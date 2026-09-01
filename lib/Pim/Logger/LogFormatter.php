<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\lib\Pim\Helper;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

if (version_compare(Helper::getPackageVersion('psr/log'), '2', '>=')) {
    class LogFormatter extends LineFormatter
    {
        public function format(LogRecord $record): string
        {
            return '['.$record->level->getName().'] '.$record->message."\n";
        }
    }
} else {
    class LogFormatter extends LineFormatter
    {
        public function format(array $record): string
        {
            return '['.$record['level_name'].'] '.$record['message']."\n";
        }
    }
}