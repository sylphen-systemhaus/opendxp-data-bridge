<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\DummyDeepCopy;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use OpenDxp\Model\Element\DeepCopy\UnmarshalMatcher;
use OpenDxp\Model\Element\ElementDescriptor;
use OpenDxp\Model\Element\Service;
use Symfony\Component\EventDispatcher\GenericEvent;

class DeepCopyListener
{
    /**
     * @param GenericEvent $e
     * @return void
     */
    public function getDeepCopyInstance($e) {
        try {
            $context = $e->getArgument('context');
            if (($context['conversion'] ?? null) === 'unmarshal') {
                return;
            }
        } catch(\InvalidArgumentException $e) {}

        if(Importer::isImportWithoutCompatibilityModeRunning()) {
            $e->setArgument('copier', new DummyDeepCopy());
        }
    }
}