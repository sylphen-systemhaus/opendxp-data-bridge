<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator;

abstract class SortedFileIterator extends \SplHeap {
    public function __construct(\Traversable $iterator) {
        foreach($iterator as $item) {
            if ($this->isFile($item)) {
                $this->insert($item);
            }
        }
    }

    /**
     * @param \SplFileInfo $a
     * @param \SplFileInfo $b
     *
     * @return int
     */
    public function compare($a, $b): int {
        $modificationDateA = $this->getModificationDate($a);
        $modificationDateB = $this->getModificationDate($b);
        if($modificationDateA === $modificationDateB) {
            return \strcmp($this->getFilename($b), $this->getFilename($a));
        }
        return $modificationDateB <=> $modificationDateA;
    }

    /**
     * @param mixed $file
     * @return bool
     */
    abstract protected function isFile($file);

    /**
     * @param mixed $file
     * @return int
     */
    abstract protected function getModificationDate($file);

    /**
     * @param mixed $file
     * @return string file name without folder path
     */
    abstract protected function getFilename($file);
}