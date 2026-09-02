<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\AssetNormalizer;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use InvalidArgumentException;
use OpenDxp\Model\Asset;
use OpenDxp\Model\Asset\Image;

class Normalizer
{
    const HASH_PROPERTY = 'normalized_hash';

    /** @var NormalizeAction[] */
    private $actions = [];

    /**
     * @param Normalizer[] $normalizers
     */
    public function __construct(array $actions)
    {
        $this->actions = $actions;
    }

    private function normalize($filePath) {
        $image = new \Imagick();
        $image->readImage($filePath);
        $image->stripImage();

        foreach($this->actions as $action) {
            $action->normalize($image);
        }

        $image->writeImage($filePath);
        $image->clear();
    }

    /**
     * @param Asset $asset
     * @return false|string|void
     * @throws \Exception
     */
    public static function getHash(Asset $asset) {
        $stream = $asset->getStream();

        $tmpFilePath = Helper::getTemporaryFileFromStream($stream);

        if ($asset->getType() === 'image') {
            $normalizer = new Normalizer([
                new NormalizeActionHistogram(),
                new NormalizeActionSquaredSize(32),
            ]);
            $normalizer->normalize($tmpFilePath);
        }

        $hash = hash_file('md5', $tmpFilePath);

        @unlink($tmpFilePath);

        return $hash;
    }
}