<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Tools\Installer;
use InvalidArgumentException;

class ApiKeys extends PimcoreDbRepository
{
    public function getTableName(): string
    {
        return Installer::TABLE_API_KEYS;
    }
}