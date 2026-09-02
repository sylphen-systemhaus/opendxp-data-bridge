<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;

class CheckboxMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Checkbox ||
            $fieldDefinition instanceof Data\BooleanSelect ||
            $fieldDefinition->getFieldtype() === 'checkbox' ||
            $fieldDefinition->getFieldtype() === 'booleanSelect';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($value !== null) {
            $value = (bool)$value;
        } elseif ($fieldDefinition->getName() === 'published') {
            $value = (is_callable($currentValue) ? $currentValue() : $currentValue);
        }

        return $value;
    }
}