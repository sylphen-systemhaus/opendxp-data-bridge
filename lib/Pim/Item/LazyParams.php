<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;
use BadMethodCallException;

class LazyParams implements ArrayAccess
{
    /** @var mixed */
    private $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function __invoke()
    {
        if (is_callable($this->value)) {
            $this->value = call_user_func($this->value);
        }

        return $this->value;
    }

    /**
     * @param string|int $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        $this->__invoke();

        return is_array($this->value) && array_key_exists($offset, $this->value);
    }

    /**
     * @param string|int $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $this->__invoke();

        if(is_array($this->value)) {
            if (isset($this->value[$offset])) {
                return $this->value[$offset];
            }

            foreach ($this->value as $key => $value) {
                if (strtolower($key) === strtolower($offset)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param string|int $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->__invoke();

        if (is_array($this->value)) {
            $this->value[$offset] = $value;
        }
    }

    /**
     * @param string|int $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new BadMethodCallException('Unsetting values is not supported');
    }
}