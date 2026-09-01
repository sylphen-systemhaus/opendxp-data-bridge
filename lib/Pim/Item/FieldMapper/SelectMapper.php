<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;
use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Exception;
use OutOfBoundsException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Fieldcollection\Definition;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Element\ValidationException;

class SelectMapper extends AbstractFieldMapper
{
    public const VIRTUAL_WORKFLOW_FIELD = 'DD.WorkflowTransition';

    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return ($fieldDefinition instanceof Data\Select || $fieldDefinition->getFieldtype() === 'select') && !($fieldDefinition instanceof Data\User);
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        /** @var $fieldDefinition Data\Select */
        try {
            if (!empty($mapping['format']['infer'])) {
                try {
                    $value = $this->infer($value, $fieldDefinition, $dataObject, $mapping, $mapping['format']);
                } catch (OutOfBoundsException $e) {
                    $this->log($dataObject, $e->getMessage(), 'info');
                    $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
                } catch (\Throwable $e) {
                    $this->log($dataObject, 'Could not infer value. '.$e->getMessage(), 'alert');

                    $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
                }
            }

            if (is_array($value) && count($value) === 1) {
                $value = reset($value);
            }

            if ($value !== null && $value !== '') {
                $valueFound = false;
                foreach ((array)$fieldDefinition->getOptions() as $option) {
                    if ($option['value'] == $value) {
                        $valueFound = true;
                        break;
                    }
                }

                if (!$valueFound && $fieldDefinition->getOptionsProviderClass()) {
                    try {
                        $fieldDefinition->enrichFieldDefinition(['object' => $dataObject]);
                    } catch (\Throwable $e) {
                        $this->log($dataObject, 'Could not enrich field "'.$fieldDefinition->getName().'". '.$e->getMessage(), 'warning');
                    }
                    foreach ($fieldDefinition->getOptions() as $option) {
                        if ($option['value'] == $value) {
                            $valueFound = true;
                            break;
                        }
                    }
                }

                if (!$valueFound) {
                    foreach ((array)$fieldDefinition->getOptions() as $option) {
                        if ($option['key'] == $value) {
                            $value = $option['value'];
                            $valueFound = true;
                            break;
                        }
                    }
                }

                if (!$valueFound) {
                    foreach ((array)$fieldDefinition->getOptions() as $option) {
                        if (strtolower($option['value']) === strtolower($value)) {
                            $value = $option['value'];
                            $valueFound = true;
                            break;
                        }
                    }
                }

                if (!$valueFound) {
                    foreach ((array)$fieldDefinition->getOptions() as $option) {
                        if (strtolower($option['key']) === strtolower($value)) {
                            $value = $option['value'];
                            $valueFound = true;
                            break;
                        }
                    }
                }

                if (!$valueFound && !empty($mapping['format']['autoCreate'])) {
                    $valueFound = true;

                    try {
                        $class = null;
                        $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
                        if ($updatableObject instanceof Concrete) {
                            $updatableObject->setClass(null); // force reloading of class definition
                            RuntimeCache::set('class_'.$updatableObject->getClassId(), $class);
                            $class = $updatableObject->getClass();
                        } elseif ($updatableObject instanceof AbstractData) {
                            $class = $updatableObject->getDefinition();
                            RuntimeCache::set('brick_'.$updatableObject->getType(), $class);
                        } elseif ($updatableObject instanceof \OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData) {
                            $class = $updatableObject->getDefinition();
                            RuntimeCache::set('fieldcollection_'.$updatableObject->getType(), $class);
                        } elseif ($updatableObject instanceof Classificationstore) {
                            $class = Classificationstore\KeyConfig::getByName($mapping['fieldName'], $updatableObject->getClass()->getFieldDefinition($mapping['targetBrickField'])->getStoreId());
                            $class->setDefinition(json_encode($fieldDefinition));
                        }

                        $fieldDefinition = $class->getFieldDefinition($mapping['fieldName']);
                        if($fieldDefinition instanceof Data\Select) {
                            /** @var Data\Select $fieldDefinition */
                            $options = $fieldDefinition->getOptions();
                            $options[] = ['key' => $value, 'value' => $value];
                            $fieldDefinition->setOptions($options);
                        }

                        if ($class instanceof ClassDefinition || $class instanceof Definition || $class instanceof Classificationstore\KeyConfig) {
                            $class->save();
                        }
                    } catch (Exception $e) {
                        throw new ValidationException('There is no option "'.$value.'" and it could not be automatically created: '.$e->getMessage());
                    }
                }

                if (!$valueFound && $fieldDefinition->getDefaultValue()) {
                    $this->log($dataObject, 'Value "'.$value.'" not found. Using default value "'.$fieldDefinition->getDefaultValue().'"', 'info');
                    $value = $fieldDefinition->getDefaultValue();
                }

                if (!$valueFound) {
                    throw new ValidationException('There is no option "'.$value.'"');
                }
            }

            $fieldDefinition->checkValidity($value);
        } catch (ValidationException $e) {
            $this->log($dataObject, 'Skipped '.Importer::getLogOutput($value).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
            $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
        }

        return $value;
    }
}