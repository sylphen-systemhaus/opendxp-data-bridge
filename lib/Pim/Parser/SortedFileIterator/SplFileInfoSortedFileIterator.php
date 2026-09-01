<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator;

use SplFileInfo;

class SplFileInfoSortedFileIterator extends SortedFileIterator
{
    /**
     * @param SplFileInfo $file
     * @return bool
     */
    protected function isFile($file)
    {
        return $file->isFile();
    }

    /**
     * @param SplFileInfo $file
     * @return int
     */
    protected function getModificationDate($file)
    {
        return $file->getMTime();
    }

    /**
     * @param SplFileInfo $file
     * @return string
     */
    protected function getFilename($file)
    {
        return $file->getFilename();
    }
}