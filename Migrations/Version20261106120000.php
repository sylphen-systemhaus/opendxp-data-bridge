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
 * Asset/document dataports store PHP class names in targetconfig / sourceconfig.
 * Data Director wrote Pimcore\Model\…; OpenDXP uses OpenDxp\Model\….
 * Version00000001 only rewrote Blackbit\DataDirectorBundle → Sylphen\DataBridgeBundle.
 *
 * JSON columns store doubled backslashes (Pimcore\\Model\\); plain calculation text
 * may use a single backslash. Rewrite both forms (same pattern as
 * Version00000001::settingsStorePayloadReplacements).
 */
class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewrite Pimcore\\Model\\ class names to OpenDxp\\Model\\ in dataport configs and fieldmapping calculations';
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function replacements(): array
    {
        return [
            // JSON-escaped form first (sourceconfig / targetconfig TEXT with encoded \)
            ['Pimcore\\\\Model\\\\', 'OpenDxp\\\\Model\\\\', 'JSON-escaped'],
            // Single-backslash form (calculation / legacy plain text)
            ['Pimcore\\Model\\', 'OpenDxp\\Model\\', 'plain'],
        ];
    }

    public function up(Schema $schema): void
    {
        unset($schema);

        $tables = [
            Installer::TABLE_DATAPORT => ['sourceconfig', 'targetconfig'],
            Installer::TABLE_FIELDMAPPING => ['calculation'],
        ];

        foreach ($tables as $table => $columns) {
            if (!$this->liveTableExists($table)) {
                $this->write(sprintf('Table `%s` missing — skipping Pimcore\\Model\\ rewrite.', $table));
                continue;
            }

            foreach ($columns as $column) {
                foreach ($this->replacements() as [$from, $to, $label]) {
                    try {
                        $count = (int) $this->connection->fetchOne(
                            sprintf(
                                'SELECT COUNT(*) FROM `%s` WHERE INSTR(`%s`, ?) > 0',
                                $table,
                                $column
                            ),
                            [$from]
                        );
                    } catch (\Throwable $e) {
                        $this->write(sprintf(
                            'Skip `%s`.`%s` (%s): %s',
                            $table,
                            $column,
                            $label,
                            $e->getMessage()
                        ));
                        continue;
                    }

                    if ($count === 0) {
                        continue;
                    }

                    $this->addSql(
                        sprintf(
                            'UPDATE `%s` SET `%s` = REPLACE(`%s`, ?, ?) WHERE INSTR(`%s`, ?) > 0',
                            $table,
                            $column,
                            $column,
                            $column
                        ),
                        [$from, $to, $from]
                    );

                    $this->write(sprintf(
                        'Queued rewrite of %d row(s) in `%s`.`%s` (%s): Pimcore\\Model\\ → OpenDxp\\Model\\.',
                        $count,
                        $table,
                        $column,
                        $label
                    ));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        unset($schema);
        // No-op: cannot tell migrated Pimcore→OpenDxp rows from dataports that were
        // created on OpenDXP with OpenDxp\Model\ already. A blind reverse REPLACE
        // would break those native configs.
        $this->write('down(): no-op — refusing to rewrite OpenDxp\\Model\\ back to Pimcore\\Model\\.');
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
