# Upgrade notes

Install (`opendxp:bundle:install SylphenDataBridgeBundle`) runs `Version00000001` for both a greenfield OpenDXP and a Blackbit Data Director database. Most port steps skip on missing tables or errors. **Perspective portlet rewrite is mandatory:** leftover `DataDirector_*` types abort the migration if they cannot be written.

**Prerequisite for the Blackbit path:** the source database must already be fully migrated to **Blackbit Data Director 3.10.4** (i.e. every migration in that version's chain has run) before installing this bundle. The baseline migration renames `plugin_pim_*` → `sylphen_dd_*` in a single step and does **not** apply any incremental `ALTER TABLE` afterwards — it assumes the renamed tables already have the 3.10.4 column set. A database on an older Blackbit schema version will end up with columns silently missing after the rename (no error, no abort). Run the source installation's own migrations to 3.10.4 first, or take a fresh export/import via the dataport JSON path instead of an in-place DB upgrade.

## What the baseline migration does

**Greenfield:** `CREATE TABLE IF NOT EXISTS` for `sylphen_dd_*`, permission keys `plugin_sylphen_data_bridge*`, category `Data Bridge`.

**Blackbit Data Director:** same baseline, plus:

- `RENAME TABLE` `plugin_pim_*` → `sylphen_dd_*`, `translations_DataDirector_*` → `sylphen_dd_translation_*` (one statement, FK-safe). If rename fails, the old table is left in place and empty `sylphen_dd_*` copies are **not** created.
- Permissions `plugin_bb_pim` / `plugin_bb_pim_admin_permission` → `plugin_sylphen_data_bridge*` (definitions, `users.permissions` / `roles`, admin translation keys).
- Tag root `Data Director` (`idPath = '/'`) → `Data Bridge`; website setting `Data Director Logs Minimum Free Disk Space`; permission category.
- `Blackbit\DataDirectorBundle` → `Sylphen\DataBridgeBundle` in dataport JSON, field-mapping calculations, and `documents.controller`.
- `settings_store` bundle-installed flag; `var/bundles/BlackbitDataDirector` → `SylphenDataBridge`.
- Blackbit rows in `migration_versions` (prefix `Blackbit\DataDirectorBundle`) are removed so they do not collide with this baseline.
- Perspective portlet types `DataDirector_*` / `pimcore.layout.portlets.*` → `opendxp.layout.portlets.DataBridge_*` in writable YAML and `users.dashboards`. **Fails the migration** if a leftover type cannot be rewritten (read-only `var/config/perspectives/`). Fix write access and re-run; the rewrite is idempotent.
- Index names containing `data_director` → `data_bridge` via `RENAME INDEX` when the server supports it.

Document types (Blackbit controllers → Sylphen) are remapped in the same migration; failure there does not roll back schema.

Runtime: no JS aliases for old portlet class names. `createPerspective()` treats only `opendxp.layout.portlets.DataBridge_ErrorMonitor` as generated PIM. Custom-view IDs `dd_*` stay. Areabrick IDs `dataDirector*` stay. Class-definition service ids `@DataDirector*` stay (Symfony aliases).

## Optional: retry leftover `data_director_*` indexes

The baseline already tries `ALTER TABLE … RENAME INDEX`. Remaining names are leftovers on servers that cannot rename in place (older than MySQL 5.7 / MariaDB 10.5.2). Do not rewrite historical migration files.

The pattern follows [OpenDXP version file migration](https://docs.opendxp.io/docs/core-framework/Installation_and_Upgrade/Upgrade_Notes/Version_Migration): copy the class into `src/Command/` in a default Symfony folder structure. You might need to adjust it to your needs.

Unlike that OpenDXP command, this one is a **dry-run by default**. Pass `--execute` to apply.

```bash
bin/console app:rename-data-bridge-indexes
bin/console app:rename-data-bridge-indexes --execute
```

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rename-data-bridge-indexes',
    description: 'Renames leftover data_director_* indexes to data_bridge_* (RENAME INDEX only)',
)]
class RenameDataBridgeIndexesCommand extends Command
{
    private const SEARCH = 'data_director';
    private const REPLACE = 'data_bridge';

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Apply ALTER TABLE … RENAME INDEX. Without this flag nothing is changed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');

        if (!$this->supportsRenameIndex($io)) {
            return Command::FAILURE;
        }

        if (!$execute) {
            $io->note('Dry-run – no indexes will actually be renamed. Pass --execute to apply.');
        }

        $io->title('Rename indexes: data_director → data_bridge');

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND INDEX_NAME LIKE ?
            GROUP BY TABLE_NAME, INDEX_NAME
            ORDER BY TABLE_NAME, INDEX_NAME
            SQL,
            ['%'.self::SEARCH.'%'],
        );

        $total = \count($rows);
        $renamed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $oldName = (string) $row['index_name'];
            $newName = str_replace(self::SEARCH, self::REPLACE, $oldName);

            if ($newName === $oldName) {
                $skipped++;
                if ($io->isVeryVerbose()) {
                    $io->text(sprintf('  skipped (name unchanged): %s.%s', $table, $oldName));
                }
                continue;
            }

            if (!$this->indexExists($table, $oldName)) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->text(sprintf('  skipped (source missing): %s.%s', $table, $oldName));
                }
                continue;
            }

            if ($this->indexExists($table, $newName)) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->text(sprintf('  skipped (target exists): %s.%s → %s', $table, $oldName, $newName));
                }
                continue;
            }

            $sql = sprintf(
                'ALTER TABLE %s RENAME INDEX %s TO %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($oldName),
                $this->quoteIdentifier($newName),
            );

            if ($io->isVerbose() || !$execute) {
                $io->text(($execute ? '  rename: ' : '  would rename: ').$sql);
            }

            if (!$execute) {
                $renamed++;
                continue;
            }

            try {
                $this->connection->executeStatement($sql);
                $renamed++;
            } catch (\Throwable $e) {
                $io->error(sprintf('Error at %s.%s: %s', $table, $oldName, $e->getMessage()));
                $errors++;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Indexes found' => $total],
            [$execute ? 'Renamed' : 'Would rename' => $renamed],
            ['Skipped (missing source, target exists, or unchanged)' => $skipped],
            ['Error' => $errors],
        );

        if (!$execute && $renamed > 0) {
            $io->warning(sprintf(
                'Dry-run: %d indexes would be renamed. Execute with --execute to make the changes.',
                $renamed,
            ));
        }

        if ($errors > 0) {
            $io->warning('There have been errors - see above.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d of %d indexes successfully %s.',
            $renamed,
            $total,
            $execute ? 'renamed' : 'recognized (dry-run)',
        ));

        return Command::SUCCESS;
    }

    private function supportsRenameIndex(SymfonyStyle $io): bool
    {
        $version = (string) $this->connection->fetchOne('SELECT VERSION()');
        $isMariaDb = stripos($version, 'MariaDB') !== false;

        // MariaDB handshake spoof: optional dummy "5.5.5-" (MySQL replication).
        // Only that exact prefix is stripped. A real 5.5.4-MariaDB stays 5.5.4 and fails 10.5.2.
        $pattern = $isMariaDb
            ? '/^(?:5\.5\.5-)?(?:mariadb-)?(?P<semver>\d+\.\d+\.\d+)/i'
            : '/^(?P<semver>\d+\.\d+\.\d+)/';

        if (preg_match($pattern, $version, $m) !== 1) {
            $io->error(sprintf(
                'Could not parse server version "%s". Refusing DROP INDEX / ADD INDEX.',
                $version,
            ));

            return false;
        }

        $semver = $m['semver'];
        $minimum = $isMariaDb ? '10.5.2' : '5.7.0';
        $product = $isMariaDb ? 'MariaDB 10.5.2+' : 'MySQL 5.7+';

        if (version_compare($semver, $minimum, '<')) {
            $io->error(sprintf(
                'RENAME INDEX requires %s. This server reports "%s" (parsed %s). Refusing DROP INDEX / ADD INDEX.',
                $product,
                $version,
                $semver,
            ));

            return false;
        }

        return true;
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
            [$table, $indexName],
        );

        return (int) $count > 0;
    }

    private function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
```

`str_replace('data_director', 'data_bridge', …)` covers `data_director_*`, `idx_data_director_*`, and `sylphen_data_director_*`. Existing target names and missing sources are skipped.

## Post-migration: Process Manager supervisord

If the OpenDXP stack runs `process-manager:maintenance` via supervisord (or a similar process supervisor), **stop it before starting the DB migration** and restart only after all bundle installs and smoke tests pass:

```bash
supervisorctl stop processmanager
# … run migration steps …
supervisorctl start processmanager
```

While bundles are not yet installed, the PM maintenance loop creates a new failed monitoring item every ~30 seconds. These are harmless but produce noisy "considered as dead process" log entries and pile up in `bundle_process_manager_monitoring_item`. If they already exist, clean up with:

```sql
DELETE FROM bundle_process_manager_monitoring_item WHERE status = 'failed';
```

## Version tooltip

The OpenDXP admin footer tooltip shows `Data Bridge: <version>`. The version is read from `composer.json` → `InstalledVersions::getPrettyVersion()`. Bump the `"version"` field in `packages/opendxp-data-bridge/composer.json` and run `composer dump-autoload` to update it.
