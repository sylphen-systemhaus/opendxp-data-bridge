<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer;

class NormalizeActionHistogram implements NormalizeAction
{
    public function normalize(\imagick $image)
    {
        $image->equalizeImage();
    }
}