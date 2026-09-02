<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\lib\Pim\Tree\TreeListing;
use OpenDxp\Model\DataObject;
use Symfony\Component\EventDispatcher\GenericEvent;

class DataObjectTreeListener
{
    public function onBeforeListLoad(GenericEvent $event)
    {
        $context = $event->getArgument('context');

        if (isset($context['query'])) {
            return;
        }

        /** @var DataObject\Listing $originalListing */
        $originalListing = $event->getArgument('list');

        if($originalListing instanceof DataObject\Listing\Concrete || !$originalListing instanceof DataObject\Listing) {
            return;
        }

        $listing = new TreeListing();

        foreach($originalListing->getObjectVars() as $var => $value) {
            $listing->setObjectVar($var, $value);
        }

        $event->setArgument('list', $listing);
    }
}