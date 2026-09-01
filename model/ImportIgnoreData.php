<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\model;

use Sylphen\DataBridgeBundle\Tools\Installer;
use Doctrine\DBAL\Connection;

class ImportIgnoreData extends PimcoreDbRepository
{
    /**
     * Appended to a logged ignored value so the dry-run UI shows the reason; stripped before hashing
     */
    public const IGNORED_VALUE_LOG_SUFFIX = '(skipped because it is configured to be ignored for this dataport / object combination)';

    /** @var bool */
    private $useImportIgnoreObjectId;

    public function __construct(?Connection $connection = null, bool $useImportIgnoreObjectId = false)
    {
        parent::__construct($connection);
        $this->useImportIgnoreObjectId = $useImportIgnoreObjectId;
    }

    public function getTableName(): string
    {
        return Installer::TABLE_IMPORT_IGNORE;
    }

    public function isObjectIdUsed(): bool
    {
        return $this->useImportIgnoreObjectId;
    }

    public function createOrUpdate(array $data, $table = null)
    {
        if (isset($data['value'])) {
            $data['value'] = self::stripIgnoredValueLogSuffix($data['value']);
        }
        if (!isset($data['hash'])) {
            $data['hash'] = $this->computeHash($data);
        }
        parent::createOrUpdate($data, $table);
    }

    public function computeHash(array $data): string
    {
        $value = self::stripIgnoredValueLogSuffix((string)($data['value'] ?? ''));
        if ($this->useImportIgnoreObjectId) {
            return md5($data['classId'].'-'.$data['objectId'].'-'.$data['field'].'-'.$value);
        }
        return md5($data['classId'].'-'.$data['path'].'-'.$data['field'].'-'.$value);
    }

    private static function stripIgnoredValueLogSuffix(string $value): string
    {
        $suffix = ' '.self::IGNORED_VALUE_LOG_SUFFIX;
        if (substr($value, -strlen($suffix)) === $suffix) {
            return substr($value, 0, -strlen($suffix));
        }
        return $value;
    }
}