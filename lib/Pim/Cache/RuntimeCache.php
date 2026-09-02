<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Cache;

use OpenDxp\Cache\RuntimeCache as PimcoreRuntimeCache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * @method static bool isRegistered(string $id)
 * @method static mixed get(string $id)
 * @method static void save(mixed $data, string $id)
 * @method static void clear()
 */
class RuntimeCache
{
    /**
     * @var self|null
     */
    protected static $instance;

    /**
     * Retrieves the default registry instance.
     *
     * @return PimcoreRuntimeCache|\OpenDxp\Cache\Runtime
     */
    public static function getInstance()
    {
        if(class_exists(PimcoreRuntimeCache::class)) {
            return PimcoreRuntimeCache::getInstance();
        }
        return \OpenDxp\Cache\Runtime::getInstance();
    }

    public static function __callStatic($name, $arguments)
    {
        return self::getInstance()->$name(...$arguments);
    }
}