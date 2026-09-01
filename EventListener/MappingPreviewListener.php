<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;


use Sylphen\DataBridgeBundle\lib\Pim\Item\ItemMoldBuilder;
use Sylphen\DataBridgeBundle\model\Dataport;
use OpenDxp\Cache;
use OpenDxp\Event\Model\ElementEventInterface;

class MappingPreviewListener
{
    public function clearMappingPreviewCache(ElementEventInterface $e) {
        if (\method_exists($e, 'getArgument')) {
            try {
                $saveVersionOnly = $e->getArgument('saveVersionOnly');
                if ($saveVersionOnly) {
                    return;
                }
            } catch (\InvalidArgumentException $exception) {
            }
        }

        $element = $e->getElement();

        Cache::clearTag('mapping-preview-element-'.$element->getId());
    }
}