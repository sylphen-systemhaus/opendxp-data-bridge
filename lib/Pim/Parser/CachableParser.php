<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

interface CachableParser
{
    /**
     * @return int unix timestamp of last modification of import source
     */
    public function getLastModified();

    /**
     * archive current import source, reset parser state to be able to import next import source
     * @return bool true if there is another resource to be imported
     */
    public function gotoNextImportResource();
}