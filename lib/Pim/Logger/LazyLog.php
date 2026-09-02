<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Closure;

class LazyLog
{
    private $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    public function __toString()
    {
        return call_user_func($this->callback);
    }
}