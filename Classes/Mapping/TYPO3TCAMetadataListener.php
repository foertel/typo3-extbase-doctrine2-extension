<?php

use Doctrine\ORM\Mapping\Driver\Driver;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\Common\EventSubscriber;

/**
 * This mapping driver uses Class Docblocks and TCA Mapping Data to build the
 * ClassMetadata mapping scheme of a loaded entity class.
 *
 * @author Benjamin Eberlei <eberlei@simplethings.de>
 */
class Tx_Doctrine2_Mapping_TYPO3TCAMetadataListener implements EventSubscriber
{
    /**
     * @var Tx_Doctrine2_Mapping_MetadataService
     */
    protected $metadataService;

    /**
     * @param Tx_Doctrine2_Mapping_MetadataService $service
     * @return void
     */
    public function injectMetadataService(Tx_Doctrine2_Mapping_MetadataService $service)
    {
        $this->metadataService = $service;
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event)
    {
        if (!($this->metadataService instanceof Tx_Doctrine2_Mapping_MetadataService)) {
            throw new \RuntimeException("Cannot load Typo3 Metadata without Tx_Doctrine2_Mapping_MetadataService being set on metadata listener.");
        }

        $metadata = $event->getClassMetadata();
        $this->addTypo3Metadata($metadata);
    }

    private function loadAbstractDomainObject(ClassMetadataInfo $metadata)
    {
        $metadata->isMappedSuperclass = true;

        $metadata->mapField(array(
                    'fieldName' => 'uid',
                    'columnName' => 'uid',
                    'id' => true,
                    'type' => 'integer',
                    ));
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
    }

    private function addTypo3Metadata(ClassMetadataInfo $metadata)
    {
        $className = $metadata->name;

        if ($className == 'Tx_Doctrine2_DomainObject_AbstractDomainObject') {
            $this->loadAbstractDomainObject($metadata);
            return;
        }

        if ( ! is_subclass_of($className, 'Tx_Doctrine2_DomainObject_AbstractEntity')) {
            return;
        }

        $dataMap = $this->metadataService->getDataMap($className);
        if ( ! $dataMap) {
            return;
        }

        if ($metadata->reflClass->getShortname() == $metadata->table['name']) {
            $metadata->table['name'] = strtolower($metadata->table['name']);
        }

        $metadata->setPrimaryTable(array('name' => $dataMap->getTableName()));
        // TODO: Save EnableFields and Other metadata stuff into primary table
        // array for later reference in filters and listeners.

        if ($pidColumnName = $dataMap->getPageIdColumnName() && isset($metadata->fieldMappings['pid'])) {
            $metadata->mapField(array(
                'fieldName'     => 'pid',
                'columnName'    => $pidColumnName,
                'type'          => 'integer'
            ));
        }

        if ($lidColumnName = $dataMap->getLanguageIdColumnName() && isset($metadata->fieldMappings['languageUid'])) {
            $metadata->mapField(array(
                'fieldName'     => 'languageUid',
                'columnName'    => $lidColumnName,
                'type'          => 'integer'
            ));
        }

        $reflClass = new \ReflectionClass($metadata->name);

        // only map to properties that actually exist on the class.
        foreach ($reflClass->getProperties() as $property) {
            if ($property->isStatic() ||
                ! $dataMap->isPersistableProperty($property->getName()) ||
                isset ($metadata->fieldMappings[$property->getName()]) ||
                isset ($metadata->associationMappings[$property->getName()])) {

                continue;
            }

            $columnMap = $dataMap->getColumnMap($property->getName());

            switch ($columnMap->getTypeOfRelation()) {
                case Tx_Extbase_Persistence_Mapper_ColumnMap::RELATION_NONE:
                    $metadata->mapField(array(
                        'fieldName'     => $columnMap->getPropertyName(),
                        'columnName'    => $columnMap->getColumnName(),
                        'type'          => $this->metadataService->getTCAColumnType($dataMap->getTableName(), $columnMap->getColumnName()),
                    ));
                    break;
                case Tx_Extbase_Persistence_Mapper_ColumnMap::RELATION_HAS_ONE:
                    $metadata->mapManyToOne(array(
                        'fieldName'     => $columnMap->getPropertyName(),
                        'targetEntity'  => $this->metadataService->getTargetEntity($metadata->name, $columnMap->getPropertyName()),
                        'joinColumns'   => array(
                            array('name' => $columnMap->getColumnName(), 'referencedColumnName' => $columnMap->getParentTableFieldName(),
                        ),
                        'cascade' => array('persist')
                    )));
                    break;
            }
        }
    }

    public function getSubscribedEvents()
    {
        return array('loadClassMetadata');
    }
}

