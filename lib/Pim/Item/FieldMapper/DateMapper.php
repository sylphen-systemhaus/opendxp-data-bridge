<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Element\ValidationException;

class DateMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\Date ||
            $fieldDefinition instanceof Data\Datetime ||
            $fieldDefinition->getFieldtype() === 'date' ||
            $fieldDefinition->getFieldtype() === 'datetime';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if ($value instanceof DateTimeInterface) {
            $value = Carbon::instance($value);
        } else {
            if (!empty($mapping['format']['dateFormat'])) {
                try {
                    $date = Carbon::createFromFormat($mapping['format']['dateFormat'], $value);
                } catch (\Exception $e) {
                    $date = null;
                }

                if ($date) {
                    if ($fieldDefinition->getFieldtype() === 'date') {
                        $date->setTime(12, 0);
                    }

                    $value = $date->getTimestamp();
                } elseif ($value !== null && $value !== '' && !ctype_digit($value)) {
                    try {
                        $parsedValue = new Carbon($value, new DateTimeZone('UTC'));
                        if ($fieldDefinition->getFieldtype() === 'date') {
                            $parsedValue->setTime(12, 0);
                        }

                        $parsedValue = $parsedValue->getTimestamp();
                    } catch(\Exception $e) {
                        $parsedValue = @strtotime($value);
                    }

                    if ($parsedValue === false && $value) {
                        throw new ValidationException('Value "'.$value.'" could not be parsed as date');
                    }
                    $value = $parsedValue;
                }
            } elseif ($value !== null) {
                if (!ctype_digit($value)) {
                    $parsedValue = strtotime($value);
                    if ($parsedValue === false && $value) {
                        throw new ValidationException('Value "'.$value.'" could not be parsed as date');
                    }
                    $value = $parsedValue;
                }
            }

            if (empty($value)) {
                $value = null;
            } else {
                $timestamp = (int)$value;
                $value = new Carbon('@0');
                $value->setTimestamp($timestamp);
            }
        }
        
        if ($value instanceof Carbon && (int)$value->format('Y') <= 0) {
            $value = null;
        }

        return $value;
    }
}