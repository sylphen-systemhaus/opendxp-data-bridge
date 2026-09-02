<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\GridOperator;

use OpenDxp\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\AbstractOperator;
use OpenDxp\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use OpenDxp\Model\Element\ElementInterface;

class DataQuerySelectorOperatorPimcore11 extends AbstractOperator
{
    use DataQuerySelectorCompatibilityTrait;

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null {
        return $this->doGetLabeledValue($element);
    }
}