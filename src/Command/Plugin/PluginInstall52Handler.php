<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginInstall52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin', InputArgument::OPTIONAL, 'Frankenstyle plugin name (e.g. mod_attendance). Optional when --from-file is used.')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Specific plugin version number (e.g. 2024010700)')
            ->addOption('from-file', 'F', InputOption::VALUE_REQUIRED, 'Install from a local plugin ZIP file instead of downloading from moodle.org')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force install even if Moodle version is unsupported')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Remove existing plugin directory before installing')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port)');

        if ($command instanceof BaseCommand) {
            $command->addExampleUsage(
                'Install latest compatible release from moodle.org',
                'mod_attendance --run',
            );
            $command->addExampleUsage(
                'Install a specific release from moodle.org',
                'mod_attendance --release=2024010700 --run',
            );
            $command->addExampleUsage(
                'Install from an already-downloaded ZIP (plugin name auto-detected)',
                '--from-file=/tmp/mod_attendance.zip --run',
            );
            $command->addExampleUsage(
                'Install from local ZIP, replacing existing directory',
                'mod_attendance --from-file=/tmp/mod_attendance.zip --delete --run',
            );
            $command->addExampleUsage(
                'Dry-run preview of what would be installed',
                'mod_attendance',
            );
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $pluginName = $input->getArgument('plugin');
        $fromFile = $input->getOption('from-file');
        $releaseVersion = $input->getOption('release');
        $force = $input->getOption('force');
        $delete = $input->getOption('delete');
        $proxy = $input->getOption('proxy');

        require_once $CFG->libdir . '/adminlib.php';
        require_once $CFG->libdir . '/upgradelib.php';
        require_once $CFG->libdir . '/filelib.php';

        if ($fromFile === null && $pluginName === null) {
            $output->writeln('<error>Plugin name is required unless --from-file is provided.</error>');
            return Command::FAILURE;
        }

        if ($fromFile !== null) {
            if (!file_exists($fromFile)) {
                $output->writeln("<error>File not found: $fromFile</error>");
                return Command::FAILURE;
            }
            if (!is_readable($fromFile)) {
                $output->writeln("<error>File not readable: $fromFile</error>");
                return Command::FAILURE;
            }
            if ($releaseVersion !== null) {
                $output->writeln('<error>--release cannot be combined with --from-file.</error>');
                return Command::FAILURE;
            }
        }

        // When using --from-file we extract upfront so we can read version.php
        // to detect the plugin component and verify it matches any plugin name
        // the user provided.
        $tempDir = null;
        $extractedPluginDir = null;

        if ($fromFile !== null) {
            $tempDir = sys_get_temp_dir() . '/moosh_plugin_' . uniqid();
            mkdir($tempDir, 0755, true);
            $extractDir = $tempDir . '/extracted';
            mkdir($extractDir, 0755, true);

            $verbose->step("Extracting $fromFile");
            try {
                PluginZipCache::assertZipMagicBytes($fromFile);
            } catch (\RuntimeException $e) {
                $this->cleanup($tempDir);
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            $zip = new \ZipArchive();
            if ($zip->open($fromFile) !== true) {
                $this->cleanup($tempDir);
                $output->writeln("<error>Failed to open ZIP archive: $fromFile</error>");
                return Command::FAILURE;
            }
            $zip->extractTo($extractDir);
            $zip->close();

            $extractedPluginDir = $this->findPluginDir($extractDir);
            if ($extractedPluginDir === null) {
                $this->cleanup($tempDir);
                $output->writeln('<error>The ZIP does not contain a valid plugin (no version.php found).</error>');
                return Command::FAILURE;
            }

            $detected = $this->detectPluginComponent($extractedPluginDir);

            if ($pluginName === null) {
                if ($detected === null) {
                    $this->cleanup($tempDir);
                    $output->writeln('<error>Could not detect plugin component from version.php. Specify the plugin name explicitly.</error>');
                    return Command::FAILURE;
                }
                $pluginName = $detected;
                $verbose->step("Detected plugin: $pluginName");
            } elseif ($detected !== null && $detected !== $pluginName) {
                $this->cleanup($tempDir);
                $output->writeln("<error>Plugin name mismatch: argument is '$pluginName' but ZIP contains '$detected'.</error>");
                return Command::FAILURE;
            }
        }

        // Validate plugin name format and resolve target install path.
        $split = explode('_', $pluginName, 2);
        if (count($split) !== 2) {
            $this->cleanup($tempDir);
            $output->writeln("<error>Invalid plugin name '$pluginName'. Expected format: type_name (e.g. mod_attendance).</error>");
            return Command::FAILURE;
        }

        [$type, $component] = $split;
        $pluginTypes = \core_component::get_plugin_types();

        if (!isset($pluginTypes[$type])) {
            $this->cleanup($tempDir);
            $output->writeln("<error>Unknown plugin type '$type'.</error>");
            return Command::FAILURE;
        }

        $installPath = $pluginTypes[$type];
        $targetPath = $installPath . DIRECTORY_SEPARATOR . $component;
        $exists = file_exists($targetPath);

        if (!is_writable($installPath)) {
            $this->cleanup($tempDir);
            $output->writeln("<error>No write permission for plugin directory $installPath. Check filesystem ownership and permissions for the user running moosh.</error>");
            return Command::FAILURE;
        }
        if ($exists && !is_writable($targetPath)) {
            $this->cleanup($tempDir);
            $output->writeln("<error>No write permission for existing plugin directory $targetPath. Check filesystem ownership and permissions for the user running moosh.</error>");
            return Command::FAILURE;
        }

        // Resolve remote version when not using a local file.
        $version = null;
        $client = null;
        if ($fromFile === null) {
            $moodleRelease = moodle_major_version();
            $verbose->step("Resolving plugin $pluginName for Moodle $moodleRelease");

            $client = new PluginApiClient($proxy);

            try {
                $version = $client->findBestVersion($pluginName, (string) $moodleRelease, $releaseVersion, $force);
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — would install the following plugin (use --run to execute):</info>');
            $output->writeln("  plugin:   $pluginName");
            if ($fromFile !== null) {
                $output->writeln("  source:   $fromFile (local file)");
            } else {
                $output->writeln("  version:  {$version->version}");
                $output->writeln("  url:      {$version->downloadurl}");
            }
            $output->writeln("  target:   $targetPath");
            if ($exists && $delete) {
                $output->writeln('  action:   Delete existing directory and reinstall');
            } elseif ($exists) {
                $output->writeln('  warning:  Target directory already exists (use --delete to overwrite)');
            }
            $this->cleanup($tempDir);
            return Command::SUCCESS;
        }

        if ($exists && !$delete) {
            $this->cleanup($tempDir);
            $output->writeln("<error>Directory already exists at $targetPath. Use --delete to remove it first.</error>");
            return Command::FAILURE;
        }

        // Download + extract for the remote path. Local-file path already
        // extracted upfront.
        if ($fromFile === null) {
            $tempDir = sys_get_temp_dir() . '/moosh_plugin_' . uniqid();
            mkdir($tempDir, 0755, true);
            $zipFile = $tempDir . '/' . $component . '.zip';

            $verbose->step('Downloading plugin');
            try {
                $client->downloadFile($version->downloadurl, $zipFile);
                PluginZipCache::assertZipMagicBytes($zipFile);
            } catch (\RuntimeException $e) {
                $this->cleanup($tempDir);
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            $verbose->step('Extracting archive');
            $extractDir = $tempDir . '/extracted';
            mkdir($extractDir, 0755, true);

            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                $this->cleanup($tempDir);
                $output->writeln('<error>Failed to open ZIP archive.</error>');
                return Command::FAILURE;
            }
            $zip->extractTo($extractDir);
            $zip->close();

            $extractedPluginDir = $this->findPluginDir($extractDir);
            if ($extractedPluginDir === null) {
                $this->cleanup($tempDir);
                $output->writeln('<error>The ZIP does not contain a valid plugin (no version.php found).</error>');
                return Command::FAILURE;
            }
        }

        if ($exists && $delete) {
            $verbose->step("Removing existing directory $targetPath");
            \fulldelete($targetPath);
        }

        $verbose->step("Installing to $targetPath");
        try {
            $this->moveDirectory($extractedPluginDir, $targetPath);
        } catch (\RuntimeException $e) {
            $this->cleanup($tempDir);
            $output->writeln("<error>Failed to install plugin to $targetPath: " . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->cleanup($tempDir);

        // Run Moodle upgrade — must fully reset component caches so Moodle sees the new plugin.
        // The on-disk core_component.php cache must be deleted first, otherwise
        // core_component::init() reloads the stale cached plugin list.
        $verbose->step('Running upgrade_noncore()');
        $cacheFile = $CFG->cachedir . '/core_component.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
        \core_component::reset(true);
        \core_plugin_manager::reset_caches();
        upgrade_noncore(true);

        if ($fromFile !== null) {
            $verbose->done("Plugin $pluginName installed successfully from $fromFile");
            $output->writeln("Installed $pluginName from $fromFile to $targetPath.");
        } else {
            $verbose->done("Plugin $pluginName version {$version->version} installed successfully");
            $output->writeln("Installed $pluginName ({$version->version}) to $targetPath.");
        }

        return Command::SUCCESS;
    }

    private function findPluginDir(string $dir): ?string
    {
        if (file_exists($dir . '/version.php')) {
            return $dir;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $found = $this->findPluginDir($path);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function detectPluginComponent(string $pluginDir): ?string
    {
        $versionFile = $pluginDir . '/version.php';
        if (!file_exists($versionFile)) {
            return null;
        }
        $contents = (string) @file_get_contents($versionFile);
        if (preg_match('/\$plugin->component\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $contents, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Move a directory into place. Tries an atomic rename() first; if that
     * fails because source and destination are on different filesystems
     * (EXDEV "Invalid cross-device link" - common in CI containers where
     * /tmp and the Moodle directory are separate mounts), falls back to a
     * recursive copy followed by deleting the source.
     *
     * @throws \RuntimeException on failure
     */
    private function moveDirectory(string $src, string $dst): void
    {
        if (@rename($src, $dst)) {
            return;
        }

        $this->copyDirectory($src, $dst);
        $this->cleanup($src);
    }

    /**
     * Recursively copy a directory. Used by moveDirectory()'s cross-device
     * fallback.
     *
     * @throws \RuntimeException on failure
     */
    private function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new \RuntimeException("Failed to create directory $dst.");
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException("Failed to create directory $target.");
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Failed to copy {$item->getPathname()} to $target.");
            }
        }
    }

    private function cleanup(?string $dir): void
    {
        if ($dir === null) {
            return;
        }
        if (function_exists('fulldelete')) {
            fulldelete($dir);
        } elseif (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
    }
}
