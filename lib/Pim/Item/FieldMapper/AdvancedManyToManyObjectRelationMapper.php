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
use OutOfBoundsException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\AbstractObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\ObjectMetadata;
use OpenDxp\Model\Element\ValidationException;
use Traversable;

class AdvancedManyToManyObjectRelationMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation || $fieldDefinition->getFieldtype() === 'advancedManyToManyObjectRelation' || $fieldDefinition->getFieldtype() === 'objectsMetadata';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        /** @var Data\AdvancedManyToManyObjectRelation $fieldDefinition */

        if ((!is_array($value) && !$value instanceof Traversable) || isset($value['query'])) {
            $value = [$value];
        }

        AbstractObject::setDisableDirtyDetection(true); // see https://github.com/pimcore/pimcore/issues/3951

        $objects = [];
        if (empty($mapping['format']['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $objects = (array)(is_callable($currentValue) ? $currentValue() : $currentValue);
        } else {
            $this->setPurged($mapping, $dataObject);
        }

        foreach ($value as $objectData) {
            if (is_array($objectData) && isset($objectData['object']['query'], $objectData['meta'])) {
                $objectData = array_merge([
                    'query' => $objectData['object']['query'],
                ], $objectData['meta'] ?? []);
            }
            $metaData = [];

            if($objectData instanceof ObjectMetadata && $objectData->getObject() instanceof Concrete) {
                $objectData = array_merge([
                    'query' => 'Concrete:id:'.$objectData->getObject()->getId()
                ], $objectData->getData());
            } elseif (!is_array($objectData)) {
                $objectData = [
                    'query' => $objectData,
                ];
            } elseif (!empty($objectData['id'])) {
                $objectData['query'] = 'Concrete:id:'.$objectData['id'];
            } elseif (!empty($objectData['fullpath'])) {
                $objectData['query'] = 'Concrete:path:'.$objectData['fullpath'];
            }

            foreach ($objectData as $key => $metaValue) {
                if ($key === 'query') {
                    continue;
                }

                if (\is_scalar($metaValue) || $metaValue === null) {
                    $metaData[$key] = $metaValue;
                }
            }
            if (!\is_string($objectData['query']) && !$objectData['query'] instanceof Stringable) {
                continue;
            }
            $objectData['query'] = (string)$objectData['query'];

            $objectToUpdateId = null;
            if (!method_exists($fieldDefinition, 'getAllowMultipleAssignments') || !$fieldDefinition->getAllowMultipleAssignments()) {
                $uniqueObjects = [];
                foreach($objects as $object) {
                    try {
                        $uniqueObjects[$object->getObject()->getId()] = $object;
                    } catch(\Throwable $e) {
                        $this->log($dataObject, 'Cleaned up invalid data in field "'.Helper::getFieldKey($mapping).'"', 'info');
                    }
                }
                $objects = array_values($uniqueObjects);

                $objectToUpdateId = $this->importer->findInRelation($objects, $objectData['query']);
            }

            $metaObjects = [];
            if ($objectToUpdateId !== null) {
                $metaObjects[] = $objects[$objectToUpdateId];
            } else {
                $targetObjects = $this->importer->getObjectByIdentifier($objectData['query']);
                if ($targetObjects === null) {
                    $this->log($dataObject, 'Could not find any object which matches '.json_encode($objectData['query'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'notice');
                    continue;
                }

                if (!is_array($targetObjects)) {
                    $targetObjects = [$targetObjects];
                }

                foreach ($targetObjects as $targetObject) {
                    if (!$targetObject instanceof Concrete) {
                        $this->log(
                            $dataObject,
                            'Skipped '.json_encode($objectData['query'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).' because at least one of the returned elements is not a data object ('.gettype($targetObject).(is_object($targetObject) ? ' of class "'.$targetObject.'"' : '').' returned)',
                            'warning'
                        );
                        continue 2;
                    }
                    $metaObject = new ObjectMetadata($fieldDefinition->getName(), $fieldDefinition->getColumnKeys(), $targetObject);
                    foreach ($fieldDefinition->getColumnKeys() as $column) {
                        $metaSetter = 'set'.ucfirst($column);
                        $metaObject->$metaSetter(null);
                    }

                    try {
                        $fieldDefinition->checkValidity(array_merge($objects, [$metaObject]), true);
                        $metaObjects[] = $metaObject;
                        $objects[] = $metaObject;
                    } catch (ValidationException $e) {
                        $this->log($dataObject, 'Skipped '.Importer::getLogOutput($objectData).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
                        continue;
                    }
                }
            }

            $metaFields = $fieldDefinition->getColumns();
            $inferFields = array_filter(explode(',', $mapping['format']['infer'] ?? ''));
            foreach ($inferFields as $inferField) {
                if (!isset($metaData[$inferField])) {
                    $metaData[$inferField] = null;
                }
            }
            $visibleFields = $fieldDefinition->getVisibleFields() ? explode(',', $fieldDefinition->getVisibleFields()) : [];
            foreach ($metaObjects as $metaObject) {
                foreach ($metaData as $key => $metaValue) {
                    foreach ($metaFields as $metaField) {
                        if (strtolower($metaField['key']) === strtolower($key)) {
                            if (in_arrayi($key, $inferFields)) {
                                try {
                                    $metaFieldDefinition = null;
                                    foreach ($fieldDefinition->getColumns() as $fieldDefinitionColumn) {
                                        if (strtolower($fieldDefinitionColumn['key']) === strtolower($key)) {
                                            if ($fieldDefinitionColumn['type'] === 'text') {
                                                $metaFieldDefinition = new Input();
                                                $metaFieldDefinition->setName($key);
                                                $metaFieldDefinition->setTitle($fieldDefinitionColumn['label'] ?? $fieldDefinitionColumn['key']);
                                            } elseif (in_array($fieldDefinitionColumn['type'], ['select', 'multiselect'], true)) {
                                                $metaFieldDefinition = ($fieldDefinitionColumn['type'] === 'select' ? new Data\Select() : new Data\Multiselect());
                                                $metaFieldDefinition->setName($key);
                                                $metaFieldDefinition->setTitle($fieldDefinitionColumn['label'] ?? $fieldDefinitionColumn['key']);
                                                $metaFieldDefinition->setOptions(
                                                    array_map(
                                                        static function ($metaFieldOption) {
                                                            return ['key' => $metaFieldOption, 'value' => $metaFieldOption];
                                                        },
                                                        explode(';', $fieldDefinitionColumn['value'])
                                                    )
                                                );
                                            }
                                            break;
                                        }
                                    }

                                    if ($metaFieldDefinition instanceof Data) {
                                        $metaFieldDefinitionTitleContext = [];
                                        foreach ((array)$visibleFields as $visibleField) {
                                            $visibleFieldValue = Importer::getValue($metaObject->getObject(), $visibleField);
                                            if ($visibleFieldValue && is_string($visibleFieldValue)) {
                                                $metaFieldDefinitionTitleContext[$visibleField] = $visibleFieldValue;
                                            }
                                        }
                                        $title = method_exists($metaObject->getObject(), 'getName') ? $metaObject->getObject()->getName() : $metaObject->getObject()->getKey();
                                        if ($metaFieldDefinitionTitleContext) {
                                            $title .= ' (context: '.urldecode(http_build_query($metaFieldDefinitionTitleContext, '', ', ')).')';
                                        }
                                        $metaFieldDefinition->setTitle($metaFieldDefinition->getTitle().' for '.$title);
                                        $metaValue = $this->infer($metaValue, $metaFieldDefinition, $dataObject, $mapping, $mapping['format']);
                                    }
                                } catch (OutOfBoundsException $e) {
                                    $this->log($dataObject, $e->getMessage(), 'info');
                                    $metaValue = null;
                                } catch (\Throwable $e) {
                                    $this->log($dataObject, 'Could not infer value. '.$e->getMessage(), 'alert');

                                    $metaValue = null;
                                }
                            }

                            if ($metaField['type'] === 'number') {
                                $metaValue = $this->parseNumber($metaValue);
                            } elseif ($metaField['type'] === 'bool') {
                                $metaValue = (bool)$metaValue;
                            }

                            $metaObject->setData(array_merge($metaObject->getData(), [$metaField['key'] => $metaValue]));
                            break;
                        }
                    }
                }
            }

            if (method_exists($fieldDefinition, 'getAllowMultipleAssignments') && $fieldDefinition->getAllowMultipleAssignments()) {
                $uniqueObjects = [];
                foreach ($objects as $index => $object) {
                    if(!empty($uniqueObjects[$object->getObject()->getId()]) && $uniqueObjects[$object->getObject()->getId()] == $object->getData()) {
                        $this->log($dataObject, 'Cleaned up duplicate assignment for #'.$object->getObject()->getId().' '.$object->getObject()->getRealFullPath().' ('.json_encode($object->getData()).') in field "'.Helper::getFieldKey($mapping).'"', 'info');
                        unset($objects[$index]);
                    }

                    $uniqueObjects[$object->getObject()->getId()] = $object->getData();
                }
            }
        }

        return $objects;
    }
}