<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Data\BlockElement;
use OpenDxp\Model\DataObject\Localizedfield;
use OpenDxp\Tool;

class BlockMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Block || $fieldDefinition->getFieldtype() === 'block';
    }

    /**
     * @param Data\Block $fieldDefinition
     */
    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $format = $mapping['format'];

        $blockCollection = null;
        if (empty($format['purgeitems']) || $this->isPurged($mapping, $dataObject)) {
            $blockCollection = (is_callable($currentValue) ? $currentValue() : $currentValue);
        } else {
            $this->setPurged($mapping, $dataObject);
        }

        if (!is_array($blockCollection)) {
            $blockCollection = [];
        }

        if ($value instanceof BlockElement || (is_array($value) && !array_key_exists(0, $value))) {
            $value = [$value];
        }

        foreach ((array)$value as $itemIndex => &$blockItem) {
            $blockItemIsEmpty = true;
            foreach ($blockItem as $field => &$fieldValue) {
                $fieldIdentifier = explode('#', $field);
                /** @var Data $blockFieldDefinition */
                $blockFieldDefinition = $fieldDefinition->getFieldDefinition($fieldIdentifier[0]);
                if (!$blockFieldDefinition instanceof Data && $fieldDefinition->getFieldDefinition('localizedfields') instanceof Data) {
                    $blockFieldDefinition = $fieldDefinition->getFieldDefinition('localizedfields')->getFieldDefinition($fieldIdentifier[0]);
                }
                if (!$blockFieldDefinition instanceof Data) {
                    $this->importer->getLogger()->notice('Skipping block item ['.$itemIndex.'] as field "'.$field.'" does not exist in block "'.$mapping['fieldName'].'"');
                    continue;
                }
                $mapping['format'] = $format;
                $mapping['format']['purgeitems'] = false;
                if (!empty($mapping['format']['autoCreate'])) {
                    $mapping['format']['autoCreateUnits'] = true;
                }

                if ($fieldValue instanceof BlockElement) {
                    $fieldValue = $fieldValue->getData();
                }

                if (!is_array($fieldValue) || !array_key_exists(0, $fieldValue)) {
                    $fieldValue = [$fieldValue];
                }

                $setValue = $fieldValue[0];

                $value = $this->importer->map(array_merge($mapping, ['fieldName' => $blockFieldDefinition->getName(), 'blockName' => $mapping['fieldName'], 'locale' => $fieldIdentifier[1] ?? null]), $setValue, null, $blockFieldDefinition, $dataObject);
                if(isset($fieldIdentifier[1]) && Tool::isValidLanguage($fieldIdentifier[1])) {
                    if(isset($blockItem['localizedfields'])) {
                        /** @var Localizedfield $localizedFieldData */
                        $localizedFieldData = $blockItem['localizedfields']->getData();
                        $localizedFieldData->setItems(array_merge($localizedFieldData->getItems(), [$fieldIdentifier[1] => [$fieldIdentifier[0] => $value]]));
                    } else {
                        $localizedFieldData = new Localizedfield([$fieldIdentifier[1] => [$fieldIdentifier[0] => $value]]);
                        $localizedFieldData->setContext(['containerType' => 'block', 'containerKey' => $mapping['fieldName']]);
                        $localizedFieldData->setObject($dataObject);
                        $blockItem['localizedfields'] = new BlockElement('localizedfields', 'localizedfields', $localizedFieldData);
                    }
                    $fieldValue = $blockItem['localizedfields'];
                    unset($blockItem[$field]);
                } else {
                    $fieldValue = new BlockElement(
                        $blockFieldDefinition->getName(),
                        $blockFieldDefinition->getFieldtype(),
                        $value
                    );
                }

                if (!empty($fieldValue->getData())) {
                    $blockItemIsEmpty = false;
                }
            }
            unset($fieldValue);

            if ($blockItemIsEmpty) {
                $this->importer->getLogger()->info('Skipping block item ['.$itemIndex.'] as all fields are empty');
                continue;
            }

            // CHECK ALL EXISTING ITEMS IF EQUAL AND ONLY IF NOT EQUAL ADD NEW ITEM
            foreach ($blockCollection as $index => $existingBlockItem) {
                $allFieldsEqual = true;
                foreach ($existingBlockItem as $field => $fieldValue) {
                    $blockFieldDefinition = $fieldDefinition->getFieldDefinition($field);

                    if (!isset($blockItem[$field]) || !$this->importer->isEqual($blockFieldDefinition, $blockItem[$field]->getData(), $fieldValue->getData())) {
                        $allFieldsEqual = false;
                        break;
                    }
                }

                if ($allFieldsEqual) {
                    $this->importer->getLogger()->info('Not adding block item because item '.$index.' is equal');
                    continue 2;
                }
            }

            if (isset($blockCollection[(int)$itemIndex])) {
                $blockCollection[] = $blockItem;
            } else {
                $blockCollection[(int)$itemIndex] = $blockItem;
            }
        }
        unset($blockItem);
        ksort($blockCollection);

        return $blockCollection;
    }
}