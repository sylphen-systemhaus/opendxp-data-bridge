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
 */
class Version20261106120000 extends AbstractMigration
{
    private const FROM_PREFIX = 'Pimcore\\Model\\';
    private const TO_PREFIX = 'OpenDxp\\Model\\';

    public function getDescription(): string
    {
        return 'Rewrite Pimcore\\Model\\ class names to OpenDxp\\Model\\ in dataport configs and fieldmapping calculations';
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
                try {
                    $count = (int) $this->connection->fetchOne(
                        sprintf(
                            'SELECT COUNT(*) FROM `%s` WHERE INSTR(`%s`, ?) > 0',
                            $table,
                            $column
                        ),
                        [self::FROM_PREFIX]
                    );
                } catch (\Throwable $e) {
                    $this->write(sprintf(
                        'Skip `%s`.`%s`: %s',
                        $table,
                        $column,
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
                    [self::FROM_PREFIX, self::TO_PREFIX, self::FROM_PREFIX]
                );

                $this->write(sprintf(
                    'Queued rewrite of %d row(s) in `%s`.`%s`: Pimcore\\Model\\ → OpenDxp\\Model\\.',
                    $count,
                    $table,
                    $column
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        unset($schema);

        $tables = [
            Installer::TABLE_DATAPORT => ['sourceconfig', 'targetconfig'],
            Installer::TABLE_FIELDMAPPING => ['calculation'],
        ];

        foreach ($tables as $table => $columns) {
            if (!$this->liveTableExists($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $this->addSql(
                    sprintf(
                        'UPDATE `%s` SET `%s` = REPLACE(`%s`, ?, ?) WHERE INSTR(`%s`, ?) > 0',
                        $table,
                        $column,
                        $column,
                        $column
                    ),
                    [self::TO_PREFIX, self::FROM_PREFIX, self::TO_PREFIX]
                );
            }
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
