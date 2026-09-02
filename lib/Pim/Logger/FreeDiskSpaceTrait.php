<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */



namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Cli;
use OpenDxp\Model\WebsiteSetting;

trait FreeDiskSpaceTrait
{
    /** @var float */
    private $allowedThresholdInBytes;

    /** @var array */
    private $freeDiskSpace = [];

    /** @var array */
    private $handledLogFiles = [];

    /**
     * @return float
     */
    public function getAllowedDiskThresholdInGb()
    {
        return $this->getAllowedDiskThreshold() / 1024 / 1024 / 1024;
    }

    /**
     * @return float
     */
    public function getAllowedDiskThreshold()
    {
        if($this->allowedThresholdInBytes === null) {
            $websiteSetting = WebsiteSetting::getByName('Data Bridge Logs Minimum Free Disk Space');
            if (!$websiteSetting instanceof WebsiteSetting) {
                $websiteSetting = new WebsiteSetting();
                $websiteSetting->setName('Data Bridge Logs Minimum Free Disk Space');
                $websiteSetting->setType('text');
                $websiteSetting->setData(1e9); // 1 GB default
                $websiteSetting->save();
            }

            $this->allowedThresholdInBytes = $websiteSetting->getData();
        }

        return $this->allowedThresholdInBytes;
    }

    /**
     * @param $logFileHandle
     * @return bool
     */
    private function isFreeSpaceBelowThreshold($logFileHandle): bool
    {
        $diskFreeSpace = $this->getDiskFreeSpace($logFileHandle);

        return $diskFreeSpace < $this->getAllowedDiskThreshold();
    }

    /**
     * @param $fileHandle
     * @return array
     */
    private function getDiskFreeSpace($fileHandle)
    {
        // check free disk space once per 10 seconds
        if (empty($this->freeDiskSpace['freeSpace']) || time() - $this->freeDiskSpace['time'] > 10) {
            if(!stream_is_local($fileHandle)) {
                $this->freeDiskSpace['time'] = time() + 86400; // do not check again as log file storage is remote
                $this->freeDiskSpace['freeSpace'] = INF;
            } else {
                $handleMetadata = stream_get_meta_data($fileHandle);

                $dirname = dirname($handleMetadata['uri']);

                $this->freeDiskSpace['time'] = time();


                $cmd = sprintf('quota -u %s 2>&1', escapeshellarg(get_current_user()));
                $output = Cli::exec($cmd);

                if (empty($output)) {
                    $quota_limit_blocks = 0;
                } else {
                    // Parse the quota output
                    // Example of what we expect:
                    // Filesystem  blocks   quota   limit   grace   files   quota   limit   grace
                    // /dev/sda1     50000  100000  120000            1000   2000    2500
                    $data_line = null;
                    foreach (explode("\n", $output) as $line) {
                        if (preg_match('/^\//', trim($line))) {
                            $data_line = preg_split('/\s+/', trim($line));
                            break;
                        }
                    }

                    if (!$data_line || count($data_line) < 4) {
                        $quota_limit_blocks = 0;
                    } else {
                        // Values from `quota` command (in 1K blocks)
                        $used_blocks = (int)$data_line[1];
                        $soft_quota = (int)$data_line[2];
                        $hard_limit = (int)$data_line[3];

                        // Determine the quota limit (prefer soft quota if set)
                        $quota_limit_blocks = $soft_quota > 0 ? $soft_quota : $hard_limit;
                    }
                }

                if ($quota_limit_blocks <= 0) {
                    // No quota enforced
                    $available_bytes = disk_free_space($dirname);
                } else {
                    $available_blocks = max(0, $quota_limit_blocks - $used_blocks);
                    $available_bytes = $available_blocks * 1024; // convert 1K blocks → bytes
                }

                // differentiate no free disk space from disk space not being determinable
                if($available_bytes == 0) {
                    $available_bytes = 1;
                }

                $this->freeDiskSpace['freeSpace'] = $available_bytes;
            }
        }

        return $this->freeDiskSpace['freeSpace'];
    }

    /**
     * @param $fileHandle
     * @return string
     */
    private function getFreeSpaceWarning($fileHandle): string
    {
        $handleId = (int)$fileHandle;

        // write the warning message only once per handle
        if (!isset($this->handledLogFiles[$handleId])) {
            $this->handledLogFiles[$handleId] = $handleId;

            return 'Free disk space is below the allowed limit, logging gets disabled to not endanger system stability. You can configure the limit by changing website setting "Data Bridge Logs Minimum Free Disk Space".';
        }

        return '';
    }
}