<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace InSquare\OpendxpProcessManagerBundle;

/**
 * No-op fallback when in-square/opendxp-process-manager-bundle is not installed.
 * Same pattern as Blackbit fallback/Elements/.../ExecutionTrait.php.
 * When the Process Manager package is present, Composer loads the real trait from vendor/.
 */
trait ExecutionTrait
{
    public static function initProcessManager($monitoringId, array $options = [])
    {
    }

    public static function getMonitoringItem()
    {
        return null;
    }
}
