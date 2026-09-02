<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;

class TableMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Table || $fieldDefinition->getFieldtype() === 'table';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        /** @var Data\Table $fieldDefinition */
        if (is_array($value)) {
            $value = array_filter($value);

            $returnData = [];
            if($fieldDefinition->isColumnConfigActivated() && $fieldDefinition->getColumnConfig()) {
                foreach ($value as $rowIndex => $row) {
                    foreach ($fieldDefinition->getColumnConfig() as $columnIndex => $columnConfig) {
                        $returnData[$rowIndex][$columnConfig['key']] = '';

                        foreach ($row as $columnKey => $columnValue) {
                            if ($columnConfig['key'] === $columnKey) {
                                $returnData[$rowIndex][$columnConfig['key']] = $columnValue;
                                continue 2;
                            }
                        }

                        foreach ($row as $columnKey => $columnValue) {
                            if ($columnIndex == $columnKey) {
                                $returnData[$rowIndex][$columnConfig['key']] = $columnValue;
                                continue 2;
                            }
                        }
                    }
                }

                $value = $returnData;
            }
        }

        return $value;
    }
}