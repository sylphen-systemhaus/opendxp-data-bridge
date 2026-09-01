<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use OutOfBoundsException;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;

class NumericMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Numeric ||
            $fieldDefinition->getFieldtype() === 'numeric';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($fieldDefinition->getName() === 'id' && is_array($value) && isset($value['id'])) {
            $value = $value['id'];
        } elseif ($value !== '') {
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

            $value = $this->parseNumber($value);

            if($fieldDefinition->getDecimalPrecision()) {
                $value = round((float)$value, $fieldDefinition->getDecimalPrecision());
            }
        } else {
            $value = null;
        }

        return $value;
    }
}