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
use OpenDxp\Model\DataObject\Data\RgbaColor;

class ColorMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\RgbaColor ||
            $fieldDefinition->getFieldtype() === 'rgbaColor';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if(is_string($value)) {
            if(preg_match('/^#?([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})$/i', $value, $colorParts)) {
                $value = new RgbaColor(hexdec($colorParts[1]), hexdec($colorParts[2]), hexdec($colorParts[3]));
            } elseif (preg_match('/^#?([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})$/i', $value, $colorParts)) {
                $value = new RgbaColor(hexdec($colorParts[1]), hexdec($colorParts[2]), hexdec($colorParts[3]), hexdec($colorParts[4]));
            } elseif (preg_match('/^#?([a-f0-9])([a-f0-9])([a-f0-9])$/i', $value, $colorParts)) {
                $value = new RgbaColor(hexdec($colorParts[1].$colorParts[1]), hexdec($colorParts[2].$colorParts[2]), hexdec($colorParts[3].$colorParts[3]));
            }
        } elseif(is_array($value)) {
            $value = new RgbaColor($value[0], $value[1], $value[2], $value[3] ?? null);
        }

        return $value;
    }
}