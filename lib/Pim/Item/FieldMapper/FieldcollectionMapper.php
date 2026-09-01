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
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Fieldcollection;
use OpenDxp\Tool;
use Throwable;

class FieldcollectionMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Fieldcollections || $fieldDefinition->getFieldtype() === 'fieldcollections';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        /** @var Fieldcollection $currentValue */
        /** @var Data\Fieldcollections $fieldDefinition */

        $format = $mapping['format'];

        $fieldCollection = null;
        $oldFieldCollection = null;
        if (empty($format['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $fieldCollection = (is_callable($currentValue) ? $currentValue() : $currentValue);
        } else {
            $oldFieldCollection = Helper::cloneElement((is_callable($currentValue) ? $currentValue() : $currentValue));
            $this->setPurged($mapping, $dataObject);
        }

        if (!$fieldCollection instanceof Fieldcollection) {
            $fieldCollection = new Fieldcollection();
        }

        $allowedTypes = $fieldDefinition->getAllowedTypes();
        $newFieldCollectionItems = [];
        foreach ((array)$value as $fieldCollectionType => $itemsData) {
            if (is_string($fieldCollectionType)) {
                $allowedTypes = [$fieldCollectionType];
            }

            foreach ($allowedTypes as $fieldCollectionType) {
                $fieldCollectionClass = '\\OpenDxp\\Model\\DataObject\\Fieldcollection\\Data\\'.ucfirst($fieldCollectionType);
                if(!class_exists($fieldCollectionClass)) {
                    $fieldCollectionClassListing = new Fieldcollection\Definition\Listing();
                    foreach($fieldCollectionClassListing->load() as $fieldCollectionClassDefinition) {
                        if(strtolower($fieldCollectionClassDefinition->getName()) === strtolower($fieldCollectionType)) {
                            $fieldCollectionClass = '\\OpenDxp\\Model\\DataObject\\Fieldcollection\\Data\\'.ucfirst($fieldCollectionClassDefinition->getName());
                            break;
                        }
                    }
                }
                /** @var Fieldcollection\Data\AbstractData $fieldCollectionItem */

                if (!ctype_digit((string)key($itemsData))) {
                    $itemsData = [$itemsData];
                }

                foreach ($itemsData as $itemIndex => $itemData) {
                    $fieldCollectionItem = \OpenDxp::getContainer()->get('opendxp.model.factory')->build($fieldCollectionClass);
                    $fieldCollectionItem->setObject($dataObject);
                    $fieldCollectionItem->setFieldname($fieldDefinition->getName());
                    $fieldCollectionItemIsEmpty = true;

                    if (!is_array($itemData)) {
                        continue;
                    }

                    foreach ($itemData as $field => $fieldValues) {
                        $fieldParts = explode('#', $field);
                        $setter = 'set'.ucfirst($fieldParts[0]);

                        if (!method_exists($fieldCollectionItem, $setter)) {
                            if($fieldParts[0] !== 'comment') {
                                $this->log($dataObject, 'Field "'.$fieldParts[0].'" does not exist in field collection "'.$fieldCollectionType.'"', 'warning');
                            }

                            continue;
                        }

                        $fieldCollectionItemFieldDefinition = Importer::getFieldDefinition($fieldCollectionItem, $fieldParts[0]);

                        $getter = 'get'.ucfirst($fieldParts[0]);

                        if (!is_array($fieldValues) || (is_string($fieldValues[count($fieldValues) - 1] ?? null) && !Tool::isValidLanguage($fieldValues[count($fieldValues) - 1])) || !is_string($fieldValues[count($fieldValues) - 1] ?? null)) {
                            $fieldValues = [$fieldValues];
                        }

                        $setValue = $fieldValues[0];
                        $setterArguments = \array_slice($fieldValues, 1);
                        if (isset($fieldParts[1])) {
                            \array_unshift($setterArguments, $fieldParts[1]);
                        }

                        $mapping['format'] = $format;
                        $mapping['format']['purgeitems'] = false;
                        $fieldValue = $this->importer->map(array_merge($mapping, ['fieldName' => $fieldParts[0], 'brickName' => $fieldCollectionType]), $setValue, $fieldCollectionItem->$getter(...$setterArguments), $fieldCollectionItemFieldDefinition, $fieldCollectionItem);
                        \array_unshift($setterArguments, $fieldValue);

                        $fieldCollectionItem->$setter(...$setterArguments);

                        if (!empty($fieldValue)) {
                            $fieldCollectionItemIsEmpty = false;
                        }
                    }

                    if ($fieldCollectionItemIsEmpty) {
                        $this->importer->getLogger()->info('Skipping field collection item '.$fieldCollectionType.'['.$itemIndex.'] as all fields are empty');
                        continue;
                    }

                    $fieldCollection1 = new Fieldcollection();
                    $fieldCollection1->add($fieldCollectionItem);

                    // CHECK ALL EXISTING FIELDCOLLECTION ITEMS IF EQUAL AND ONLY IF NOT EQUAL ADD NEW FIELDCOLLECTION ITEM
                    foreach ($fieldCollection->getItems() as $index => $existingFieldCollectionItem) {
                        if (\get_class($existingFieldCollectionItem) !== \get_class($fieldCollectionItem)) {
                            continue;
                        }

                        $fieldCollection2 = new Fieldcollection();
                        $fieldCollection2->add($existingFieldCollectionItem);

                        if($this->importer->isEqual($fieldDefinition, $fieldCollection1, $fieldCollection2)) {
                            $this->importer->getLogger()->info('Not adding field collection item because item '.$index.' is equal');
                            continue 2;
                        }
                    }

                    // CHECK ALL EXISTING FIELDCOLLECTION ITEMS IF ONE ONLY DIFFERS IN EMPTY FIELDS
                    foreach ($fieldCollection->getItems() as $index => $existingFieldCollectionItem) {
                        if (\get_class($existingFieldCollectionItem) !== \get_class($fieldCollectionItem)) {
                            continue;
                        }

                        $allNonEmptyFieldsEqual = true;
                        if (empty($fieldDefinition->getMaxItems()) || $fieldDefinition->getMaxItems() > 1) {
                            foreach ($existingFieldCollectionItem->getDefinition()->getFieldDefinitions() as $fieldCollectionItemFieldDefinition) {
                                if ($fieldCollectionItemFieldDefinition instanceof Data\Localizedfields) {
                                    foreach ($fieldCollectionItemFieldDefinition->getFieldDefinitions() as $localizedFieldCollectionItemFieldDefinition) {
                                        $getter = 'get'.$localizedFieldCollectionItemFieldDefinition->getName();
                                        foreach (Tool::getValidLanguages() as $language) {
                                            if (!$this->importer->isEmpty($existingFieldCollectionItem->$getter($language), $localizedFieldCollectionItemFieldDefinition) && !$this->importer->isEqual(
                                                    $localizedFieldCollectionItemFieldDefinition,
                                                    $existingFieldCollectionItem->$getter($language),
                                                    $fieldCollectionItem->$getter($language)
                                                )) {
                                                $allNonEmptyFieldsEqual = false;
                                                break;
                                            }
                                        }
                                    }
                                } else {
                                    $getter = 'get'.$fieldCollectionItemFieldDefinition->getName();
                                    if (!$this->importer->isEmpty($existingFieldCollectionItem->$getter(), $fieldCollectionItemFieldDefinition) && !$this->importer->isEqual($fieldCollectionItemFieldDefinition, $existingFieldCollectionItem->$getter(), $fieldCollectionItem->$getter())) {
                                        $allNonEmptyFieldsEqual = false;
                                        break;
                                    }
                                }
                            }
                        }

                        if($allNonEmptyFieldsEqual) {
                            foreach ($existingFieldCollectionItem->getDefinition()->getFieldDefinitions() as $fieldCollectionItemFieldDefinition) {
                                foreach ($itemData as $field => $fieldValues) {
                                    $fieldParts = explode('#', $field);
                                    $setter = 'set'.ucfirst($fieldParts[0]);

                                    if (!method_exists($existingFieldCollectionItem, $setter)) {
                                        if ($fieldParts[0] !== 'comment') {
                                            $this->log($dataObject, 'Field "'.$fieldParts[0].'" does not exist in field collection "'.$fieldCollectionType.'"', 'warning');
                                        }

                                        continue;
                                    }

                                    $fieldCollectionItemFieldDefinition = Importer::getFieldDefinition($existingFieldCollectionItem, $fieldParts[0]);

                                    $getter = 'get'.ucfirst($fieldParts[0]);

                                    if (!is_array($fieldValues) || (is_string($fieldValues[count($fieldValues) - 1] ?? null) && !Tool::isValidLanguage($fieldValues[count($fieldValues) - 1])) || !is_string($fieldValues[count($fieldValues) - 1] ?? null)) {
                                        $fieldValues = [$fieldValues];
                                    }

                                    $setValue = $fieldValues[0];
                                    $setterArguments = \array_slice($fieldValues, 1);
                                    if (isset($fieldParts[1])) {
                                        \array_unshift($setterArguments, $fieldParts[1]);
                                    }

                                    if($fieldDefinition->getMaxItems() == 1 || $this->importer->isEmpty($existingFieldCollectionItem->$getter(...$setterArguments), $fieldCollectionItemFieldDefinition)) {
                                        $mapping['format'] = $format;
                                        $mapping['format']['purgeitems'] = false;
                                        $fieldValue = $this->importer->map(array_merge($mapping, ['fieldName' => $fieldParts[0], 'brickName' => $fieldCollectionType]),
                                            $setValue,
                                            $existingFieldCollectionItem->$getter(...$setterArguments),
                                            $fieldCollectionItemFieldDefinition,
                                            $existingFieldCollectionItem);
                                        \array_unshift($setterArguments, $fieldValue);

                                        $existingFieldCollectionItem->$setter(...$setterArguments);
                                    }
                                }
                            }

                            if ($fieldDefinition->getMaxItems() == 1) {
                                $this->log($dataObject, 'Field collection item got merged to existing one because of configured maximum number of items = 1', 'info');
                            } else {
                                $this->log($dataObject, 'Field collection item got merged to existing one because they only differed in empty fields', 'info');
                            }
                            continue 2;
                        }
                    }


                    if (isset($newFieldCollectionItems[(int)$itemIndex])) {
                        $newFieldCollectionItems[] = $fieldCollectionItem;
                    } else {
                        $newFieldCollectionItems[(int)$itemIndex] = $fieldCollectionItem;
                    }
                }
            }
        }
        ksort($newFieldCollectionItems);

        if($oldFieldCollection instanceof Fieldcollection && count($newFieldCollectionItems) === count($oldFieldCollection->getItems())) {
            $allItemsPresent = true;
            foreach ($newFieldCollectionItems as $newFieldCollectionItem) {
                $fieldCollection1 = new Fieldcollection();
                $fieldCollection1->add($newFieldCollectionItem);

                $itemPresent = false;
                foreach ($oldFieldCollection->getItems() as $oldFieldCollectionItem) {
                    $fieldCollection2 = new Fieldcollection();
                    $fieldCollection2->add($oldFieldCollectionItem);

                    if($this->importer->isEqual($fieldDefinition, $fieldCollection1, $fieldCollection2)) {
                        $itemPresent = true;
                        break;
                    }
                }

                if(!$itemPresent) {
                    $allItemsPresent = false;
                    break;
                }
            }

            if(!$allItemsPresent) {
                foreach ($newFieldCollectionItems as $fieldCollectionItemFromArray) {
                    // Do not use setItems(), as in case of !$purgeitems, items are added to a non-empty list.
                    $fieldCollectionItemFromArray->setIndex(count($fieldCollection->getItems()));
                    $fieldCollection->add($fieldCollectionItemFromArray);
                }
                $value = $fieldCollection;
            } else {
                $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
            }
        } else {
            foreach ($newFieldCollectionItems as $fieldCollectionItemFromArray) {
                // Do not use setItems(), as in case of !$purgeitems, items are added to a non-empty list.
                $fieldCollectionItemFromArray->setIndex(count($fieldCollection->getItems()));
                $fieldCollection->add($fieldCollectionItemFromArray);
            }
            $value = $fieldCollection;
        }

        return $value;
    }
}