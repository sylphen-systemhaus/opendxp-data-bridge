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
use Sylphen\DataBridgeBundle\Tools\Installer;

/**
 * OpenDXP >= 1.4.2 rejects Data::setName() values that are not identifiers.
 * Rewrite built-in virtual mapping field names that previously contained spaces.
 */
class Version00000002 extends AbstractMigration
{
    /** @var array<string, string> legacy fieldName => identifier-safe fieldName */
    private const FIELD_NAME_RENAMES = [
        'delete element' => 'deleteElement',
        'Complete Object' => 'completeObject',
        'Complete Document' => 'completeDocument',
    ];

    public function getDescription(): string
    {
        return 'Rename virtual fieldmapping fieldName values to identifier-safe names (OpenDXP >= 1.4.2)';
    }

    public function up(Schema $schema): void
    {
        unset($schema);

        $table = Installer::TABLE_FIELDMAPPING;
        if (!$this->liveTableExists($table)) {
            $this->write(sprintf('Table `%s` missing — skipping virtual fieldName rewrite.', $table));

            return;
        }

        foreach (self::FIELD_NAME_RENAMES as $from => $to) {
            $updated = (int) $this->connection->executeStatement(
                sprintf(
                    'UPDATE `%s` SET `fieldName` = ? WHERE `fieldName` = ?',
                    $table
                ),
                [$to, $from]
            );
            if ($updated > 0) {
                $this->write(sprintf('Rewrote %d row(s): "%s" → "%s".', $updated, $from, $to));
            }
        }
    }

    public function down(Schema $schema): void
    {
        unset($schema);

        $table = Installer::TABLE_FIELDMAPPING;
        if (!$this->liveTableExists($table)) {
            return;
        }

        foreach (self::FIELD_NAME_RENAMES as $from => $to) {
            $this->connection->executeStatement(
                sprintf(
                    'UPDATE `%s` SET `fieldName` = ? WHERE `fieldName` = ?',
                    $table
                ),
                [$from, $to]
            );
        }
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
