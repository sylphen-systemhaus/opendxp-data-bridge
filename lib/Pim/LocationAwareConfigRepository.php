<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim;

class LocationAwareConfigRepository extends \OpenDxp\Config\LocationAwareConfigRepository
{
    /**
     * copied original method but allow to save config, even if $writeTarget === self::LOCATION_SYMFONY_CONFIG && !\OpenDxp::getKernel()->isDebug()
     *
     * @throws \Exception
     */
    public function isWriteable(?string $key = null, ?string $dataSource = null): bool
    {
        $writeTarget = $this->getWriteTarget();

        if ($writeTarget === self::LOCATION_SYMFONY_CONFIG && !\OpenDxp::getKernel()->isDebug()) {
            $directory = rtrim($this->storageDirectory ?? $this->storageConfig['write_target']['options']['directory'], '/\\');

            if (!is_dir($directory) && !mkdir($directory, 0755) && !is_dir($directory)) {
                return false;
            }

            $fh = fopen($directory.'/'.$key.'.yaml', 'wb');
            @fclose($fh);
            return file_exists($directory.'/'.$key.'.yaml');
        }

        return parent::isWriteable($key, $dataSource);
    }
}