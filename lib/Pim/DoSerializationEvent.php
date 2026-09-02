<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Sylphen\DataBridgeBundle\lib\Pim;

use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\Element\ElementInterface;
use Symfony\Contracts\EventDispatcher\Event;

class DoSerializationEvent extends Event
{
    /** @var mixed */
    private $element;

    /** @var Data */
    private $fieldDefinition;

    /** @var string[] */
    private $getterArguments;

    /** @var bool */
    private $doSerialization = true;

    /**
     * @param mixed $element
     * @param Data $fieldDefinition
     * @param string[] $getterArguments
     */
    public function __construct($element, Data $fieldDefinition, array $getterArguments)
    {
        $this->element = $element;
        $this->fieldDefinition = $fieldDefinition;
        $this->getterArguments = $getterArguments;
    }

    public function getElement()
    {
        return $this->element;
    }

    public function getFieldDefinition(): Data
    {
        return $this->fieldDefinition;
    }

    public function getGetterArguments(): array
    {
        return $this->getterArguments;
    }

    public function doSerialization(bool $doSerialization): void{
        $this->doSerialization = $doSerialization;
    }

    public function shallBeSerialized(): bool{
        return $this->doSerialization;
    }
}