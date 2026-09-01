<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use ArrayAccess;
use ArrayIterator;
use Countable;
use LogicException;

class ArrayMapIterator extends \IteratorIterator implements Countable, ArrayAccess
{
    /** @var callable Callback */
    protected $callback;

    /** @var int */
    private $count;

    /** @var ArrayIterator */
    private $innerIterator;

    /**
     * @param array $iterator Traversable iterator
     * @param callable $callback Callback used for iterating
     */
    public function __construct(array $data, callable $callback)
    {
        $this->innerIterator = new ArrayIterator($data);
        parent::__construct($this->innerIterator);

        $this->callback = $callback;
        $this->count = count($data);
    }

    /**
     * @return false|mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return call_user_func($this->callback, parent::current());
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->count;
    }

    /**
     * @param $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->innerIterator->offsetExists($offset);
    }

    /**
     * @param $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $value = $this->innerIterator->offsetGet($offset);
        return call_user_func($this->callback, $value);
    }

    /**
     * @param $offset
     * @param $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new LogicException('Setting data is not allowed');
    }

    /**
     * @param $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new LogicException('Unsetting data is not allowed');
    }
}