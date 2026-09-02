<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer;

use OpenDxp\Model\Asset\Image\Thumbnail;

class NormalizeActionSquaredSize implements NormalizeAction
{
    private $sampleSize;

    public function __construct($sampleSize = 8)
    {
        $this->sampleSize = (max(2, $sampleSize));
    }

    public function normalize(\imagick $image)
    {
        $image->resizeimage($this->sampleSize, $this->sampleSize, \Imagick::FILTER_UNDEFINED, 1, false);
    }
}