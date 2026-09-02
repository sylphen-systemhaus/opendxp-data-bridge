<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\GridOperator;

use Sylphen\DataBridgeBundle\lib\Pim\FieldType\CalculatedValueDataQuerySelector;

trait DataQuerySelectorCompatibilityTrait
{
    /**
     * @var \stdClass
     */
    private $config;

    /**
     * {@inheritdoc}
     */
    public function __construct(\stdClass $config, $context = null)
    {
        parent::__construct($config, $context);

        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getDataQuerySelector(): string
    {
        return $this->config->dataQuerySelecotr;
    }

    /**
     * @param string $dataQuerySelector
     */
    public function setDataQuerySelector(string $dataQuerySelector)
    {
        $this->phpClass = $dataQuerySelector;
    }

    /**
     * {@inheritdoc}
     */
    public function doGetLabeledValue($element)
    {
        $result = new \stdClass();
        $result->label = $this->label;

        $result->value = CalculatedValueDataQuerySelector::getResult($this->config->dataQuerySelector ?? null, $element);

        return $result;
    }
}