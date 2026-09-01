<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use OpenDxp\Cache;
use OpenDxp\Model\DataObject\Data\QuantityValue;
use OpenDxp\Model\DataObject\QuantityValue\DefaultConverter;
use OpenDxp\Model\DataObject\QuantityValue\Unit;

trait CurrencyConverterTrait
{
    public function doConvert(QuantityValue $quantityValue, Unit $toUnit): QuantityValue
    {
        $conversionRates = Cache::load('data-bridge-currency-conversion-rates');
        if (!$conversionRates) {
            $xml = simplexml_load_string(file_get_contents('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'));

            if ($xml !== false) {
                foreach ($xml->Cube->Cube->Cube as $currencyRate) {
                    $unit = Unit::getByAbbreviation($currencyRate['currency']);
                    if (!$unit instanceof Unit) {
                        continue;
                    }

                    $baseUnit = $unit->getBaseunit();
                    if (!$baseUnit instanceof Unit) {
                        $baseUnit = Unit::getByAbbreviation('EUR');
                        if (!$baseUnit instanceof Unit) {
                            $baseUnit = new Unit();
                            $baseUnit->setAbbreviation('EUR');
                            $baseUnit->setLongname('Euro');
                            $baseUnit->save();
                        }
                    }

                    $baseUnitFactor = 1;
                    if (strtoupper($baseUnit->getAbbreviation()) !== 'EUR') {
                        foreach ($xml->Cube->Cube->Cube as $baseCurrencyRate) {
                            if ($currencyRate['currency'] === $baseUnit->getAbbreviation()) {
                                $baseUnitFactor = 1 / $baseCurrencyRate['rate'];
                            }
                        }
                    }

                    $unit->setFactor($baseUnitFactor * $currencyRate['rate']);
                    $unit->save();
                }
            }
        }

        return (new DefaultConverter())->convert($quantityValue, $toUnit);
    }
}