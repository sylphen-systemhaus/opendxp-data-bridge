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
use InvalidArgumentException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ElementMetadata;
use OpenDxp\Model\Document\PageSnippet;
use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\ValidationException;
use Traversable;

class AdvancedManyToManyRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\AdvancedManyToManyRelation ||
            $fieldDefinition->getFieldtype() === 'advancedManyToManyRelation' ||
            $fieldDefinition->getFieldtype() === 'multihrefMetadata';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (empty($value)) {
            $value = [];
        }

        if ((!is_array($value) && !$value instanceof Traversable) || (count($value) > 0 && !isset($value[0]) && !isset(reset($value)['url']) && !isset(reset($value)['query'])) || isset($value['url']) || isset($value['query'])) {
            $value = [$value];
        }

        // Bestehende Einträge nicht übernehmen, wenn purgeItems gesetzt ist
        if (empty($mapping['format']['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $currentValue = (is_callable($currentValue) ? $currentValue() : $currentValue);
            if (!is_array($currentValue)) {
                $currentValue = array();
            }
        } else {
            $currentValue = [];
            $this->setPurged($mapping, $dataObject);
        }

        foreach ($value as $assetSource) {
            // assetSource array durchlaufen und alle Einträge außer url, query, filename als Metadaten setzen
            $metaData = array();
            if (\is_array($assetSource)) {
                if (isset($assetSource['element']['query'], $assetSource['meta'])) {
                    $assetSource = array_merge([
                        'query' => $assetSource['element']['query'],
                    ], $assetSource['meta'] ?? []);
                }

                foreach ($assetSource as $key => $metaDataItem) {
                    if (\in_array($key, ['url', 'query', 'filename', 'fullpath']) || is_numeric($key)) {
                        continue;
                    }

                    if (\is_scalar($metaDataItem)) {
                        $metaData[strtolower($key)] = $metaDataItem;
                    }
                }
            }

            /** @var Data\AdvancedManyToManyRelation $fieldDefinition */
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
                        $currentAsset = $currentAsset->getElement();
                        if ($currentAsset instanceof Asset && $assetRecognitionFunction($currentAsset)) {
                            $assetToUpdateId = $key;
                            if(empty($mapping['format']['overwrite'])) {
                                $this->importer->getLogger()->info('Asset "'.$newAsset->getFullpath().'" is a duplicate of the already assigned "'.$currentAsset->getFullpath().'" -> using existing one');
                            }

                            break 2;
                        }
                    }
                }

                if ($assetToUpdateId !== null) {
                    if (!empty($mapping['format']['overwrite'])) {
                        $currentValue[$assetToUpdateId]->setElement($newAsset);
                    }

                    $metaElement = $currentValue[$assetToUpdateId];
                } else {
                    $metaElement = new ElementMetadata($fieldDefinition->getName(), $fieldDefinition->getColumnKeys(), $newAsset);

                    try {
                        $fieldDefinition->checkValidity(array_merge($currentValue, [$metaElement]), true);
                        $currentValue[] = $metaElement;
                    } catch (ValidationException $e) {
                        unset($currentValue[$assetToUpdateId]);
                        $currentValue = array_values($currentValue);

                        $this->log($dataObject, 'Skipped '.Importer::getLogOutput($assetSource).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
                        continue;
                    }

                    foreach ($fieldDefinition->getColumnKeys() as $column) {
                        $metaSetter = 'set'.ucfirst($column);
                        $metaElement->$metaSetter(null);
                    }
                }

                $metaFields = $fieldDefinition->getColumns();
                foreach ($metaData as $key => $metaDataItem) {
                    foreach ($metaFields as $metaField) {
                        if (strtolower($metaField['key']) === strtolower($key)) {
                            if ($metaField['type'] === 'number') {
                                $metaDataItem = $this->parseNumber($metaDataItem);
                            } elseif ($metaField['type'] === 'bool') {
                                $metaDataItem = (bool)$metaDataItem;
                            }
                            break;
                        }
                    }

                    $metaSetter = 'set'.ucfirst($key);
                    try {
                        $metaElement->$metaSetter($metaDataItem);
                    } catch (\Exception $e) {
                        $this->log($dataObject, $e->getMessage(), 'warning');
                    }
                }
            } elseif ($fieldDefinition->getObjectsAllowed() || $fieldDefinition->getDocumentsAllowed()) {
                if ($assetSource instanceof ElementInterface) {
                    $assetSource = [
                        'query' => get_class($assetSource).':id:'.$assetSource->getId()
                    ];
                } elseif (!isset($assetSource['query']) && (is_string($assetSource) || $assetSource instanceof Stringable)) {
                    $assetSource = [
                        'query' => $assetSource
                    ];
                }

                $objectToUpdateId = null;
                $metaElement = null;
                if (!method_exists($fieldDefinition, 'getAllowMultipleAssignments') || !$fieldDefinition->getAllowMultipleAssignments()) {
                    $uniqueObjects = [];
                    foreach ($currentValue as $object) {
                        try {
                            $uniqueObjects[$object->getElement()->getId()] = $object;
                        } catch (\Throwable $e) {
                            $this->log($dataObject, 'Cleaned up invalid data in field "'.Helper::getFieldKey($mapping).'"', 'info');
                        }
                    }
                    $currentValue = array_values($uniqueObjects);

                    $objectToUpdateId = $this->importer->findInRelation($currentValue, $assetSource['query']);
                }

                if ($objectToUpdateId !== null) {
                    $metaElement = $currentValue[$objectToUpdateId];
                } else {
                    $targetObjects = $this->importer->getObjectByIdentifier($assetSource['query']);

                    if ($targetObjects === null) {
                        $this->log($dataObject, 'Could not find any object which matches '.json_encode($assetSource['query'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'notice');
                        continue;
                    }

                    if (!is_array($targetObjects)) {
                        $targetObjects = [$targetObjects];
                    }

                    foreach ($targetObjects as $targetObject) {
                        if (!$targetObject instanceof Concrete && !$targetObject instanceof PageSnippet) {
                            continue;
                        }

                        $metaElement = new ElementMetadata($fieldDefinition->getName(), $fieldDefinition->getColumnKeys(), $targetObject);

                        foreach ($fieldDefinition->getColumnKeys() as $column) {
                            $metaSetter = 'set'.ucfirst($column);
                            $metaElement->$metaSetter(null);
                        }

                        try {
                            $fieldDefinition->checkValidity(array_merge($currentValue, [$metaElement]), true);
                            $currentValue[] = $metaElement;
                        } catch (ValidationException $e) {
                            $this->log($dataObject, 'Skipped '.Importer::getLogOutput($assetSource).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
                            continue;
                        }
                    }
                }

                $metaFields = $fieldDefinition->getColumns();
                foreach ($metaData as $key => $metaDataItem) {
                    foreach ($metaFields as $metaField) {
                        if (strtolower($metaField['key']) === strtolower($key)) {
                            if ($metaField['type'] === 'number') {
                                $metaDataItem = $this->parseNumber($metaDataItem);
                            } elseif ($metaField['type'] === 'bool') {
                                $metaDataItem = (bool)$metaDataItem;
                            }

                            $metaElement->setData(array_merge($metaElement->getData(), [$metaField['key'] => $metaDataItem]));
                            break;
                        }
                    }
                }
            }
        }

        if (method_exists($fieldDefinition, 'getAllowMultipleAssignments') && $fieldDefinition->getAllowMultipleAssignments()) {
            $uniqueObjects = [];
            foreach ($currentValue as $index => $object) {
                if (!$object instanceof ElementMetadata || !$object->getElement() instanceof ElementInterface) {
                    $this->log($dataObject, 'Cleaned up relation for non-existing element', 'info');
                    unset($currentValue[$index]);
                    continue;
                }

                if (!empty($uniqueObjects[$object->getElement()->getId()]) && $uniqueObjects[$object->getElement()->getId()] == $object->getData()) {
                    $this->log($dataObject, 'Cleaned up duplicate assignment for #'.$object->getElement()->getId().' '.$object->getElement()->getRealFullPath().' ('.json_encode($object->getData()).') in field "'.Helper::getFieldKey($mapping).'"', 'info');
                    unset($currentValue[$index]);
                }

                $uniqueObjects[$object->getElement()->getId()] = $object->getData();
            }
        } else {
            foreach ($currentValue as $index => $object) {
                if (!$object instanceof ElementMetadata || !$object->getElement() instanceof ElementInterface) {
                    $this->log($dataObject, 'Cleaned up relation for non-existing element', 'info');
                    unset($currentValue[$index]);
                }
            }
        }

        $value = array_values($currentValue);

        return $value;
    }
}