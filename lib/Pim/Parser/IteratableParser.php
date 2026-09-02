<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;


use Sylphen\DataBridgeBundle\lib\Pim\Serializer;

trait IteratableParser
{
    /** @var int|null */
    private $limit;

    /** @var int */
    private $offset = 0;

    /** @var array|null */
    private $current;

    /** @var int */
    private $position = 0;

    /** @var int */
    private $positionOverAllFiles = 0;

    /**
     * @param int|null $limit
     */
    public function setLimit($limit)
    {
        if ($limit === INF || $limit == PHP_INT_MAX) {
            $limit = null;
        }

        $this->limit = $limit;
    }

    public function getLimit() {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->position;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        if($this->getLimit() !== null && $this->positionOverAllFiles >= $this->getLimit() + $this->offset) {
            if(method_exists($this, 'archive')) {
                $this->archive();
            }
            return false;
        }

        return $this->position === 0 || $this->current !== null;
    }

    /**
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->position = 0;
    }

    /**
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $this->position++;
        $this->positionOverAllFiles++;
    }
}