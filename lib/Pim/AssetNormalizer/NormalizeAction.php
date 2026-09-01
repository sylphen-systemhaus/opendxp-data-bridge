<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer;

interface NormalizeAction
{
    /**
     * @param \imagick $image
     * @return \imagick
     */
    public function normalize(\imagick $image);
}