<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use Sylphen\DataBridgeBundle\Maintenance\CleanupImportTrait;
use OpenDxp\Event\System\MaintenanceEvent;
use OpenDxp\Model\Schedule\Maintenance\Job;

// Fallback for Pimcore 5 (does not support defining maintenance task via service tag)
class ImportStatusListener
{
    use CleanupImportTrait;

    public function maintenance(MaintenanceEvent $event)
    {
        $event->getManager()->registerJob(Job::fromMethodCall('importCleanupLegacy', $this, 'execute'));
    }
}
