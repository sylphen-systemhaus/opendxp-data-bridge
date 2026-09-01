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
use OutOfBoundsException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Classificationstore\KeyConfig;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Fieldcollection\Definition;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\Element\ValidationException;

class MultiselectMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Multiselect || $fieldDefinition->getFieldtype() === 'multiselect';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $selectedOptions = [];
        if (empty($mapping['format']['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $selectedOptions = (array)(is_callable($currentValue) ? $currentValue() : $currentValue);
        } else {
            $this->setPurged($mapping, $dataObject);
        }

        if ($value === null) {
            $value = [];
        } elseif (!empty($mapping['format']['infer'])) {
            try {
                $value = $this->infer($value, $fieldDefinition, $dataObject, $mapping, $mapping['format']);
            } catch (OutOfBoundsException $e) {
                $this->log($dataObject, $e->getMessage(), 'info');
                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            } catch (\Throwable $e) {
                $this->log($dataObject, 'Could not infer value. '.$e->getMessage(), 'alert');

                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            }
            $value = (array)$value;
            $newOptions = $value;
            $value = array_merge($selectedOptions, $value);
        } elseif (is_string($value) || $value instanceof Stringable) {
            $separator = $mapping['format']['separator'] ?? null;

            if ($separator) {
                $newOptions = explode($separator, (string)$value);
                $selectedOptions = array_merge($selectedOptions, $newOptions);
            } else {
                $newOptions = [(string)$value];
                $selectedOptions[] = (string)$value;
            }
            $value = $selectedOptions;
        } elseif (is_array($value)) {
            $newOptions = $value;
            $value = array_merge($selectedOptions, $value);
        }

        foreach ($value as &$selectedOption) {
            try {
                foreach ($fieldDefinition->getOptions() as $option) {
                    if ($option['value'] == $selectedOption) {
                        continue 2;
                    }
                }

                if ($fieldDefinition->getOptionsProviderClass()) {
                    try {
                        $fieldDefinition->enrichFieldDefinition(['object' => $dataObject]);
                    } catch (\Throwable $e) {
                        $this->log($dataObject, 'Could not enrich field "'.$fieldDefinition->getName().'". '.$e->getMessage(), 'warning');
                    }
                    foreach ($fieldDefinition->getOptions() as $option) {
                        if ($option['value'] == $selectedOption) {
                            continue 2;
                        }
                    }
                }

                foreach ($fieldDefinition->getOptions() as $option) {
                    if ($option['key'] == $selectedOption) {
                        $selectedOption = $option['value'];
                        continue 2;
                    }
                }

                $trimmedValue = trim($selectedOption);
                if ($trimmedValue !== $selectedOption) {
                    foreach ($fieldDefinition->getOptions() as $option) {
                        if (trim($option['value']) == $trimmedValue) {
                            continue 2;
                        }
                    }

                    foreach ($fieldDefinition->getOptions() as $option) {
                        if (trim($option['key']) == $selectedOption) {
                            $selectedOption = $option['value'];
                            continue 2;
                        }
                    }
                }

                if (!empty($mapping['format']['autoCreate'])) {
                    /** @var Data\Multiselect $fieldDefinition */
                    $options = $fieldDefinition->getOptions();
                    $options[] = ['key' => $selectedOption, 'value' => str_replace(',', ' ', $selectedOption)]; // commas not allowed in option value, see https://github.com/pimcore/pimcore/issues/5010
                    $fieldDefinition->setOptions($options);

                    try {
                        $class = null;
                        $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
                        if ($updatableObject instanceof Concrete) {
                            $class = $updatableObject->getClass();
                        } elseif ($updatableObject instanceof AbstractData || $updatableObject instanceof \OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData) {
                            $class = $updatableObject->getDefinition();
                        } elseif ($updatableObject instanceof Classificationstore) {
                            $class = KeyConfig::getByName($mapping['fieldName'], $updatableObject->getClass()->getFieldDefinition($mapping['targetBrickField'])->getStoreId());
                            $class->setDefinition(json_encode($fieldDefinition));
                        }

                        if ($class instanceof ClassDefinition || $class instanceof Definition || $class instanceof KeyConfig) {
                            $class->save();
                        }
                    } catch (Exception $e) {
                        throw new ValidationException('There is no option "'.$selectedOption.'" and it could not be automatically created: '.$e->getMessage());
                    }

                    continue;
                }

                if (in_array($selectedOption, $newOptions)) {
                    throw new ValidationException('There is no option "'.$selectedOption.'"');
                }

                $this->importer->getLogger()->info('Cleaned up option "'.$selectedOption.'" as it is not available anymore');
                unset($selectedOption);
            } catch (ValidationException $e) {
                $this->log($dataObject, 'Skipped '.Importer::getLogOutput($selectedOption).' for field '.Helper::getFieldKey($mapping).' because: '.$e->getMessage(), 'warning');
            }
        }
        unset($selectedOption);

        return array_values(array_unique($value));
    }
}