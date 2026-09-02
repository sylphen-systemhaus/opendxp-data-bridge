<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\lib\Pim\Cache\RuntimeCache;

class Tag
{
    /**
     * @param int $id
     * @return \OpenDxp\Model\Element\Tag|null
     */
    public static function getById($id) {
        $cacheKey = 'tag_'.$id;
        if (RuntimeCache::isRegistered($cacheKey)) {
            $tag = RuntimeCache::get($cacheKey);
            if ($tag instanceof \OpenDxp\Model\Element\Tag) {
                return $tag;
            }
        }

        return \OpenDxp\Model\Element\Tag::getById($id);
    }
}