<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use OpenDxp\Model\DataObject\Concrete;

class Export extends Concrete
{
    protected $o_className = 'DataBridgeExport'; // necessary for getList() calls to not throw fatal error
}