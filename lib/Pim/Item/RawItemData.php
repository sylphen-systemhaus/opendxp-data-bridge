<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;

class RawItemData implements Stringable, ArrayAccess
{
    /** @var array */
    private $rawItemData;

    public function __construct($rawItemData)
    {
        if (!is_array($rawItemData)) {
            $rawItemData = [
                'value' => $rawItemData
            ];
        }
        $this->rawItemData = $rawItemData;
    }

    /**
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function __toString()
    {
        return (string)$this->rawItemData['value'];
    }

    public function __get($name)
    {
        return $this->offsetGet($name);
    }

    /**
     * @param $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->rawItemData[$offset]);
    }

    /**
     * @param $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->rawItemData[$offset];
    }

    /**
     * @param $offset
     * @param $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->rawItemData[$offset] = $value;
    }

    /**
     * @param $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->rawItemData[$offset]);
    }
}