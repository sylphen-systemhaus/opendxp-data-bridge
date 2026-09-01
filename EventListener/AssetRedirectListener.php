<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Model\Asset;

class AssetRedirectListener
{
    private static $links = [];

    public function checkForLinkSupport(AssetEvent $e)
    {
        $asset = $e->getAsset();
        if(!$asset instanceof Asset) {
            return;
        }

        $parent = $asset->getParent();
        if (!$parent instanceof Asset) {
            return;
        }

        $oldFullPath = PimcoreDbRepository::getInstance()->findOneInSql('SELECT CONCAT(path, filename) FROM assets WHERE id = ?', [$asset->getId()]);
        $newFullPath = rtrim($asset->getParent()->getFullPath(), '/').'/'.$asset->getFilename(); // getFullpath() does not return the new full path when moving asset

        if($oldFullPath !== $newFullPath) {
            $assetRootDirectory = rtrim(defined('OPENDXP_ASSET_DIRECTORY') ? OPENDXP_ASSET_DIRECTORY : OPENDXP_WEB_ROOT . '/var/assets', '/');

            if (is_link($assetRootDirectory.$newFullPath)) {
                @unlink($assetRootDirectory.$newFullPath);
            }

            if(file_exists($assetRootDirectory.$oldFullPath)) {
                self::$links[$newFullPath] = $assetRootDirectory.$oldFullPath;
            }
        }
    }

    public function createLinkIfRenamed(AssetEvent $e)
    {
        $asset = $e->getAsset();
        if (!$asset instanceof Asset || !isset(self::$links[$asset->getRealFullPath()])) {
            return;
        }

        $assetRootDirectory = rtrim(defined('OPENDXP_ASSET_DIRECTORY') ? OPENDXP_ASSET_DIRECTORY : OPENDXP_WEB_ROOT.'/var/assets', '/');

        if(!file_exists(self::$links[$asset->getRealFullPath()])) {
            symlink(str_repeat('../', substr_count(str_replace($assetRootDirectory, '', self::$links[$asset->getRealFullPath()]), '/') - 1).ltrim($asset->getRealFullPath(), '/'), self::$links[$asset->getRealFullPath()]);
        }
    }
}