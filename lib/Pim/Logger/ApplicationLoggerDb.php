<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Logger;

use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Monolog\LogRecord;
use OpenDxp\Db;
use OpenDxp\Version;

if (class_exists(LogRecord::class)) {
    class ApplicationLoggerDb extends \OpenDxp\Bundle\ApplicationLoggerBundle\Handler\ApplicationLoggerDb
    {
        public function write(LogRecord $record): void
        {
            if (!empty($record->context['fileObject'])) {
                $existingLogIdWithSameMessage = PimcoreDbRepository::getInstance()->findRowInSql(
                    'SELECT id,message FROM '.self::TABLE_NAME.' WHERE fileObject = ? AND message LIKE ? LIMIT 1',
                    [
                        $record->context['fileObject'],
                        str_replace(['_', '%'], ['\\_', '\\%'], rtrim(substr($record->message, 0, strpos($record->message, "\n") ?: PHP_INT_MAX), "\r")).'%'
                    ]
                );

                if ($existingLogIdWithSameMessage) {
                    $message = preg_replace_callback('/(, happened (\d+)x)?$/', static function ($matches) {
                        if (isset($matches[2])) {
                            return ', happened '.($matches[2] + 1).'x';
                        }

                        return ', happened 2x';
                    }, $existingLogIdWithSameMessage['message'], 1);

                    PimcoreDbRepository::getInstance()->execute('UPDATE '.self::TABLE_NAME.' SET message=? WHERE id=?', [$message, $existingLogIdWithSameMessage['id']]);
                    return;
                }
            }

            parent::write($record);
        }
    }
} else {
    class ApplicationLoggerDb extends \OpenDxp\Bundle\ApplicationLoggerBundle\Handler\ApplicationLoggerDb
    {
        public function write(array $record): void
        {
            if (!empty($record['context']['fileObject'])) {
                $existingLogIdWithSameMessage = PimcoreDbRepository::getInstance()->findRowInSql(
                    'SELECT id,message FROM '.self::TABLE_NAME.' WHERE fileObject = ? AND message LIKE ?',
                    [
                        $record['context']['fileObject'],
                        str_replace(['_', '%'], ['\\_', '\\%'], rtrim(substr($record['message'], 0, strpos($record['message'], "\n") ?: PHP_INT_MAX), "\r")).'%'
                    ]
                );

                if ($existingLogIdWithSameMessage) {
                    $message = preg_replace_callback('/(, happened (\d+)x)?$/', static function ($matches) {
                        if (isset($matches[2])) {
                            return ', happened '.($matches[2] + 1).'x';
                        }

                        return ', happened 2x';
                    }, $existingLogIdWithSameMessage['message'], 1);

                    PimcoreDbRepository::getInstance()->execute('UPDATE '.self::TABLE_NAME.' SET message=? WHERE id=?', [$message, $existingLogIdWithSameMessage['id']]);
                    return;
                }
            }

            parent::write($record);
        }
    }
}