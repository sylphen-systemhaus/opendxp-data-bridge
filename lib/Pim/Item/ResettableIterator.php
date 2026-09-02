<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item;

use Exception;
use Iterator;
use IteratorAggregate;
use Traversable;

class ResettableIterator implements IteratorAggregate
{
    /** @var Iterator */
    private $innerIterator;

    private $startIndex;

    /**
     * @param Iterator $innerIterator
     */
    public function __construct(Iterator $innerIterator)
    {
        $this->innerIterator = $innerIterator;
        $this->startIndex = $innerIterator->key();
    }

    public function getIterator(): Traversable
    {
        return $this->innerIterator;
    }

    public function reset()
    {
        foreach ($this->innerIterator as $tmp) {
            if ($this->innerIterator->key() === $this->startIndex) {
                return;
            }
        }
    }
}