<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser\SortedFileIterator;

use League\Flysystem\StorageAttributes;
use SplFileInfo;

class FlysystemSortedFileIterator extends SortedFileIterator
{
    /**
     * @param StorageAttributes $file
     * @return bool
     */
    protected function isFile($file)
    {
        return $file->isFile();
    }

    /**
     * @param StorageAttributes $file
     * @return int
     */
    protected function getModificationDate($file)
    {
        return $file->lastModified();
    }

    /**
     * @param StorageAttributes $file
     * @return string
     */
    protected function getFilename($file)
    {
        return basename($file->path());
    }
}