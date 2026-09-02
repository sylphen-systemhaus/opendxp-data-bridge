<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\NormalizeAction;
use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\NormalizeActionHistogram;
use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\NormalizeActionSquaredSize;
use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\Normalizer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use InvalidArgumentException;
use OpenDxp\Cache;
use OpenDxp\Event\Model\AssetEvent;
use OpenDxp\Event\Model\ElementEventInterface;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Asset\Folder;
use OpenDxp\Model\Asset\Image;
use OpenDxp\Model\Property;

class AssetHashListener
{
    public function updateHash(AssetEvent $e)
    {
        $asset = $e->getAsset();
        if($asset instanceof Folder) {
            return;
        }

        $parent = $asset->getParent();
        if(!$parent instanceof Asset) {
            return;
        }

        $parentPath = $parent->getFullPath(); // when asset gets moved realpath is not correct yet but only parent gets set

        $fieldMappings = new Fieldmapping();
        $calculateHash = false;
        $dataports = new Dataport();
        foreach($fieldMappings->find(['format LIKE ?' => '%"preventDuplicates";b:1%']) as $fieldMapping) {
            $dataport = $dataports->get($fieldMapping['dataportId']);
            $targetConfig = $dataport['targetconfig'];
            if(strpos($asset->getRealPath(), $targetConfig['assetFolder']) === 0 || strpos($parentPath, $targetConfig['assetFolder']) === 0) {
                $calculateHash = true;
                break;
            }
        }

        if(!$calculateHash) {
            return;
        }

        try {
            $asset->setProperty(Normalizer::HASH_PROPERTY, 'text', Normalizer::getHash($asset));
        } catch (\Exception $e) {
            return;
        }
    }
}