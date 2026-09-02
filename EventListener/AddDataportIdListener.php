<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\EventListener;

use OpenDxp\Event\Model\ElementEventInterface;

class AddDataportIdListener
{
    /** @var int */
    private static $dataportId;

    public function addDataportId(ElementEventInterface $e) {
        if (\method_exists($e, 'setArgument')) {
            $e->setArgument('dataportId', self::$dataportId);
        }
    }

    /**
     * @param int $dataportId
     */
    public static function setDataportId($dataportId)
    {
        self::$dataportId = $dataportId;
    }
}