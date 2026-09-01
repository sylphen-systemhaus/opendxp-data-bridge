<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

use OpenDxp\Model\DataObject\Data\AbstractQuantityValue;
use OpenDxp\Model\DataObject\QuantityValue\QuantityValueConverterInterface;
use OpenDxp\Model\DataObject\QuantityValue\Unit;

class CurrencyConverter implements QuantityValueConverterInterface
{
    use CurrencyConverterTrait;

    public function convert(AbstractQuantityValue $quantityValue, Unit $toUnit): AbstractQuantityValue
    {
        return $this->doConvert($quantityValue, $toUnit);
    }
}
