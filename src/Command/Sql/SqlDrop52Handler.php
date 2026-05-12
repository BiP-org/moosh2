<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Sql;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * sql:drop implementation for Moodle 5.2.
 *
 * Enumerates every table in the configured database and drops them one by
 * one. Works for MySQL/MariaDB and PostgreSQL. Foreign keys are handled per
 * engine: MySQL temporarily disables FOREIGN_KEY_CHECKS for the session;
 * PostgreSQL uses DROP TABLE ... CASCADE.
 */
class SqlDrop52Handler extends BaseHandler
{
    use DbConnectionTrait;

    public function getBootstrapLevel(): ?BootstrapLevel
    {
        return BootstrapLevel::DbOnly;
    }

    public function configureCommand(Command $command): void
    {
        $command->addOption(
            'exclude',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma-separated full table names (with prefix) to keep, e.g. mdl_user,mdl_config',
        );

        $command->addExampleUsage('Show which tables would be dropped (dry run)', '');
        $command->addExampleUsage('Drop every table in the database', '--run');
        $command->addExampleUsage('Drop everything except mdl_config', '--exclude=mdl_config --run');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);
        $runMode = (bool) $input->getOption('run');
        $excludeOpt = $input->getOption('exclude');

        try {
            $dbType = $this->getDbType();
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $exclude = $this->parseExclude($excludeOpt);

        $verbose->step('Listing tables in the database');
        try {
            $allTables = $this->listAllTables($dbType);
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to list tables: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $tablesToDrop = array_values(array_diff($allTables, $exclude));

        $verbose->detail('Tables in database', (string) count($allTables));
        $verbose->detail('Excluded', (string) count(array_intersect($allTables, $exclude)));
        $verbose->detail('To drop', (string) count($tablesToDrop));

        if ($tablesToDrop === []) {
            $output->writeln('No tables to drop.');
            return Command::SUCCESS;
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — the following ' . count($tablesToDrop) . ' table(s) would be dropped (use --run to execute):</info>');
            foreach ($tablesToDrop as $table) {
                $output->writeln('  ' . $table);
            }
            if ($exclude !== []) {
                $missing = array_diff($exclude, $allTables);
                if ($missing !== []) {
                    $output->writeln('<comment>Note: excluded tables not present in the database: ' . implode(', ', $missing) . '</comment>');
                }
            }
            return Command::SUCCESS;
        }

        return $this->dropTables($dbType, $tablesToDrop, $verbose, $output);
    }

    /**
     * Parse the --exclude option into a list of trimmed table names.
     *
     * @return string[]
     */
    private function parseExclude(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', $value) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Return every table name in the current database/schema.
     *
     * Bypasses Moodle's prefix-filtered $DB->get_tables() so the result
     * truly mirrors what a DROP DATABASE would remove.
     *
     * @return string[]
     */
    private function listAllTables(string $dbType): array
    {
        global $DB;

        if ($dbType === 'pgsql') {
            $sql = "SELECT tablename FROM pg_catalog.pg_tables "
                . "WHERE schemaname = ANY (current_schemas(false)) "
                . "ORDER BY tablename";
        } else {
            $sql = 'SHOW TABLES';
        }

        $records = $DB->get_records_sql($sql);

        $tables = [];
        foreach ($records as $record) {
            $row = (array) $record;
            $tables[] = (string) reset($row);
        }

        sort($tables);
        return $tables;
    }

    /**
     * @param string[] $tables
     */
    private function dropTables(
        string $dbType,
        array $tables,
        VerboseLogger $verbose,
        OutputInterface $output,
    ): int {
        $verbose->step('Dropping ' . count($tables) . ' table(s)');

        if ($dbType === 'pgsql') {
            return $this->dropTablesPgsql($tables, $verbose, $output);
        }

        return $this->dropTablesMysql($tables, $verbose, $output);
    }

    /**
     * @param string[] $tables
     */
    private function dropTablesMysql(array $tables, VerboseLogger $verbose, OutputInterface $output): int
    {
        global $DB;

        $verbose->info('Disabling FOREIGN_KEY_CHECKS for the session');
        $DB->execute('SET FOREIGN_KEY_CHECKS = 0');

        $dropped = 0;
        $failures = [];

        try {
            foreach ($tables as $table) {
                $sql = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`';
                $verbose->info($sql);
                try {
                    $DB->execute($sql);
                    $dropped++;
                } catch (Throwable $e) {
                    $failures[] = $table . ': ' . $e->getMessage();
                }
            }
        } finally {
            try {
                $DB->execute('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
                // Session-scoped flag; if the connection is gone we don't care.
            }
        }

        return $this->reportResult($dropped, $failures, $output);
    }

    /**
     * @param string[] $tables
     */
    private function dropTablesPgsql(array $tables, VerboseLogger $verbose, OutputInterface $output): int
    {
        global $DB;

        $dropped = 0;
        $failures = [];

        foreach ($tables as $table) {
            $sql = 'DROP TABLE IF EXISTS "' . str_replace('"', '""', $table) . '" CASCADE';
            $verbose->info($sql);
            try {
                $DB->execute($sql);
                $dropped++;
            } catch (Throwable $e) {
                $failures[] = $table . ': ' . $e->getMessage();
            }
        }

        return $this->reportResult($dropped, $failures, $output);
    }

    /**
     * @param string[] $failures
     */
    private function reportResult(int $dropped, array $failures, OutputInterface $output): int
    {
        $output->writeln("Dropped $dropped table(s).");

        if ($failures !== []) {
            $output->writeln('<error>Failed to drop ' . count($failures) . ' table(s):</error>');
            foreach ($failures as $line) {
                $output->writeln('  ' . $line);
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
