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
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseHandler;
use Moosh2\Command\Sql\DbConnectionTrait;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * archive:dump implementation for Moodle 5.2.
 */
class ArchiveDump52Handler extends BaseHandler
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
                'destination',
                InputArgument::REQUIRED,
                'Path to the output .tar.gz file',
            )
            ->addOption('code', null, InputOption::VALUE_NONE, 'Include only the codebase')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Include only the dataroot files')
            ->addOption('db', null, InputOption::VALUE_NONE, 'Include only the database dump')
            ->addOption(
                'description',
                null,
                InputOption::VALUE_REQUIRED,
                'Free-text description recorded in MANIFEST.yml',
            )
            ->addOption(
                'exclude-code-paths',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of code paths (tar patterns) to exclude in addition to defaults',
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Overwrite the destination file if it exists',
            );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);

        $codeFlag = (bool) $input->getOption('code');
        $filesFlag = (bool) $input->getOption('files');
        $dbFlag = (bool) $input->getOption('db');

        $anySelected = $codeFlag || $filesFlag || $dbFlag;
        $includeCode = $anySelected ? $codeFlag : true;
        $includeFiles = $anySelected ? $filesFlag : true;
        $includeDb = $anySelected ? $dbFlag : true;

        $destination = $this->resolveDestination($input->getArgument('destination'));
        $overwrite = (bool) $input->getOption('overwrite');
        $description = $input->getOption('description');
        $extraExcludes = $this->parseCsv($input->getOption('exclude-code-paths'));

        if (file_exists($destination) && !$overwrite) {
            $output->writeln(sprintf(
                '<error>Destination already exists: %s. Use --overwrite to replace it.</error>',
                $destination,
            ));
            return Command::FAILURE;
        }

        $destDir = dirname($destination);
        if (!is_dir($destDir) || !is_writable($destDir)) {
            $output->writeln(sprintf(
                '<error>Destination directory is not writable: %s</error>',
                $destDir,
            ));
            return Command::FAILURE;
        }

        try {
            $dbType = $this->getDbType();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $codeSource = $this->resolveCodeSourceDir();
        $dataSource = $this->resolveDataSourceDir();

        $verbose->section('Archive plan');
        $verbose->detail('Destination', $destination);
        $verbose->detail('Include code', $includeCode ? 'yes' : 'no');
        $verbose->detail('Include files', $includeFiles ? 'yes' : 'no');
        $verbose->detail('Include db', $includeDb ? 'yes' : 'no');
        if ($includeCode) {
            $verbose->detail('Code source', $codeSource);
        }
        if ($includeFiles) {
            $verbose->detail('Files source', $dataSource);
        }

        $staging = $this->createStagingDir($destDir);
        // Temp uncompressed tar lives next to the destination so it's on
        // the same filesystem — large dataroots can produce multi-GB files
        // and /tmp may be too small.
        $tempTar = $destDir . '/.moosh-archive-' . uniqid('', true) . '.tar';

        try {
            if ($includeDb) {
                $verbose->step('Dumping database');
                $dbDumpPath = $staging . '/database.sql';
                if ($this->runDatabaseDump($dbType, $dbDumpPath, $verbose, $output) !== 0) {
                    return Command::FAILURE;
                }
            }

            $verbose->step('Writing MANIFEST.yml');
            $manifestPath = $staging . '/MANIFEST.yml';
            file_put_contents($manifestPath, $this->buildManifest(
                $description,
                $includeCode,
                $includeFiles,
                $includeDb,
                $codeSource,
                $dataSource,
                $dbType,
            ));

            $stagingFiles = ['MANIFEST.yml'];
            if ($includeDb) {
                $stagingFiles[] = 'database.sql';
            }

            $verbose->step('Building tarball');
            if ($this->createBaseTar($tempTar, $staging, $stagingFiles, $output, $verbose) !== 0) {
                return Command::FAILURE;
            }

            if ($includeCode) {
                $verbose->step('Appending code/');
                if ($this->appendDirectoryToTar(
                    $tempTar,
                    $codeSource,
                    'code',
                    array_merge(self::CODE_DEFAULT_EXCLUDES, $extraExcludes),
                    $output,
                    $verbose,
                ) !== 0) {
                    return Command::FAILURE;
                }
            }

            if ($includeFiles) {
                $verbose->step('Appending files/');
                if ($this->appendDirectoryToTar(
                    $tempTar,
                    $dataSource,
                    'files',
                    self::DATAROOT_EXCLUDES,
                    $output,
                    $verbose,
                ) !== 0) {
                    return Command::FAILURE;
                }
            }

            $verbose->step('Compressing tarball to ' . $destination);
            if ($this->gzipFile($tempTar, $destination, $verbose, $output) !== 0) {
                return Command::FAILURE;
            }
        } finally {
            $this->removeDirectory($staging);
            if (file_exists($tempTar)) {
                @unlink($tempTar);
            }
        }

        $size = file_exists($destination) ? filesize($destination) : 0;
        $verbose->done(sprintf('Archive created (%s bytes)', number_format($size)));
        $output->writeln($destination);

        return Command::SUCCESS;
    }

    /**
     * Resolve destination relative to the user's original working directory
     * (Moodle bootstrap may have chdir'd elsewhere).
     */
    private function resolveDestination(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($path[0] === '/') {
            return $path;
        }

        return rtrim(Application::getOriginalCwd(), '/') . '/' . $path;
    }

    private function parseCsv(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function createStagingDir(string $parentDir): string
    {
        $tmp = $parentDir . '/.moosh-archive-stage-' . uniqid('', true);
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException("Failed to create staging directory: $tmp");
        }
        return $tmp;
    }

    private function runDatabaseDump(
        string $dbType,
        string $outputFile,
        VerboseLogger $verbose,
        OutputInterface $output,
    ): int {
        if ($dbType === 'pgsql') {
            $this->setPgPassword();
            // --clean --if-exists makes the dump re-importable into an
            // existing database (DROP statements precede each CREATE).
            // mysqldump emits the equivalent DROP TABLE IF EXISTS by default.
            $cmd = str_replace(
                'pg_dump ',
                'pg_dump --clean --if-exists ',
                $this->getPgDumpCommand(),
            );
        } else {
            $cmd = $this->getMysqldumpCommand();
        }

        $cmd .= ' > ' . escapeshellarg($outputFile);
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>Database dump failed (exit code $rc)</error>");
        }

        return $rc;
    }

    /**
     * Create the initial tar archive containing files staged in $stagingDir.
     */
    private function createBaseTar(
        string $tempTar,
        string $stagingDir,
        array $files,
        OutputInterface $output,
        VerboseLogger $verbose,
    ): int {
        $cmd = sprintf(
            'tar -cf %s -C %s %s',
            escapeshellarg($tempTar),
            escapeshellarg($stagingDir),
            implode(' ', array_map('escapeshellarg', $files)),
        );
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>tar failed building base archive (exit code $rc)</error>");
        }

        return $rc;
    }

    /**
     * Append the contents of $sourceDir to the tar, renaming the source
     * basename to $prefix so paths in the archive land under $prefix/...
     */
    private function appendDirectoryToTar(
        string $tempTar,
        string $sourceDir,
        string $prefix,
        array $excludes,
        OutputInterface $output,
        VerboseLogger $verbose,
    ): int {
        if (!is_dir($sourceDir)) {
            $output->writeln("<error>Source directory not found: $sourceDir</error>");
            return 1;
        }

        $parent = dirname($sourceDir);
        $base = basename($sourceDir);

        $excludeArgs = '';
        foreach ($excludes as $pattern) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($pattern);
        }

        $transform = 's|^' . preg_quote($base, '|') . '|' . $prefix . '|S';

        $cmd = sprintf(
            'tar -rf %s --transform %s%s -C %s %s',
            escapeshellarg($tempTar),
            escapeshellarg($transform),
            $excludeArgs,
            escapeshellarg($parent),
            escapeshellarg($base),
        );
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>tar failed appending $prefix/ (exit code $rc)</error>");
        }

        return $rc;
    }

    private function gzipFile(
        string $source,
        string $destination,
        VerboseLogger $verbose,
        OutputInterface $output,
    ): int {
        $cmd = sprintf(
            'gzip -c %s > %s',
            escapeshellarg($source),
            escapeshellarg($destination),
        );
        $verbose->info('Command: ' . $cmd);

        $rc = 0;
        passthru($cmd, $rc);

        if ($rc !== 0) {
            $output->writeln("<error>gzip failed (exit code $rc)</error>");
            @unlink($destination);
        }

        return $rc;
    }

    private function buildManifest(
        ?string $description,
        bool $includeCode,
        bool $includeFiles,
        bool $includeDb,
        string $codeSource,
        string $dataSource,
        string $dbType,
    ): string {
        global $CFG;

        $created = gmdate('Y-m-d\TH:i:s\Z');

        // Config-only bootstrap doesn't load version.php, so $CFG->release etc.
        // are not populated. Parse version.php directly from dirroot.
        $release = '';
        $branch = '';
        $version = '';
        if (isset($CFG->dirroot) && is_dir($CFG->dirroot)) {
            try {
                $mv = MoodleVersion::fromMoodleDir($CFG->dirroot);
                $release = $mv->getRelease();
                $branch = $mv->getBranch();
                $version = (string) $mv->getNumericVersion();
            } catch (\RuntimeException) {
                // version.php missing — leave fields blank.
            }
        }

        $prefix = $CFG->prefix ?? '';
        $dbname = $CFG->dbname ?? '';
        $dbhost = $CFG->dbhost ?? '';

        $lines = [];
        $lines[] = '---';
        $lines[] = '# Generated by moosh2 archive:dump';
        $lines[] = 'moosh:';
        $lines[] = '  version: "' . $this->yamlEscape((string) Application::VERSION) . '"';
        $lines[] = '  command: "archive:dump"';
        $lines[] = 'created: "' . $created . '"';
        if ($description !== null && $description !== '') {
            $lines[] = 'description: "' . $this->yamlEscape($description) . '"';
        }
        $lines[] = 'moodle:';
        $lines[] = '  release: "' . $this->yamlEscape($release) . '"';
        $lines[] = '  branch: "' . $this->yamlEscape($branch) . '"';
        $lines[] = '  version: "' . $this->yamlEscape($version) . '"';
        $lines[] = '  dirroot: "' . $this->yamlEscape($CFG->dirroot ?? '') . '"';
        $lines[] = '  dataroot: "' . $this->yamlEscape($CFG->dataroot ?? '') . '"';
        $lines[] = '  wwwroot: "' . $this->yamlEscape($CFG->wwwroot ?? '') . '"';
        $lines[] = 'contents:';
        $lines[] = '  code: ' . ($includeCode ? 'true' : 'false');
        $lines[] = '  files: ' . ($includeFiles ? 'true' : 'false');
        $lines[] = '  database: ' . ($includeDb ? 'true' : 'false');
        if ($includeCode) {
            $lines[] = 'code:';
            $lines[] = '  archivePath: "code"';
            $lines[] = '  source: "' . $this->yamlEscape($codeSource) . '"';
        }
        if ($includeFiles) {
            $lines[] = 'files:';
            $lines[] = '  archivePath: "files"';
            $lines[] = '  source: "' . $this->yamlEscape($dataSource) . '"';
        }
        if ($includeDb) {
            $lines[] = 'database:';
            $lines[] = '  archivePath: "database.sql"';
            $lines[] = '  type: "' . $this->yamlEscape($dbType) . '"';
            $lines[] = '  driver: "' . $this->yamlEscape($CFG->dbtype ?? '') . '"';
            $lines[] = '  name: "' . $this->yamlEscape($dbname) . '"';
            $lines[] = '  host: "' . $this->yamlEscape($dbhost) . '"';
            $lines[] = '  prefix: "' . $this->yamlEscape($prefix) . '"';
        }

        return implode("\n", $lines) . "\n";
    }

    private function yamlEscape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Recursively delete a directory. Best-effort — silently ignores missing
     * paths so we can call it from a finally block.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
