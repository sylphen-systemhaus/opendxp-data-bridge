<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\Maintenance;

use Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer\Normalizer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\Dataport;
use Sylphen\DataBridgeBundle\model\Fieldmapping;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use OpenDxp\Maintenance\TaskInterface;
use OpenDxp\Model\Asset\Listing;
use OpenDxp\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyRelation;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Hotspotimage;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Image;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ImageGallery;
use OpenDxp\Model\DataObject\ClassDefinition\Data\ManyToManyRelation;
use OpenDxp\Model\Property;

class AssetHashTask implements TaskInterface
{
    public function execute(): void
    {
        $fieldMappings = new Fieldmapping();
        $dataports = new Dataport();
        $itemMoldBuilder = \OpenDxp::getContainer()->get(ItemMoldBuilder::class);

        $folders = [];
        foreach ($fieldMappings->find(['format LIKE ?' => '%"preventDuplicates";b:1%']) as $fieldMapping) {
            $dataport = $dataports->get($fieldMapping['dataportId']);
            $targetConfig = $dataport['targetconfig'];

            $itemMold = $itemMoldBuilder->getItemMold($fieldMapping['dataportId']);
            $fieldDefinition = Importer::getFieldDefinition($itemMold, $fieldMapping);

            if($fieldDefinition instanceof Image || $fieldDefinition instanceof ImageGallery || $fieldDefinition instanceof Hotspotimage || ($fieldDefinition instanceof ManyToManyRelation && $fieldDefinition->getAssetsAllowed()) || ($fieldDefinition instanceof AdvancedManyToManyRelation && $fieldDefinition->getAssetsAllowed())) {
                $folders[] = $targetConfig['assetFolder'];
            }
        }

        foreach($folders as $folder) {
            $listing = new Listing();
            $listing->addConditionParam('path LIKE ?', rtrim($folder, '/').'/%');
            $listing->addConditionParam('type != ?', 'folder');
            $listing->addConditionParam('id NOT IN (SELECT cid FROM properties WHERE ctype="asset" AND name=?)', Normalizer::HASH_PROPERTY);

            foreach($listing as $asset) {
                $property = new Property();
                $property->setValues([
                    'cid' => $asset->getId(),
                    'ctype' => 'asset',
                    'type' => 'text',
                    'name' => Normalizer::HASH_PROPERTY,
                    'data' => Normalizer::getHash($asset),
                    'inheritable' => 0,
                ]);
                $property->save();
            }
        }
    }
}