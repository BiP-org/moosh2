<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Archive;

use Moosh2\Application;
use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Command\Sql\DbConnectionTrait;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * archive:restore implementation for Moodle 5.2.
 */
class ArchiveRestore52Handler extends BaseHandler
{
    use DbConnectionTrait;
    use ArchivePathsTrait;

    public function getBootstrapLevel(): ?BootstrapLevel
    {
        return BootstrapLevel::Config;
    }

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument(
                'archive',
                InputArgument::REQUIRED,
                'Path to the .tar.gz produced by archive:dump',
            )
            ->addOption('code', null, InputOption::VALUE_NONE, 'Restore only the codebase')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Restore only the dataroot files')
            ->addOption('db', null, InputOption::VALUE_NONE, 'Restore only the database')
            ->addOption(
                'code-destination',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the destination directory for the codebase (default: detected from $CFG->dirroot)',
            )
            ->addOption(
                'files-destination',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the destination directory for the dataroot (default: $CFG->dataroot)',
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Permit overwriting existing code/files destinations',
            );

        $command->addExampleUsage('Dry-run a full restore', 'backup.tar.gz');
        $command->addExampleUsage('Full restore (code + files + database)', '--run backup.tar.gz');
        $command->addExampleUsage('Restore only the database', '--db --run backup.tar.gz');
        $command->addExampleUsage('Restore only the dataroot files', '--files --run backup.tar.gz');
        $command->addExampleUsage('Restore code into a different path', '--code --code-destination=/tmp/restored --run backup.tar.gz');
        $command->addExampleUsage('Allow overwriting existing destinations', '--overwrite --run backup.tar.gz');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);

        $archive = $this->resolveAbsolutePath($input->getArgument('archive'));
        if (!file_exists($archive)) {
            $output->writeln("<error>Archive not found: $archive</error>");
            return Command::FAILURE;
        }
        if (!is_readable($archive)) {
            $output->writeln("<error>Archive not readable: $archive</error>");
            return Command::FAILURE;
        }

        $codeFlag = (bool) $input->getOption('code');
        $filesFlag = (bool) $input->getOption('files');
        $dbFlag = (bool) $input->getOption('db');
        $anySelected = $codeFlag || $filesFlag || $dbFlag;
        $wantCode = $anySelected ? $codeFlag : true;
        $wantFiles = $anySelected ? $filesFlag : true;
        $wantDb = $anySelected ? $dbFlag : true;

        $verbose->step('Inspecting archive contents');
        $entries = $this->listArchiveEntries($archive, $output);
        if ($entries === null) {
            return Command::FAILURE;
        }

        $hasCode = $this->archiveHasPath($entries, 'code/');
        $hasFiles = $this->archiveHasPath($entries, 'files/');
        $hasDb = in_array('database.sql', $entries, true);

        if ($wantCode && !$hasCode) {
            $output->writeln('<error>Archive has no code/ — cannot restore code.</error>');
            return Command::FAILURE;
        }
        if ($wantFiles && !$hasFiles) {
            $output->writeln('<error>Archive has no files/ — cannot restore files.</error>');
            return Command::FAILURE;
        }
        if ($wantDb && !$hasDb) {
            $output->writeln('<error>Archive has no database.sql — cannot restore database.</error>');
            return Command::FAILURE;
        }

        $doCode = $wantCode && $hasCode;
        $doFiles = $wantFiles && $hasFiles;
        $doDb = $wantDb && $hasDb;

        if (!$doCode && !$doFiles && !$doDb) {
            $output->writeln('<error>Nothing selected to restore.</error>');
            return Command::FAILURE;
        }

        try {
            $dbType = $this->getDbType();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $codeDest = $input->getOption('code-destination');
        if ($codeDest !== null) {
            $codeDest = $this->resolveAbsolutePath($codeDest);
        } elseif ($doCode) {
            $codeDest = $this->resolveCodeSourceDir();
        }

        $filesDest = $input->getOption('files-destination');
        if ($filesDest !== null) {
            $filesDest = $this->resolveAbsolutePath($filesDest);
        } elseif ($doFiles) {
            $filesDest = $this->resolveDataSourceDir();
        }

        $overwrite = (bool) $input->getOption('overwrite');
        $runMode = (bool) $input->getOption('run');

        $verbose->section('Restore plan');
        $verbose->detail('Archive', $archive);
        $verbose->detail('Restore code', $doCode ? 'yes' : 'no');
        $verbose->detail('Restore files', $doFiles ? 'yes' : 'no');
        $verbose->detail('Restore database', $doDb ? 'yes' : 'no');
        if ($doCode) {
            $verbose->detail('Code destination', $codeDest);
        }
        if ($doFiles) {
            $verbose->detail('Files destination', $filesDest);
        }
        if ($doDb) {
            $verbose->detail('Database driver', $dbType);
            $verbose->detail('Database name', $this->getDbName());
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — would restore from archive (use --run to execute):</info>');
            $output->writeln("  Archive:           $archive");
            $output->writeln('  Restore code:      ' . ($doCode ? 'yes' : 'no'));
            if ($doCode) {
                $output->writeln("    Destination:     $codeDest");
            }
            $output->writeln('  Restore files:     ' . ($doFiles ? 'yes' : 'no'));
            if ($doFiles) {
                $output->writeln("    Destination:     $filesDest");
            }
            $output->writeln('  Restore database:  ' . ($doDb ? 'yes' : 'no'));
            if ($doDb) {
                $output->writeln('    Driver:          ' . $dbType);
                $output->writeln('    Database:        ' . $this->getDbName());
            }
            return Command::SUCCESS;
        }

        if ($doCode && is_dir($codeDest) && !$this->isEmptyDir($codeDest) && !$overwrite) {
            $output->writeln(sprintf(
                '<error>Code destination is non-empty: %s. Use --overwrite to replace.</error>',
                $codeDest,
            ));
            return Command::FAILURE;
        }
        if ($doFiles && is_dir($filesDest) && !$this->isEmptyDir($filesDest) && !$overwrite) {
            $output->writeln(sprintf(
                '<error>Files destination is non-empty: %s. Use --overwrite to replace.</error>',
                $filesDest,
            ));
            return Command::FAILURE;
        }

        if ($doCode) {
            $verbose->step('Restoring code/ to ' . $codeDest);
            if ($this->extractTreeFromArchive($archive, 'code', $codeDest, $output, $verbose) !== 0) {
                return Command::FAILURE;
            }
        }

        if ($doFiles) {
            $verbose->step('Restoring files/ to ' . $filesDest);
            if ($this->extractTreeFromArchive($archive, 'files', $filesDest, $output, $verbose) !== 0) {
                return Command::FAILURE;
            }
        }

        if ($doDb) {
            $verbose->step('Restoring database');
            if ($this->restoreDatabase($archive, $dbType, $output, $verbose) !== 0) {
                return Command::FAILURE;
            }
        }

        $verbose->done('Archive restore complete');
        $output->writeln('Restored from ' . $archive);

        return Command::SUCCESS;
    }

    /**
     * Resolve a path against the user's original cwd, since Moodle bootstrap
     * may have changed the current working directory.
     */
    private function resolveAbsolutePath(string $path): string
    {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }

        return rtrim(Application::getOriginalCwd(), '/') . '/' . $path;
    }

    private function listArchiveEntries(string $archive, OutputInterface $output): ?array
    {
        $cmd = 'tar -tzf ' . escapeshellarg($archive);
        $entries = [];
        $rc = 0;

        exec($cmd . ' 2>&1', $entries, $rc);

        if ($rc !== 0) {
            $output->writeln('<error>Failed to read archive contents (tar rc=' . $rc . ')</error>');
            foreach ($entries as $line) {
                $output->writeln('  ' . $line);
            }
            return null;
        }

        return $entries;
    }

    private function archiveHasPath(array $entries, string $prefix): bool
    {
        foreach ($entries as $entry) {
            if (str_starts_with($entry, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract everything under "$prefix/" from the archive into $destination
     * (replacing $destination's contents). Strips the prefix component.
     */
    private function extractTreeFromArchive(
        string $archive,
        string $prefix,
        string $destination,
        OutputInterface $output,
        VerboseLogger $verbose,
    ): int {
        if (file_exists($destination) && !is_dir($destination)) {
            $output->writeln("<error>Destination exists but is not a directory: $destination</error>");
            return 1;
        }

        if (is_dir($destination)) {
            $verbose->info('Clearing existing destination: ' . $destination);
            if (!$this->emptyDirectory($destination, $output)) {
                return 1;
            }
        } else {
            if (!@mkdir($destination, 0755, true) && !is_dir($destination)) {
                $output->writeln("<error>Could not create destination: $destination</error>");
                return 1;
            }
        }

        $cmd = sprintf(
            'tar -xzf %s -C %s --strip-components=1 %s',
            escapeshellarg($archive),
            escapeshellarg($destination),
            escapeshellarg($prefix),
        );
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>tar failed extracting $prefix/ (exit code $rc)</error>");
        }

        return $rc;
    }

    private function restoreDatabase(
        string $archive,
        string $dbType,
        OutputInterface $output,
        VerboseLogger $verbose,
    ): int {
        global $CFG;

        if ($dbType === 'pgsql') {
            $this->setPgPassword();
            $cli = $this->getPgsqlCliCommand();
            // pg_dump's --clean --if-exists handles existing objects.
        } else {
            $cli = $this->getMysqlCliCommand();
        }

        // Pipe SQL dump from inside the archive directly to the DB client.
        $cmd = sprintf(
            'tar -xzOf %s %s | %s',
            escapeshellarg($archive),
            escapeshellarg('database.sql'),
            $cli,
        );
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>Database restore failed (exit code $rc)</error>");
        }

        return $rc;
    }

    private function getDbName(): string
    {
        global $CFG;
        return $CFG->dbname ?? '(unknown)';
    }

    private function isEmptyDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $iter = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        return !$iter->valid();
    }

    /**
     * Empty $dir without removing $dir itself. Used so we can extract on top
     * of a path that may be referenced by config (e.g. $CFG->dataroot) and
     * preserve the directory's owner/permissions.
     */
    private function emptyDirectory(string $dir, OutputInterface $output): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!@rmdir($item->getPathname())) {
                    $output->writeln("<error>Could not remove directory: {$item->getPathname()}</error>");
                    return false;
                }
            } elseif (!@unlink($item->getPathname())) {
                $output->writeln("<error>Could not remove: {$item->getPathname()}</error>");
                return false;
            }
        }

        return true;
    }
}
