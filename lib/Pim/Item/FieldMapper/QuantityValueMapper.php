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
use InvalidArgumentException;
use PhpUnitConversion\UnitType;
use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Classificationstore;
use OpenDxp\Model\DataObject\Classificationstore\KeyConfig;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Data\InputQuantityValue;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\Objectbrick\Data\AbstractData;
use OpenDxp\Model\DataObject\Objectbrick\Definition;
use OpenDxp\Model\DataObject\QuantityValue\Unit;
use OpenDxp\Model\Element\ValidationException;

class QuantityValueMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\QuantityValue ||
            $fieldDefinition instanceof Data\InputQuantityValue ||
            $fieldDefinition->getFieldtype() === 'quantityValue' ||
            $fieldDefinition->getFieldtype() === 'inputQuantityValue';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        $tmpValue = (array)$value;

        processQuantityValue:
        if (($fieldDefinition instanceof Data\InputQuantityValue || $fieldDefinition->getFieldtype() === 'inputQuantityValue') && class_exists(InputQuantityValue::class)) {
            $value = new InputQuantityValue();
        } else {
            $value = new QuantityValue();
        }

        if (isset($tmpValue['value'])) {
            $tmpValue = [$tmpValue['value'], $tmpValue['unit'] ?? null];
        }

        if (!isset($tmpValue[0]) || $tmpValue[0] === '') {
            $tmpValue[0] = null;
        }

        $value->setValue($tmpValue[0]);

        if (!empty($tmpValue[1])) {
            try {
                $unit = Unit::getByAbbreviation($tmpValue[1]);
                if (!$unit instanceof Unit) {
                    $unit = Unit::getById($tmpValue[1]);
                }

                if (!$unit instanceof Unit) {
                    throw new \Exception('Unit '.$tmpValue[1].' does not exist');
                }

                $this->migrateCurrencyConverter($unit);

                if (!empty($mapping['format']['autoCreateUnits'])) {
                    /** @var Data\QuantityValue $fieldDefinition */
                    $allowedUnits = $fieldDefinition->getValidUnits();
                    if (!in_array($unit->getId(), $allowedUnits)) {
                        $allowedUnits[] = $unit->getId();
                        $fieldDefinition->setValidUnits($allowedUnits);

                        /** @var ClassDefinition|Definition $classDefinition */
                        $classDefinition = null;
                        $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
                        if ($updatableObject instanceof Concrete) {
                            $classDefinition = $updatableObject->getClass();
                        } elseif ($updatableObject instanceof AbstractData) {
                            $classDefinition = $updatableObject->getDefinition();
                        } elseif ($updatableObject instanceof Classificationstore) {
                            $classDefinition = KeyConfig::getByName($mapping['fieldName'], $updatableObject->getClass()->getFieldDefinition($mapping['targetBrickField'])->getStoreId());
                        }

                        if ($classDefinition) {
                            $classDefinition->addFieldDefinition($fieldDefinition->getName(), $fieldDefinition);
                            $classDefinition->save(true);
                        }
                    }
                }

                $value->setUnitId($unit->getId());
            } catch (\Throwable $e) {
                $unit = $this->createUnit($tmpValue[1]);
                $unit->save();

                $value->setUnitId($unit->getId());

                if (empty($mapping['format']['autoCreateUnits'])) {
                    register_shutdown_function(static function () use ($unit) {
                        $unit->delete();
                    });
                } else {
                    /** @var Data\QuantityValue $fieldDefinition */
                    $allowedUnits = $fieldDefinition->getValidUnits();
                    $allowedUnits[] = $unit->getId();
                    $fieldDefinition->setValidUnits($allowedUnits);

                    /** @var ClassDefinition|Definition $classDefinition */
                    $classDefinition = null;
                    $updatableObject = Importer::getUpdatableObject($dataObject, $mapping);
                    if ($updatableObject instanceof Concrete) {
                        $classDefinition = $updatableObject->getClass();
                    } elseif ($updatableObject instanceof AbstractData) {
                        $classDefinition = $updatableObject->getDefinition();
                    }
                    $classDefinition->addFieldDefinition($fieldDefinition->getName(), $fieldDefinition);
                    try {
                        $classDefinition->save(true);

                        $this->log($dataObject, 'Unit '.$unit->getLongname().' ('.$unit->getAbbreviation().') automatically created', 'info');
                    } catch (\Throwable $e) {
                        $this->log($dataObject, 'Could not create unit '.$unit->getLongname().' ('.$unit->getAbbreviation().') automatically: '.$e->getMessage(), 'error');
                    }
                }
            }

            $allowedUnits = $fieldDefinition->getValidUnits();
            if($fieldDefinition->getDefaultUnit()) {
                array_unshift($allowedUnits, $fieldDefinition->getDefaultUnit());
            }
            if (!in_array($unit->getId(), $allowedUnits)) {
                $conversionSuccessful = false;
                $allowedUnitAbbreviations = [];
                foreach($allowedUnits as $allowedUnitId) {
                    $allowedUnit = Unit::getById($allowedUnitId);
                    if ($allowedUnit instanceof Unit) {
                        $this->migrateCurrencyConverter($allowedUnit);
                        $allowedUnitAbbreviations[] = $allowedUnit->getAbbreviation();
                        if (empty($allowedUnit->getBaseunit()) || (empty($allowedUnit->getFactor()) && empty($allowedUnit->getConversionOffset()))) {
                            $allowedUnitWithBaseUnit = $this->createUnit($allowedUnit->getAbbreviation());
                            if ($allowedUnitWithBaseUnit->getBaseunit() instanceof Unit && ($allowedUnitWithBaseUnit->getBaseunit()->getAbbreviation() === $unit->getAbbreviation() || ($unit->getBaseunit() instanceof Unit && $allowedUnitWithBaseUnit->getBaseunit()->getAbbreviation(
                                        ) === $unit->getBaseunit()->getAbbreviation()))) {
                                $allowedUnit->setBaseunit($allowedUnitWithBaseUnit->getBaseunit());
                                $allowedUnit->setConversionOffset($allowedUnitWithBaseUnit->getConversionOffset());
                                $allowedUnit->setFactor($allowedUnitWithBaseUnit->getFactor());
                            }
                        }

                        try {
                            $originalValue = $value->getValue();
                            $value = $value->convertTo($allowedUnit);
                            $this->log(
                                $dataObject,
                                'Unit "'.$unit->getAbbreviation().'" is not allowed in field '.Helper::getFieldKey($mapping).'. Converted '.$originalValue.' '.$unit->getAbbreviation().' to allowed unit '.$allowedUnit->getAbbreviation().': '.$value->getValue().' '.$allowedUnit->getAbbreviation(),
                                'info'
                            );
                            $conversionSuccessful = true;
                            break;
                        } catch (\Throwable $e) {
                        }
                    }
                }

                if(!$conversionSuccessful) {
                    $this->log(
                        $dataObject,
                        'Unit "'.$unit->getAbbreviation().'" is not allowed in field '.Helper::getFieldKey($mapping).'. Value could not be converted to one of the allowed units "'.implode('", "', $allowedUnitAbbreviations).'". Please add the unit to "Valid units" of the field or configure base unit and conversion factor in the unit settings.',
                        'error'
                    );
                    $value->setValue(null);
                }
            }
        } elseif (is_string($tmpValue[0])) {
            if (preg_match('/(.*?)(-?)\s*(\d+([.,]\d+)*)(.*)/', $tmpValue[0], $valueExtraction)) {
                if ($valueExtraction[3] !== '' && (!empty($valueExtraction[5]) || !empty($valueExtraction[1]))) {
                    $tmpValue = [$valueExtraction[2].trim($valueExtraction[3]), trim($valueExtraction[5]) ?: trim($valueExtraction[1])];
                    goto processQuantityValue;
                }
            }
        }

        if ($value->getUnitId() === null) {
            /** @var Data\QuantityValue|Data\InputQuantityValue $fieldDefinition */
            $defaultUnit = $fieldDefinition->getDefaultUnit();
            if ($defaultUnit) {
                $value->setUnitId($defaultUnit);
            }
        }

        try {
            $fieldDefinition->checkValidity($value);
        } catch (ValidationException $e) {
            $value->setValue(null);

            if ($fieldDefinition instanceof QuantityValue || $fieldDefinition->getFieldtype() === 'quantityValue') {
                $valueBeforeReplacement = $tmpValue[0];
                $tmpValue[0] = $this->parseNumber($tmpValue[0]);
                if ($tmpValue[0] !== null && $tmpValue[0] != $valueBeforeReplacement) {
                    goto processQuantityValue;
                }
            }
        }

        if ($value->getValue() === null || $value->getValue() === '') {
            $value = null;
        }

        return $value;
    }

    /**
     * @param $value1
     * @return Unit
     * @throws \PhpUnitConversion\Exception\UnsupportedConversionException
     */
    private function createUnit($value1): Unit
    {
        try {
            $phpUnitConversionUnit = \PhpUnitConversion\Unit::from($value1);

            $unit = new Unit();
            $unit->setAbbreviation($phpUnitConversionUnit->getSymbol());
            $unit->setLongname($phpUnitConversionUnit->getLabel());
            $unit->setGroup(UnitType::search($phpUnitConversionUnit::TYPE));

            $baseUnitClass = $phpUnitConversionUnit::BASE_UNIT;
            if (get_class($phpUnitConversionUnit) !== $baseUnitClass) {
                $phpUnitConversionBaseUnit = new $baseUnitClass(null, true);
                $baseUnit = null;
                try {
                    $baseUnit = Unit::getByAbbreviation($phpUnitConversionBaseUnit->getSymbol());
                } catch (\Throwable $e) {
                }

                if ($baseUnit === null) {
                    $baseUnit = new Unit();

                    $baseUnit->setAbbreviation($phpUnitConversionBaseUnit->getSymbol());
                    $baseUnit->setLongname($phpUnitConversionBaseUnit->getLabel());

                    $baseUnit->setGroup(UnitType::search($phpUnitConversionBaseUnit::TYPE));
                    $baseUnit->save();
                }

                $unit->setBaseunit($baseUnit);
            }

            if ((float)$phpUnitConversionUnit->getFactor() !== 0.0) {
                $unit->setFactor($phpUnitConversionUnit->getFactor());
            }

            $offset = $phpUnitConversionUnit->getAdditionPost() ?: $phpUnitConversionUnit->getAdditionPre();
            if ((float)$offset !== 0.0) {
                $unit->setConversionOffset($offset);
            }
        } catch (InvalidArgumentException $e) {
            $unit = new Unit();
            $unit->setAbbreviation($value1);
            $unit->setLongname($value1);

            if (in_arrayi($value1, ['EUR', 'USD', 'GBP', 'JPY', 'BGN', 'CZK', 'DKK', 'HUF', 'PLN', 'RON', 'SEK', 'CHF', 'ISK', 'NOK', 'TRY', 'AUD', 'BRL', 'CAD', 'CNY', 'HKD', 'IDR', 'ILS', 'INR', 'KRW', 'MXN', 'MYR', 'NZD', 'PHP', 'SGD', 'THB', 'ZAR', '€', '$', '£'])) {
                $unit->setConverter('@DataBridgeCurrencyConverter');
            }
        }
        return $unit;
    }

    private function migrateCurrencyConverter(Unit $unit): void
    {
        if ($unit->getConverter() === '@DataDirectorCurrencyConverter') {
            $unit->setConverter('@DataBridgeCurrencyConverter');
            $unit->save();
        }
    }
}