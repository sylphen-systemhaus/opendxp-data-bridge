<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use ArrayAccess;
use ArrayIterator;
use Exception;
use IteratorAggregate;
use Traversable;

class RawItem implements ArrayAccess, IteratorAggregate
{
    private $rawItem;

    public function __construct(array $rawItem)
    {
        $this->rawItem = array_map(static function ($rawItemData) {
            unset($rawItemData['rawItemId']);
            return new RawItemData($rawItemData);
        }, $rawItem);
    }

    public function asArray()
    {
        return $this->rawItem;
    }

    public function toArray()
    {
        return $this->asArray();
    }

    /**
     * @param $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        if (isset($this->rawItem[$offset])) {
            return true;
        }

        foreach (array_keys($this->rawItem) as $fieldname) {
            if (mb_strtolower($fieldname) === mb_strtolower($offset)) {
                return true;
            }
        }

        return false;
    }

    public function __get($name)
    {
        return $this->offsetGet($name);
    }

    /**
     * @param $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (array_key_exists($offset, $this->rawItem)) {
            return $this->rawItem[$offset];
        }

        foreach (array_keys($this->rawItem) as $fieldname) {
            if (mb_strtolower($fieldname) === mb_strtolower($offset)) {
                return $this->rawItem[$fieldname];
            }
        }

        return null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->rawItem[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->rawItem[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rawItem);
    }
}