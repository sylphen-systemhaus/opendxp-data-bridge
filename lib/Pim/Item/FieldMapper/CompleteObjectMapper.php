<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Serializer;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Element\ElementInterface;

class CompleteObjectMapper extends AbstractFieldMapper
{
    /**
     * @param array $mapping
     * @param Data $fieldDefinition
     * @param AbstractModel|null $dataObject
     * @return bool
     */
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition->getName() === 'Complete Object';
    }

    /**
     * @param $mapping
     * @param $value
     * @param $currentValue
     * @param Data $fieldDefinition
     * @param AbstractModel|null $dataObject
     * @return null
     */
    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $format = $mapping['format'];

        if($dataObject instanceof ElementInterface) {
            unset($value['query']);
            if ($value instanceof ElementInterface) {
                $serializer = new Serializer(1);
                $value = $serializer->serialize($value);
            }

            if (!is_array($value)) {
                $this->log($dataObject, 'Provided data has to be an array or ElementInterface, but got: '.gettype($value).'. Skipping this field.', 'error');
                return null;
            }

            $generatedRawItem = $this->importer->addRawItem($value);

            $fieldMappings = [];
            $fieldNo = 1;
            foreach ($value as $field => $fieldValue) {
                $language = null;
                $fieldParts = explode('#', $field);
                if (isset($fieldParts[1])) {
                    $field = $fieldParts[0];
                    $language = $fieldParts[1];
                }

                $fieldDefinition = Importer::getFieldDefinition($dataObject, $field);
                if($fieldDefinition instanceof Data\CalculatedValue) {
                    $fieldNo++;
                    continue;
                }

                $fieldMapping = ['fieldName' => $field, 'fieldNo' => $fieldNo, 'format' => ['autoCreateUnits' => true, 'purgeitems' => $format['purgeitems'] ?? false, 'autoCreate' => !empty($format['auto_generate_fields'])]];
                if ($language !== null) {
                    $fieldMapping['locale'] = $language;
                }

                $fieldMappings[] = $fieldMapping;
                $fieldNo++;
            }

            $this->importer->mapData($dataObject, $generatedRawItem, $fieldMappings);

            $this->importer->removeRawItem($generatedRawItem['id']);
        }

        return $value;
    }
}
