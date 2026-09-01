<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Stringable;
use Exception;
use InvalidArgumentException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementDescriptor;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Service;
use OpenDxp\Model\Element\ValidationException;
use OpenDxp\Tool;
use Traversable;

class ManyToManyRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return ($fieldDefinition instanceof Data\ManyToManyRelation ||
            $fieldDefinition->getFieldtype() === 'manyToManyRelation' ||
            $fieldDefinition->getFieldtype() === 'multihref') && !(
                $fieldDefinition instanceof Data\AdvancedManyToManyRelation ||
                $fieldDefinition->getFieldtype() === 'advancedManyToManyRelation' ||
                $fieldDefinition->getFieldtype() === 'multihrefMetadata'
            );
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (empty($value)) {
            $value = [];
        }

        /** @var Data\ManyToManyRelation $fieldDefinition */
        if ((!is_array($value) && !$value instanceof Traversable) || (count($value) > 0 && !isset($value[0])) || isset($value['url']) || isset($value['query'])) {
            $value = [$value];
        }

        if ((empty($mapping['format']['purgeitems']) && $fieldDefinition->getMaxItems() != 1) || $this->isPurged($mapping, $dataObject)) {
            $currentValue = (is_callable($currentValue) ? $currentValue() : $currentValue);
            if (!is_array($currentValue)) {
                $currentValue = array();
            }
        } else {
            $currentValue = [];
            $this->setPurged($mapping, $dataObject);
        }

        foreach ($value as $assetSource) {
            $newAsset = null;
            if ($fieldDefinition->getAssetsAllowed()) {
                $newAsset = $this->getAsset($assetSource, ['overwrite' => !empty($mapping['format']['overwrite']), 'preventDuplicates' => !empty($mapping['format']['preventDuplicates'])]);

                if (!$newAsset instanceof Asset) {
                    continue;
                }

                $assetToUpdateId = null;
                $assetSourceURI = null;
                $newAssetChecksum = null;

                $assetRecognitionFunctions = [
                    static function ($existingAsset) use ($newAsset) {
                        return $newAsset->getId() === $existingAsset->getId();
                    },
                    static function ($existingAsset) use (&$assetSourceURI, $newAsset) {
                        if (!$assetSourceURI) {
                            $assetSourceURI = $newAsset->getProperty('sourcePath');
                        }
                        return $assetSourceURI && $existingAsset->getProperty('sourcePath') === $assetSourceURI;
                    },
                    static function ($existingAsset) use (&$newAssetChecksum, $newAsset) {
                        try {
                            if (!$newAssetChecksum) {
                                $newAssetChecksum = Importer::getAssetChecksum($newAsset);
                            }
                            return $newAssetChecksum && Importer::getAssetChecksum($existingAsset) === $newAssetChecksum;
                        } catch (InvalidArgumentException $e) {
                            return false;
                        }
                    },
                ];

                foreach ($assetRecognitionFunctions as $assetRecognitionFunction) {
                    /** @var Asset $currentAsset */
                    foreach ($currentValue as $key => $currentAsset) {
                        if ($assetRecognitionFunction($currentAsset)) {
                            $assetToUpdateId = $key;
                            $this->importer->getLogger()->info('Asset "'.$newAsset->getFullpath().'" seems to be a duplicate of the already assigned "'.$currentAsset->getFullpath().'" -> update existing one');
                            break 2;
                        }
                    }
                }

                // assetSource array durchlaufen und alle Einträge außer 'url' als Metadaten setzten
                if (is_array($assetSource) && empty($assetSource['id']) && (empty($assetSource['path']) || empty($assetSource['fullpath']))) {
                    $assetNeedsSaving = false;
                    $addedMetaData = [];
                    foreach ($assetSource as $key => $metaValue) {
                        if (\in_array($key, ['url', 'query', 'filename', 'fullpath']) || is_numeric($key)) {
                            continue;
                        }
                        if (is_array($metaValue)) {
                            foreach ($metaValue as $language => $localizedValue) {
                                if (!Tool::isValidLanguage($language)) {
                                    $this->log($newAsset, 'It is not possible to set asset metadata field "'.$key.'" for language "'.$language.'" as the language does not exist. Please check System settings > Localization & Internationalization', 'error');
                                }

                                try {
                                    $metaObject = $this->importer->getObjectByIdentifier($localizedValue);
                                    if ($metaObject !== $localizedValue) {
                                        $localizedValue = $metaObject;
                                    }
                                } catch (Exception $e) {
                                }

                                if ($newAsset->getMetadata($key, $language) != $localizedValue) {
                                    $metadataType = ($localizedValue instanceof ElementInterface ? Service::getElementType($localizedValue) : 'input');
                                    $newAsset->addMetadata($key, $metadataType, $localizedValue, $language);
                                    $addedMetaData[] = [
                                        'name' => $key,
                                        'type' => $metadataType,
                                        'data' => $localizedValue,
                                        'language' => $language
                                    ];
                                    $assetNeedsSaving = true;
                                }
                            }
                        } else {
                            try {
                                $metaObject = $this->importer->getObjectByIdentifier($metaValue);
                                if ($metaObject !== $metaValue) {
                                    $metaValue = $metaObject;
                                }
                            } catch (Exception $e) {
                            }

                            if ($newAsset->getMetadata($key) != $metaValue) {
                                $metadataType = ($metaValue instanceof ElementInterface ? Service::getElementType($value) : 'input');
                                $newAsset->addMetadata($key, $metadataType, $metaValue);
                                $addedMetaData[] = [
                                    'name' => $key,
                                    'type' => $metadataType,
                                    'data' => $metaValue
                                ];
                                $assetNeedsSaving = true;
                            }
                        }
                    }

                    if ($assetNeedsSaving) {
                        $newAsset->save(['versionNote' => 'Dataport: '.$this->importer->getDataport()['id']]);
                        $this->log($newAsset, 'Metadata of asset #'.$newAsset->getId().' '.$newAsset->getRealFullPath().' updated: '.json_encode($addedMetaData, JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'info');
                    }
                }

                try {
                    if ($assetToUpdateId !== null) {
                        $fieldDefinition->checkValidity(array_replace($currentValue, [$assetToUpdateId => $newAsset]), true);
                        $currentValue[$assetToUpdateId] = $newAsset;
                    } else {
                        $fieldDefinition->checkValidity(array_merge($currentValue, [$newAsset]), true);
                        $currentValue[] = $newAsset;
                    }
                } catch (Exception $e) {
                    if ($assetToUpdateId !== null) {
                        unset($currentValue[$assetToUpdateId]);
                        $currentValue = array_values($currentValue);
                    }
                    $this->log($dataObject, 'Skipped '.Importer::getLogOutput($assetSource).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');

                    continue;
                }
            } elseif ($fieldDefinition->getObjectsAllowed() || $fieldDefinition->getDocumentsAllowed()) {
                if (\is_string($assetSource) || $assetSource instanceof Stringable) {
                    $assetSource = (string)$assetSource;
                    if (!\preg_match('/^[A-Za-z0-9\\\.]+:[^:]+(::?[^:]*)*$/', $assetSource)) {
                        if (count($fieldDefinition->getClasses()) === 1) {
                            $relationClass = $fieldDefinition->getClasses()[0]['classes'];

                            if (is_numeric($assetSource)) {
                                $relationField = 'id';
                            } else {
                                $relationField = 'path';
                            }

                            $relationClassDefinition = Helper::getClassDefinitionByName($relationClass);
                            if ($relationClassDefinition instanceof ClassDefinition) {
                                foreach ($relationClassDefinition->getFieldDefinitions() as $relationFieldDefinition) {
                                    if ($relationFieldDefinition->getUnique()) {
                                        $relationField = $relationFieldDefinition->getName();
                                        break;
                                    }
                                }
                            }

                            $assetSource = $relationClass.':'.$relationField.':'.$assetSource;
                        }
                    }

                    $assetSource = ['query' => $assetSource];
                } elseif (is_array($assetSource) && isset($assetSource['fullpath'], $assetSource['type'])) {
                    if ($assetSource['type'] === 'object') {
                        $assetSource = [
                            'query' => 'Concrete:path:'.$assetSource['fullpath'],
                        ];
                    } elseif ($assetSource['type'] === 'asset') {
                        $assetSource = [
                            'query' => 'Asset:path:'.$assetSource['fullpath'],
                        ];
                    } elseif ($assetSource['type'] === 'document') {
                        $assetSource = [
                            'query' => 'Document:path:'.$assetSource['fullpath'],
                        ];
                    }
                } elseif ($assetSource instanceof ElementDescriptor) {
                    $class = 'Concrete';
                    if ($assetSource->getType() === 'asset') {
                        $class = 'Asset';
                    } elseif ($assetSource->getType() === 'document') {
                        $class = 'Document';
                    }
                    $assetSource = [
                        'query' => $class.':id:'.$assetSource->getId()
                    ];
                } elseif ($assetSource instanceof ElementInterface) {
                    $assetSource = [
                        'query' => get_class($assetSource).':id:'.$assetSource->getId()
                    ];
                }

                $objectToUpdateId = $this->importer->findInRelation($currentValue, $assetSource['query']);
                if ($objectToUpdateId === null) {
                    $targetObjects = $this->importer->getObjectByIdentifier($assetSource['query']);

                    if ($targetObjects === null) {
                        $this->log($dataObject, 'Could not find any object which matches '.json_encode($assetSource['query'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'notice');
                        continue;
                    }

                    if (!is_array($targetObjects)) {
                        $targetObjects = [$targetObjects];
                    }

                    foreach ($targetObjects as $targetObject) {
                        if ($targetObject instanceof Concrete || $targetObject instanceof PageSnippet) {
                            try {
                                $fieldDefinition->checkValidity(array_merge($currentValue, [$targetObject]), true);
                                $currentValue[] = $targetObject;
                            } catch (ValidationException $e) {
                                $this->log($dataObject, 'Skipped '.Importer::getLogOutput($assetSource).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
                                continue;
                            }
                        }
                    }
                }
            }
        }

        return $currentValue;
    }
}