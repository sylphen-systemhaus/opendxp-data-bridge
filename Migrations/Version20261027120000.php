<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Sylphen\DataBridgeBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Sylphen\DataBridgeBundle\lib\Pim\Item\Importer;
use Sylphen\DataBridgeBundle\Tools\Installer;

/**
 * OpenDXP >= 1.4.2 rejects Data::setName() values that are not identifiers.
 * Rewrite built-in virtual mapping field names that previously contained spaces
 * to `__`-prefixed names outside the class-field namespace.
 *
 * Timestamped class name avoids collisions with local hotfix migrations named
 * Version00000002 (Doctrine identifies migrations by class name).
 */
class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename virtual fieldmapping fieldName values to identifier-safe __-prefixed names (OpenDXP >= 1.4.2)';
    }

    public function up(Schema $schema): void
    {
        unset($schema);

        $table = Installer::TABLE_FIELDMAPPING;
        if (!$this->liveTableExists($table)) {
            $this->write(sprintf('Table `%s` missing — skipping virtual fieldName rewrite.', $table));

            return;
        }

        $this->warnLegacyNamesInCalculations($table);

        foreach (Importer::VIRTUAL_FIELD_NAME_ALIASES as $from => $to) {
            $count = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM `%s` WHERE `fieldName` = ?', $table),
                [$from]
            );
            if ($count === 0) {
                continue;
            }

            // Drop legacy rows that would collide with an existing target PK row.
            $this->addSql(sprintf(
                'DELETE `legacy` FROM `%s` AS `legacy`
                 INNER JOIN `%s` AS `target`
                    ON `legacy`.`dataportId` = `target`.`dataportId`
                   AND `legacy`.`locale` = `target`.`locale`
                   AND `legacy`.`brickName` = `target`.`brickName`
                   AND `legacy`.`fieldName` = %s
                   AND `target`.`fieldName` = %s',
                $table,
                $table,
                $this->connection->quote($from),
                $this->connection->quote($to)
            ));

            $this->addSql(sprintf(
                'UPDATE `%s` SET `fieldName` = %s WHERE `fieldName` = %s',
                $table,
                $this->connection->quote($to),
                $this->connection->quote($from)
            ));

            $this->write(sprintf('Queued rewrite of %d row(s): "%s" → "%s".', $count, $from, $to));
        }
    }

    public function down(Schema $schema): void
    {
        unset($schema);

        $table = Installer::TABLE_FIELDMAPPING;
        if (!$this->liveTableExists($table)) {
            return;
        }

        $reverse = [
            Importer::VIRTUAL_FIELD_DELETE_ELEMENT => 'delete element',
            Importer::VIRTUAL_FIELD_COMPLETE_OBJECT => 'Complete Object',
            Importer::VIRTUAL_FIELD_COMPLETE_DOCUMENT => 'Complete Document',
        ];

        foreach ($reverse as $from => $to) {
            $this->addSql(sprintf(
                'DELETE `legacy` FROM `%s` AS `legacy`
                 INNER JOIN `%s` AS `target`
                    ON `legacy`.`dataportId` = `target`.`dataportId`
                   AND `legacy`.`locale` = `target`.`locale`
                   AND `legacy`.`brickName` = `target`.`brickName`
                   AND `legacy`.`fieldName` = %s
                   AND `target`.`fieldName` = %s',
                $table,
                $table,
                $this->connection->quote($from),
                $this->connection->quote($to)
            ));

            $this->addSql(sprintf(
                'UPDATE `%s` SET `fieldName` = %s WHERE `fieldName` = %s',
                $table,
                $this->connection->quote($to),
                $this->connection->quote($from)
            ));
        }
    }

    private function warnLegacyNamesInCalculations(string $table): void
    {
        $legacyNames = array_keys(Importer::VIRTUAL_FIELD_NAME_ALIASES);
        $conditions = [];
        $params = [];
        foreach ($legacyNames as $name) {
            $conditions[] = '`calculation` LIKE ?';
            $params[] = '%' . $this->escapeLike($name) . '%';
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT DISTINCT `dataportId` FROM `%s` WHERE `calculation` IS NOT NULL AND (%s) ORDER BY `dataportId`',
                    $table,
                    implode(' OR ', $conditions)
                ),
                $params
            );
        } catch (\Throwable $e) {
            $this->write('Could not scan calculations for legacy virtual field names: ' . $e->getMessage());

            return;
        }

        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn(array $row): string => (string) $row['dataportId'], $rows);
        $this->write(sprintf(
            'WARNING: dataport(s) %s have callback `calculation` text referencing a legacy virtual field name'
            . ' (e.g. {{ Complete Object }}). After this migration those callbacks resolve to null'
            . ' and must be updated manually to use %s / %s / %s.',
            implode(', ', $ids),
            Importer::VIRTUAL_FIELD_DELETE_ELEMENT,
            Importer::VIRTUAL_FIELD_COMPLETE_OBJECT,
            Importer::VIRTUAL_FIELD_COMPLETE_DOCUMENT
        ));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function liveTableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
