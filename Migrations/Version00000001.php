<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Sylphen\DataBridgeBundle\Migrations;

use Sylphen\DataBridgeBundle\Controller\DocumentController;
use Sylphen\DataBridgeBundle\Controller\Web2PrintController;
use Sylphen\DataBridgeBundle\EventListener\ClassChangedListener;
use Sylphen\DataBridgeBundle\model\PimcoreDbRepository;
use Sylphen\DataBridgeBundle\Tools\Installer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use OpenDxp\Model\Document\DocType;

/**
 * Sylphen Data Bridge v1.0 baseline.
 *
 * Greenfield: CREATE sylphen_dd_* + permissions.
 * Blackbit Data Director: RENAME plugin_pim_* / translations_DataDirector_* ,
 * remap plugin_bb_pim permissions, persisted labels, class names, portlets.
 *
 * settings_store JSON (document types, custom views, perspectives) is rewritten
 * via addSql REPLACE — DocType/Perspective APIs alone miss settings-store rows.
 *
 * Most port steps skip on error. Perspective portlet rewrite aborts
 * the migration if leftover DataDirector_* types cannot be written to YAML.
 * Schema CREATE still runs only when the target table is missing and
 * no Blackbit source table remains.
 *
 * REVIEW: commented opendxp-branch control flow at the bottom of this class
 * (not executed). Remove before merging to main.
 */
class Version00000001 extends AbstractMigration
{
    private const BLACKBIT_WEB2PRINT = 'Blackbit\\DataDirectorBundle\\Controller\\Web2PrintController::pageAction';

    private const BLACKBIT_DOCUMENT = 'Blackbit\\DataDirectorBundle\\Controller\\DocumentController::pageAction';

    private const BLACKBIT_BUNDLE = 'Blackbit\\DataDirectorBundle\\BlackbitDataDirectorBundle';

    private const NEW_BUNDLE = 'Sylphen\\DataBridgeBundle\\SylphenDataBridgeBundle';

    private const BLACKBIT_NS = 'Blackbit\\DataDirectorBundle';

    private const NEW_NS = 'Sylphen\\DataBridgeBundle';

    /** @var array<string, string> old table => new table */
    private const BLACKBIT_TABLES = [
        'plugin_pim_dataport' => Installer::TABLE_DATAPORT,
        'plugin_pim_dataport_resource' => Installer::TABLE_DATAPORT_RESOURCE,
        'plugin_pim_rawitem' => Installer::TABLE_RAWITEM,
        'plugin_pim_rawitemData' => Installer::TABLE_RAWITEMDATA,
        'plugin_pim_rawitemField' => Installer::TABLE_RAWITEMFIELD,
        'plugin_pim_fieldmapping' => Installer::TABLE_FIELDMAPPING,
        'plugin_pim_importstatus' => Installer::TABLE_IMPORTSTATUS,
        'plugin_pim_queue' => Installer::TABLE_QUEUE,
        'plugin_pim_api_keys' => Installer::TABLE_API_KEYS,
        'plugin_pim_favorites' => Installer::TABLE_FAVORITES,
        'plugin_pim_import_ignore' => Installer::TABLE_IMPORT_IGNORE,
        'translations_DataDirector_Cache' => Installer::TABLE_TRANSLATION_CACHE,
        'translations_DataDirector_Glossary' => Installer::TABLE_TRANSLATION_GLOSSARY,
    ];

    /** @var array<string, string> longest Blackbit keys first */
    private const BLACKBIT_PERMISSIONS = [
        'plugin_bb_pim_admin_permission' => 'plugin_sylphen_data_bridge_admin_permission',
        'plugin_bb_pim' => 'plugin_sylphen_data_bridge',
    ];

    public function getDescription(): string
    {
        return 'Sylphen Data Bridge v1.0: schema, Blackbit Data Director port, document types';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Do not inspect $schema (getTable/hasTable). Doctrine's lazy $toSchema
        // would snapshot index names before addSql runs; getSqlDiffToMigrate then
        // emits reverse RENAME INDEX data_bridge_* → data_director_*.
        unset($schema);

        // REVIEW (opendxp, not executed): if sylphen_dd_* already exist → return (no port steps).
        // REVIEW (opendxp, not executed): if plugin_pim_* exist → rename then return (no steps below).
        // REVIEW (opendxp, not executed): rename threw if old and new tables both exist; main skips.

        $this->renameBlackbitTables();
        $this->createMissingTables();
        $this->ensurePermissions();
        $this->portBlackbitPermissions();
        $this->portPersistedLabels();
        $this->rewriteBlackbitClassNames();
        $this->portBundleInstallFlag();
        $this->purgeBlackbitMigrationVersions();
        $this->renameBlackbitVarDirectories();
        $this->renameLeftoverIndexes();

        $this->queueSettingsStorePayloadRewrites();

        try {
            $this->ensureDocumentTypes();
        } catch (\Throwable $e) {
            $this->write('Document types API: skipped (' . $e->getMessage() . '). settings_store REPLACE queued via addSql.');
        }

        try {
            ClassChangedListener::rewriteLegacyPortletTypes();
        } catch (\Throwable $e) {
            $this->write('Perspective API rewrite: skipped (' . $e->getMessage() . '). settings_store REPLACE queued via addSql.');
        }
    }

    /**
     * One RENAME TABLE for all Blackbit sources whose target does not exist.
     */
    private function renameBlackbitTables(): void
    {
        $pairs = [];
        foreach (self::BLACKBIT_TABLES as $old => $new) {
            if ($this->liveTableExists($old) && !$this->liveTableExists($new)) {
                $pairs[] = $this->quoteIdent($old) . ' TO ' . $this->quoteIdent($new);
            }
        }

        if ($pairs === []) {
            return;
        }

        try {
            $this->connection->executeStatement('RENAME TABLE ' . implode(', ', $pairs));
            $this->write('Renamed Blackbit tables: ' . count($pairs) . '.');
        } catch (\Throwable $e) {
            $this->write('RENAME TABLE skipped: ' . $e->getMessage());
        }
    }

    private function createMissingTables(): void
    {
        foreach ($this->createTableSql() as $table => $sql) {
            if ($this->liveTableExists($table)) {
                continue;
            }

            $old = array_search($table, self::BLACKBIT_TABLES, true);
            if (is_string($old) && $this->liveTableExists($old)) {
                $this->write(sprintf(
                    'Skip CREATE `%s`: Blackbit table `%s` is still present (rename failed).',
                    $table,
                    $old
                ));
                continue;
            }

            $this->addSql($sql);
        }
    }

    /**
     * @return array<string, string>
     */
    private function createTableSql(): array
    {
        return [
            Installer::TABLE_API_KEYS => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_api_keys` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(11) unsigned NOT NULL,
  `api_key` varchar(191) DEFAULT NULL,
  `valid_to` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_permissions_api_key` (`api_key`),
  KEY `fk_permissions_users_id` (`users_id`),
  CONSTRAINT `fk_permissions_users_id` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            Installer::TABLE_DATAPORT => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_dataport` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL DEFAULT \'\',
  `sourcetype` enum(\'xml\',\'csv\',\'excel\',\'pimcore\',\'files\',\'json\',\'report\',\'grid\',\'fixed-length\',\'object-wizard\') NOT NULL,
  `sourceconfig` mediumtext NOT NULL,
  `targetconfig` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dataport_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_DATAPORT_RESOURCE => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_dataport_resource` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `dataportId` int(11) unsigned NOT NULL,
  `resource` mediumtext DEFAULT NULL,
  `resourceHash` char(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `lastAccess` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_dataport_resource_dataportId_resource` (`dataportId`,`resourceHash`),
  CONSTRAINT `fk_dataport_resource_dataportId` FOREIGN KEY (`dataportId`) REFERENCES `sylphen_dd_dataport` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_FAVORITES => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_favorites` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `dataport_id` int(11) unsigned NOT NULL,
  `users_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_favorites_dataport` (`dataport_id`),
  KEY `fk_favorites_user` (`users_id`),
  CONSTRAINT `fk_favorites_dataport` FOREIGN KEY (`dataport_id`) REFERENCES `sylphen_dd_dataport` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_FIELDMAPPING => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_fieldmapping` (
  `dataportId` int(11) unsigned NOT NULL,
  `fieldName` varchar(255) NOT NULL,
  `locale` varchar(10) NOT NULL DEFAULT \'\',
  `fieldNo` int(11) unsigned DEFAULT NULL,
  `keyMapping` tinyint(1) NOT NULL DEFAULT 0,
  `format` text NOT NULL,
  `calculation` mediumtext DEFAULT NULL,
  `brickName` varchar(255) NOT NULL,
  `targetBrickField` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`dataportId`,`fieldName`,`locale`,`brickName`),
  CONSTRAINT `fk_fieldmapping_dataport` FOREIGN KEY (`dataportId`) REFERENCES `sylphen_dd_dataport` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_IMPORT_IGNORE => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_import_ignore` (
  `hash` char(32) NOT NULL,
  `path` varchar(255) DEFAULT NULL,
  `objectId` int(11) unsigned DEFAULT NULL,
  `field` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `classId` varchar(255) NOT NULL,
  PRIMARY KEY (`hash`),
  KEY `fk_import_ignore_object` (`objectId`),
  CONSTRAINT `fk_import_ignore_object` FOREIGN KEY (`objectId`) REFERENCES `objects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_IMPORTSTATUS => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_importstatus` (
  `key` varchar(27) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `dataport_resource_id` int(11) unsigned DEFAULT NULL,
  `dataport_id` int(11) unsigned DEFAULT NULL,
  `importType` tinyint(3) unsigned NOT NULL,
  `startDate` datetime NOT NULL,
  `endDate` datetime DEFAULT NULL,
  `lastUpdate` datetime NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `totalItems` int(11) unsigned NOT NULL DEFAULT 0,
  `doneItems` int(11) unsigned NOT NULL DEFAULT 0,
  `command_parameters` text DEFAULT NULL,
  `worst_error` text DEFAULT NULL,
  `pid` int(11) unsigned NOT NULL,
  PRIMARY KEY (`key`),
  KEY `fk_importstatus_dataport_resource` (`dataport_resource_id`),
  KEY `fk_importstatus_dataport` (`dataport_id`,`startDate`),
  KEY `idx_importstatus_dataport_status` (`dataport_id`,`status`),
  KEY `idx_importstatus_lastUpdate` (`lastUpdate`),
  KEY `idx_importstatus_totalItems` (`totalItems`),
  KEY `idx_startDate` (`startDate`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_importstatus_dataport` FOREIGN KEY (`dataport_id`) REFERENCES `sylphen_dd_dataport` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_importstatus_dataport_resource` FOREIGN KEY (`dataport_resource_id`) REFERENCES `sylphen_dd_dataport_resource` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_QUEUE => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_queue` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `command` mediumtext NOT NULL,
  `command_hash` char(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `queued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `triggered_by` varchar(255) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `worker_id` varchar(255) NOT NULL DEFAULT \'unknown\',
  `restarts` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_command` (`command_hash`),
  KEY `idx_command` (`command`(191)),
  KEY `idx_queue_worker_id` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_RAWITEM => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_rawitem` (
  `id` binary(16) NOT NULL,
  `dataport_resource_id` int(11) unsigned NOT NULL,
  `updated` datetime NOT NULL,
  `hash` char(32) NOT NULL,
  `priority` varchar(191) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL,
  `toBeDeleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rawitem_dataport_resource_id_hash` (`dataport_resource_id`,`hash`),
  KEY `idx_rawitem_dataport_resource_id_priority` (`dataport_resource_id`,`priority`),
  KEY `idx_dataport_resource_id_updated_priority` (`dataport_resource_id`,`updated`,`priority`),
  KEY `idx_priority` (`priority`),
  KEY `idx_dataport_resource_id_id` (`dataport_resource_id`,`id`),
  KEY `index_dataport_resource_id_toBeDeleted` (`dataport_resource_id`,`toBeDeleted`),
  CONSTRAINT `fk_rawItem_dataport_resource` FOREIGN KEY (`dataport_resource_id`) REFERENCES `sylphen_dd_dataport_resource` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            Installer::TABLE_RAWITEMDATA => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_rawitem_data` (
  `rawItemId` binary(16) NOT NULL,
  `fieldNo` int(11) unsigned NOT NULL,
  `value` longtext DEFAULT NULL,
  PRIMARY KEY (`rawItemId`,`fieldNo`),
  FULLTEXT KEY `fulltext_value` (`value`),
  CONSTRAINT `fk_rawitemdata_rawitem` FOREIGN KEY (`rawItemId`) REFERENCES `sylphen_dd_rawitem` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            Installer::TABLE_RAWITEMFIELD => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_rawitem_field` (
  `dataportId` int(11) unsigned NOT NULL,
  `fieldNo` int(11) unsigned NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT \'\',
  `priority` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`dataportId`,`fieldNo`),
  KEY `index_rawItemField_dataportId_priority` (`dataportId`,`priority`),
  CONSTRAINT `fk_rawitemfield_dataport` FOREIGN KEY (`dataportId`) REFERENCES `sylphen_dd_dataport` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci',
            Installer::TABLE_TRANSLATION_CACHE => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_translation_cache` (
  `key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT \'\',
  `type` varchar(10) DEFAULT NULL,
  `language` varchar(10) NOT NULL DEFAULT \'\',
  `text` text DEFAULT NULL,
  `creationDate` int(11) unsigned DEFAULT NULL,
  `modificationDate` int(11) unsigned DEFAULT NULL,
  `userOwner` int(11) unsigned DEFAULT NULL,
  `userModification` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`key`,`language`),
  KEY `language` (`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            Installer::TABLE_TRANSLATION_GLOSSARY => 'CREATE TABLE IF NOT EXISTS `sylphen_dd_translation_glossary` (
  `key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT \'\',
  `type` varchar(10) DEFAULT NULL,
  `language` varchar(10) NOT NULL DEFAULT \'\',
  `text` text DEFAULT NULL,
  `creationDate` int(11) unsigned DEFAULT NULL,
  `modificationDate` int(11) unsigned DEFAULT NULL,
  `userOwner` int(11) unsigned DEFAULT NULL,
  `userModification` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`key`,`language`),
  KEY `language` (`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        ];
    }

    private function ensurePermissions(): void
    {
        $permissionTableHasCategoryColumn = $this->liveColumnExists(
            'users_permission_definitions',
            'category'
        );

        foreach (['plugin_sylphen_data_bridge', 'plugin_sylphen_data_bridge_admin_permission'] as $permissionKey) {
            $permissionData = ['key' => $permissionKey];
            if ($permissionTableHasCategoryColumn) {
                $permissionData['category'] = 'Data Bridge';
            }
            PimcoreDbRepository::getInstance()->insertOrUpdate(
                'users_permission_definitions',
                $permissionData
            );
        }
    }

    private function portBlackbitPermissions(): void
    {
        if (!$this->liveTableExists('users_permission_definitions')) {
            return;
        }

        try {
            foreach (self::BLACKBIT_PERMISSIONS as $from => $to) {
                if (!$this->permissionExists($from)) {
                    continue;
                }

                if ($this->permissionExists($to)) {
                    $this->connection->executeStatement(
                        'DELETE FROM users_permission_definitions WHERE `key` = ?',
                        [$from]
                    );
                } else {
                    $this->connection->executeStatement(
                        'UPDATE users_permission_definitions SET `key` = ? WHERE `key` = ?',
                        [$to, $from]
                    );
                }
            }

            if ($this->liveTableExists('users')) {
                foreach (self::BLACKBIT_PERMISSIONS as $from => $to) {
                    $this->connection->executeStatement(
                        'UPDATE users SET permissions = REPLACE(permissions, ?, ?) WHERE permissions LIKE ?',
                        [$from, $to, '%' . $from . '%']
                    );
                    $this->connection->executeStatement(
                        'UPDATE users SET roles = REPLACE(roles, ?, ?) WHERE roles LIKE ?',
                        [$from, $to, '%' . $from . '%']
                    );
                }
            }

            $this->renameAdminTranslationKeys(self::BLACKBIT_PERMISSIONS);
        } catch (\Throwable $e) {
            $this->write('Blackbit permissions: skipped (' . $e->getMessage() . ').');
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function renameAdminTranslationKeys(array $map): void
    {
        if (!$this->liveTableExists('translations_admin')) {
            return;
        }

        foreach ($map as $from => $to) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM translations_admin WHERE `key` = ?',
                [$from]
            );
            if ($count === 0) {
                continue;
            }

            $targetExists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM translations_admin WHERE `key` = ?',
                [$to]
            ) > 0;

            if ($targetExists) {
                $this->connection->executeStatement(
                    'DELETE FROM translations_admin WHERE `key` = ?',
                    [$from]
                );
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE translations_admin SET `key` = ? WHERE `key` = ?',
                [$to, $from]
            );
        }
    }

    private function portPersistedLabels(): void
    {
        try {
            if ($this->liveTableExists('tags')) {
                $this->connection->executeStatement(
                    "UPDATE tags SET name = 'Data Bridge' WHERE name = 'Data Director' AND idPath = '/'"
                );
            }

            if ($this->liveTableExists('website_settings')) {
                $targetExists = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM website_settings WHERE name = 'Data Bridge Logs Minimum Free Disk Space'"
                ) > 0;
                if (!$targetExists) {
                    $this->connection->executeStatement(
                        "UPDATE website_settings SET name = 'Data Bridge Logs Minimum Free Disk Space' WHERE name = 'Data Director Logs Minimum Free Disk Space'"
                    );
                }
            }

            if ($this->liveColumnExists('users_permission_definitions', 'category')) {
                $this->connection->executeStatement(
                    "UPDATE users_permission_definitions SET category = 'Data Bridge' WHERE category = 'Data Director'"
                );
            }
        } catch (\Throwable $e) {
            $this->write('Persisted labels: skipped (' . $e->getMessage() . ').');
        }
    }

    private function rewriteBlackbitClassNames(): void
    {
        try {
            $tables = [
                Installer::TABLE_FIELDMAPPING => ['calculation'],
                Installer::TABLE_DATAPORT => ['sourceconfig', 'targetconfig'],
            ];

            foreach ($tables as $table => $columns) {
                if (!$this->liveTableExists($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    $this->connection->executeStatement(
                        sprintf(
                            'UPDATE `%s` SET `%s` = REPLACE(`%s`, ?, ?) WHERE INSTR(`%s`, ?) > 0',
                            $table,
                            $column,
                            $column,
                            $column
                        ),
                        [self::BLACKBIT_NS, self::NEW_NS, self::BLACKBIT_NS]
                    );
                }
            }

            if ($this->liveTableExists('documents') && $this->liveColumnExists('documents', 'controller')) {
                $this->connection->executeStatement(
                    'UPDATE documents SET controller = REPLACE(controller, ?, ?) WHERE INSTR(controller, ?) > 0',
                    [self::BLACKBIT_NS, self::NEW_NS, self::BLACKBIT_NS]
                );
            }
        } catch (\Throwable $e) {
            $this->write('Class-name rewrite: skipped (' . $e->getMessage() . ').');
        }
    }

    private function portBundleInstallFlag(): void
    {
        if (!$this->liveTableExists('settings_store')) {
            return;
        }

        try {
            $fromId = 'BUNDLE_INSTALLED__' . self::BLACKBIT_BUNDLE;
            $toId = 'BUNDLE_INSTALLED__' . self::NEW_BUNDLE;

            $entries = $this->connection->fetchAllAssociative(
                'SELECT id, scope, data, type FROM settings_store WHERE id = ?',
                [$fromId]
            );

            if ($entries === []) {
                return;
            }

            foreach ($entries as $entry) {
                $this->connection->executeStatement(
                    'INSERT INTO settings_store (id, scope, data, type)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE data = VALUES(data), type = VALUES(type)',
                    [$toId, $entry['scope'], $entry['data'], $entry['type']]
                );
            }

            $this->connection->executeStatement(
                'DELETE FROM settings_store WHERE id = ?',
                [$fromId]
            );
        } catch (\Throwable $e) {
            $this->write('settings_store bundle flag: skipped (' . $e->getMessage() . ').');
        }
    }

    private function purgeBlackbitMigrationVersions(): void
    {
        if (!$this->liveTableExists('migration_versions')) {
            return;
        }

        try {
            $deleted = (int) $this->connection->executeStatement(
                'DELETE FROM migration_versions WHERE version LIKE ?',
                ['Blackbit%DataDirectorBundle%']
            );
            if ($deleted > 0) {
                $this->write(sprintf('migration_versions: removed %d Blackbit rows.', $deleted));
            }
        } catch (\Throwable $e) {
            $this->write('migration_versions purge: skipped (' . $e->getMessage() . ').');
        }
    }

    private function renameBlackbitVarDirectories(): void
    {
        try {
            if (!defined('OPENDXP_PRIVATE_VAR')) {
                return;
            }

            $legacy = OPENDXP_PRIVATE_VAR . '/bundles/BlackbitDataDirector';
            $target = OPENDXP_PRIVATE_VAR . '/bundles/SylphenDataBridge';
            if (is_dir($legacy) && !is_dir($target)) {
                rename($legacy, $target);
            }

            if (defined('OPENDXP_SYMFONY_CACHE_DIRECTORY')) {
                $legacyCache = OPENDXP_SYMFONY_CACHE_DIRECTORY . '/BlackbitDataDirector';
                $targetCache = OPENDXP_SYMFONY_CACHE_DIRECTORY . '/SylphenDataBridge';
                if (is_dir($legacyCache) && !is_dir($targetCache)) {
                    rename($legacyCache, $targetCache);
                }
            }
        } catch (\Throwable $e) {
            $this->write('var/bundles rename: skipped (' . $e->getMessage() . ').');
        }
    }

    private function renameLeftoverIndexes(): void
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND (
                    INDEX_NAME LIKE ?
                    OR INDEX_NAME LIKE ?
                  )
                GROUP BY TABLE_NAME, INDEX_NAME
                SQL,
                ['%data_director%', '%blackbit_data_bridge%']
            );

            foreach ($rows as $row) {
                $table = (string) $row['table_name'];
                $oldName = (string) $row['index_name'];
                $newName = $this->targetDataBridgeIndexName($oldName);
                if ($newName === null || $newName === $oldName || $this->indexExists($table, $newName)) {
                    continue;
                }

                $this->addSql(sprintf(
                    'ALTER TABLE %s RENAME INDEX %s TO %s',
                    $this->quoteIdent($table),
                    $this->quoteIdent($oldName),
                    $this->quoteIdent($newName)
                ));
            }
        } catch (\Throwable $e) {
            $this->write('Index rename: skipped (' . $e->getMessage() . ').');
        }
    }

    /**
     * Blackbit used vendor-prefixed index names (blackbit_data_director_*); Sylphen expects sylphen_data_bridge_*.
     * Generic data_director_* on core tables become data_bridge_* only.
     */
    private function targetDataBridgeIndexName(string $oldName): ?string
    {
        if (str_starts_with($oldName, 'blackbit_data_director_')) {
            return 'sylphen_data_bridge_' . substr($oldName, strlen('blackbit_data_director_'));
        }

        if (str_starts_with($oldName, 'blackbit_data_bridge_')) {
            return 'sylphen_data_bridge_' . substr($oldName, strlen('blackbit_data_bridge_'));
        }

        if (!str_contains($oldName, 'data_director')) {
            return null;
        }

        return str_replace('data_director', 'data_bridge', $oldName);
    }

    /**
     * Rewrite JSON in settings-store (document types, custom views, perspectives).
     * Queued via addSql so Doctrine -v shows the statements. Bound params keep
     * JSON backslash escaping intact (stored as Blackbit\\DataDirectorBundle).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function settingsStorePayloadReplacements(): array
    {
        return [
            ['Blackbit\\\\DataDirectorBundle', 'Sylphen\\\\DataBridgeBundle'],
            ['DataDirector_ErrorMonitor', 'DataBridge_ErrorMonitor'],
            ['DataDirector_TaggedElements', 'DataBridge_TaggedElements'],
            ['DataDirector_QueueMonitor', 'DataBridge_QueueMonitor'],
            ['pimcore.layout.portlets.DataBridge_', 'opendxp.layout.portlets.DataBridge_'],
            ['pimcore.layout.portlets.', 'opendxp.layout.portlets.'],
            ['/bundles/pimcoreadmin/', '/bundles/opendxpadmin/'],
            ['\\/bundles\\/pimcoreadmin\\/', '\\/bundles\\/opendxpadmin\\/'],
            ['pimcore_nav_icon_', 'opendxp_nav_icon_'],
            ['pimcore_icon_', 'opendxp_icon_'],
        ];
    }

    private function queueSettingsStorePayloadRewrites(): void
    {
        if (!$this->liveTableExists('settings_store')) {
            return;
        }

        $sql = <<<'SQL'
UPDATE settings_store
   SET data = REPLACE(data, ?, ?)
 WHERE scope IN ('opendxp_document_types', 'opendxp_custom_views', 'opendxp_perspectives')
   AND INSTR(data, ?) > 0
SQL;
        foreach ($this->settingsStorePayloadReplacements() as [$from, $to]) {
            $this->addSql($sql, [$from, $to, $from]);
        }

        if ($this->liveColumnExists('users', 'dashboards')) {
            foreach ($this->settingsStorePayloadReplacements() as [$from, $to]) {
                $this->addSql(
                    'UPDATE users SET dashboards = REPLACE(dashboards, ?, ?) WHERE INSTR(dashboards, ?) > 0',
                    [$from, $to, $from]
                );
            }
        }
    }

    /**
     * Blackbit Data Director → Sylphen Data Bridge: remap controllers and
     * ensure a Print Page type uses Web2PrintController (legacy 202307 created
     * printpage "Page" with DocumentController; 202504 renamed it).
     */
    private function ensureDocumentTypes(): void
    {
        $web2print = Web2PrintController::class . '::pageAction';
        $document = DocumentController::class . '::pageAction';

        $types = (new DocType\Listing())->load();
        $printPages = [];
        foreach ($types as $documentType) {
            $changed = false;
            $controller = $documentType->getController();

            if ($controller === self::BLACKBIT_WEB2PRINT) {
                $documentType->setController($web2print);
                $changed = true;
            } elseif ($controller === self::BLACKBIT_DOCUMENT) {
                $documentType->setController($document);
                $changed = true;
            }

            if ($changed) {
                $documentType->save();
            }

            if (
                $documentType->getType() === 'printpage'
                && $documentType->getName() === 'Print Page'
                && $documentType->getController() === $web2print
            ) {
                $printPages[] = $documentType;
            }
        }

        if ($printPages !== []) {
            foreach (array_slice($printPages, 1) as $duplicate) {
                $id = (string) $duplicate->getId();
                $duplicate->delete();
                $this->write('Document types: removed duplicate Print Page ('.$id.').');
            }

            return;
        }

        foreach ($types as $documentType) {
            if (
                $documentType->getType() === 'printpage'
                && $documentType->getName() === 'Page'
                && $documentType->getController() === $document
            ) {
                $documentType->setName('Print Page');
                $documentType->setController($web2print);
                $documentType->save();

                return;
            }
        }

        $documentType = new DocType();
        $documentType->setName('Print Page');
        $documentType->setController($web2print);
        $documentType->setType('printpage');
        $documentType->save();
    }

    public function down(Schema $schema): void
    {
        $tables = [
            Installer::TABLE_IMPORTSTATUS,
            Installer::TABLE_FIELDMAPPING,
            Installer::TABLE_RAWITEMFIELD,
            Installer::TABLE_RAWITEMDATA,
            Installer::TABLE_RAWITEM,
            Installer::TABLE_DATAPORT_RESOURCE,
            Installer::TABLE_FAVORITES,
            Installer::TABLE_IMPORT_IGNORE,
            Installer::TABLE_DATAPORT,
            Installer::TABLE_QUEUE,
            Installer::TABLE_API_KEYS,
            Installer::TABLE_TRANSLATION_CACHE,
            Installer::TABLE_TRANSLATION_GLOSSARY,
        ];
        foreach ($tables as $table) {
            $this->addSql('DROP TABLE IF EXISTS `' . $table . '`');
        }
        PimcoreDbRepository::getInstance()->execute(
            'DELETE FROM `users_permission_definitions` WHERE `key` IN (?, ?)',
            ['plugin_sylphen_data_bridge', 'plugin_sylphen_data_bridge_admin_permission']
        );
    }

    private function liveTableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function liveColumnExists(string $table, string $column): bool
    {
        try {
            $count = $this->connection->fetchOne(
                <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
                SQL,
                [$table, $column]
            );

            return (int) $count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function permissionExists(string $key): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users_permission_definitions WHERE `key` = ?',
            [$key]
        ) > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            SQL,
            [$table, $indexName]
        );

        return (int) $count > 0;
    }

    private function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /*
     * =========================================================================
     * REVIEW ONLY — opendxp-branch control flow. NOT executed.
     * Delete this block (and the REVIEW lines in up()) before merging to main.
     *
     * Mehrwert vs. main was semantic, not extra CREATE SQL:
     *   1. Early return when sylphen_dd_* already exist (idempotent re-run).
     *   2. After RENAME, stop — no permission/label/portlet/settings_store port.
     *   3. RENAME throws if old and new tables both exist (main: skip on error).
     * CREATE TABLE body was the same idea as createTableSql() below (opendxp
     * used addSql without IF NOT EXISTS; main uses IF NOT EXISTS + skip).
     * registerPermissions() ≈ ensurePermissions() below.
     * =========================================================================
     *
     * public function up(Schema $schema): void
     * {
     *     $this->registerPermissions($schema);
     *
     *     if ($this->hasCurrentSchema()) {
     *         $this->write('sylphen_dd_* tables already present — skipping schema step.');
     *         return;
     *     }
     *
     *     if ($this->hasLegacySchema()) {
     *         $this->renameLegacyTables();
     *         $this->write('renamed legacy Blackbit plugin_pim_* tables to sylphen_dd_*.');
     *         return;
     *     }
     *
     *     $this->createGreenfieldTables();
     * }
     *
     * private function hasCurrentSchema(): bool
     * {
     *     return $this->tableExists(Installer::TABLE_DATAPORT);
     * }
     *
     * private function hasLegacySchema(): bool
     * {
     *     foreach (array_keys(Installer::LEGACY_TABLE_RENAMES) as $legacyTable) {
     *         if ($this->tableExists($legacyTable)) {
     *             return true;
     *         }
     *     }
     *     return false;
     * }
     *
     * private function renameLegacyTables(): void
     * {
     *     $renames = [];
     *     foreach (Installer::LEGACY_TABLE_RENAMES as $legacyTable => $newTable) {
     *         if (!$this->tableExists($legacyTable)) {
     *             continue;
     *         }
     *         if ($this->tableExists($newTable)) {
     *             throw new \RuntimeException(sprintf(
     *                 'Cannot rename legacy table `%s`: target table `%s` already exists.',
     *                 $legacyTable,
     *                 $newTable
     *             ));
     *         }
     *         $renames[] = sprintf('`%s` TO `%s`', $legacyTable, $newTable);
     *     }
     *     if ($renames === []) {
     *         return;
     *     }
     *     $this->addSql('RENAME TABLE ' . implode(', ', $renames));
     * }
     *
     * private function tableExists(string $table): bool
     * {
     *     return (bool) $this->connection->fetchOne(
     *         'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
     *         [$table]
     *     );
     * }
     */
}
