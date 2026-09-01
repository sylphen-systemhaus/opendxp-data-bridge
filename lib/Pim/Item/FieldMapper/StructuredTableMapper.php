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
use OpenDxp\Model\Element\ValidationException;

class StructuredTableMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\StructuredTable || $fieldDefinition->getFieldtype() === 'structuredTable';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if(is_array($value)) {
            $value = new \OpenDxp\Model\DataObject\Data\StructuredTable($value);
        } elseif(!$value instanceof \OpenDxp\Model\DataObject\Data\StructuredTable && $value !== null) {
            $this->log($dataObject, 'Value '.Importer::getLogOutput($value).' could not be parsed as a structured table in field '.Helper::getFieldKey($mapping), 'warning');
            $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
        }

        return $value;
    }
}