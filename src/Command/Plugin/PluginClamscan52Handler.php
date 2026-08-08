<?php
/**
 * moosh2 — Moodle Shell
 *
 * Ported from moosh's Moosh\Command\Generic\Plugin\PluginClamscan.
 *
 * Scans either the plugin in the current directory (detected via
 * version.php) or a plugin downloaded from the moodle.org plugin directory
 * by frankenstyle name.
 *
 * Differences from the original moosh command, all intentional:
 *   - No Moosh\PluginChecksum step (that class has no moosh2 equivalent
 *     and wasn't part of this port's scope) — downloaded zips are still
 *     validated as structurally-sound zips via PluginZipCache::isValidZip(),
 *     just not checked against a pinned md5.
 *   - Plugin-root detection after unzip is a small local helper here
 *     instead of Moosh\PluginZip (same idea: first directory containing a
 *     version.php, searched depth-first).
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\ClamscanRunner;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginClamscan52Handler extends BaseHandler
{
    public function getBootstrapLevel(): ?BootstrapLevel
    {
        // Scanning never needs a working Moodle site — not even to resolve
        // a plugin's compatible version, since clamscan doesn't care about
        // Moodle compatibility, only about what code it's being asked to scan.
        return BootstrapLevel::None;
    }

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin', InputArgument::OPTIONAL, 'Frankenstyle plugin name (e.g. mod_attendance). Omit to scan the plugin in the current directory.')
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Specific version to scan, e.g. 2024010700 (only used with a plugin name). Defaults to the newest available version.')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'ClamAV/YARA database or directory to load (clamscan -d/--database). May be given multiple times.')
            ->addOption('infected', 'i', InputOption::VALUE_NONE, 'Only print filenames that ARE infected (clamscan -i/--infected).')
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'Save scan report to a file (clamscan --log=).');

        if ($command instanceof \Moosh2\Command\BaseCommand) {
            $command->addExampleUsage('Scan the plugin in the current directory', '');
            $command->addExampleUsage('Download and scan a specific plugin/version', 'mod_board --release=2024010700');
            $command->addExampleUsage('Scan with a custom YARA ruleset, only reporting infected files', 'mod_board -d /path/to/yara-rules -i');
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $tempDir = null;

        try {
            $binary = ClamscanRunner::findBinary();
            if ($binary === null) {
                $output->writeln('<e>clamscan was not found in PATH. Install ClamAV (e.g. \'apt-get install clamav\') before running plugin:clamscan.</e>');
                return ClamscanRunner::EXIT_ERROR;
            }

            $pluginName = $input->getArgument('plugin');

            if ($pluginName !== null) {
                [$pluginRoot, $tempDir] = $this->downloadAndExtractPlugin($pluginName, $input, $output);
            } else {
                $pluginRoot = $this->resolvePluginRootFromCwd(getcwd());
            }

            $options = [
                'database' => $input->getOption('database'),
                'infected' => $input->getOption('infected'),
                'log' => $input->getOption('log'),
            ];

            $output->writeln("Starting malware scan at $pluginRoot");
            [$exitcode, $scanOutput] = ClamscanRunner::scan($binary, $pluginRoot, $options);
            foreach ($scanOutput as $line) {
                $output->writeln($line);
            }

            return $exitcode;
        } catch (\RuntimeException $e) {
            $output->writeln('<e>' . $e->getMessage() . '</e>');
            return ClamscanRunner::EXIT_ERROR;
        } finally {
            if ($tempDir !== null && is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Confirm $cwd looks like a plugin root (i.e. contains version.php).
     *
     * @throws \RuntimeException if $cwd doesn't look like a plugin
     */
    private function resolvePluginRootFromCwd(string $cwd): string
    {
        $cwd = rtrim($cwd, '/');
        if (!is_file($cwd . '/version.php')) {
            throw new \RuntimeException(
                "No plugin name given and no version.php found in $cwd — " .
                "run this from a plugin's root directory, or pass a plugin name to download and scan.",
            );
        }
        return $cwd;
    }

    /**
     * Resolve which version of $pluginName to scan: the exact requested
     * version if given, otherwise the newest version listed. Unlike
     * plugin:install, this does NOT filter by Moodle compatibility —
     * clamscan doesn't care whether a version could actually be installed,
     * only about what bytes it's being asked to scan.
     *
     * @throws \RuntimeException if the plugin/version can't be found
     */
    private function resolvePluginVersion(PluginApiClient $client, string $pluginName, ?string $requestedVersion): object
    {
        $plugin = $client->findPlugin($pluginName);
        if ($plugin === null) {
            throw new \RuntimeException("Couldn't find $pluginName in the moodle.org plugin directory.");
        }

        if ($requestedVersion !== null) {
            foreach ($plugin->versions as $version) {
                if ((string) $version->version === $requestedVersion) {
                    return $version;
                }
            }
            throw new \RuntimeException("Version $requestedVersion of $pluginName not found.");
        }

        $latest = null;
        foreach ($plugin->versions as $version) {
            if ($latest === null || $version->version > $latest->version) {
                $latest = $version;
            }
        }
        if ($latest === null) {
            throw new \RuntimeException("No versions found for $pluginName.");
        }
        return $latest;
    }

    /**
     * Download (or fetch from cache) $pluginName, and extract it to a
     * fresh temp directory.
     *
     * @return array{0: string, 1: string} [pluginRoot, tempDir] — caller is
     *   responsible for deleting tempDir once done with it.
     * @throws \RuntimeException on any download/extraction failure
     */
    private function downloadAndExtractPlugin(string $pluginName, InputInterface $input, OutputInterface $output): array
    {
        $client = new PluginApiClient($input->getOption('proxy'));
        $version = $this->resolvePluginVersion($client, $pluginName, $input->getOption('release'));

        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/moosh_plugin_clamscan_' . uniqid();
        if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            throw new \RuntimeException("Failed to create temp directory $tempDir.");
        }

        $downloadedFile = $tempDir . '/' . $pluginName . '.zip';

        if (PluginZipCache::fetch($pluginName, (string) $version->version, $downloadedFile)) {
            $output->writeln("Using cached copy of $pluginName ({$version->version})");
        } else {
            $client->downloadFile($version->downloadurl, $downloadedFile);

            if (!PluginZipCache::isValidZip($downloadedFile)) {
                @unlink($downloadedFile);
                throw new \RuntimeException("Downloaded file from {$version->downloadurl} is not a valid, non-empty zip archive.");
            }

            PluginZipCache::store($pluginName, (string) $version->version, $downloadedFile);
        }

        $extractDir = $tempDir . '/extracted';
        mkdir($extractDir, 0755, true);

        $zip = new \ZipArchive();
        if ($zip->open($downloadedFile) !== true) {
            throw new \RuntimeException("Failed to open ZIP archive: $downloadedFile");
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $pluginRoot = $this->findPluginDir($extractDir);
        if ($pluginRoot === null) {
            throw new \RuntimeException('The ZIP does not contain a valid plugin (no version.php found).');
        }

        return [$pluginRoot, $tempDir];
    }

    /**
     * Depth-first search for the first directory containing version.php.
     */
    private function findPluginDir(string $dir): ?string
    {
        if (file_exists($dir . '/version.php')) {
            return $dir;
        }

        foreach (scandir($dir) as $item) {
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

    private function removeDirectory(string $dir): void
    {
        if (function_exists('fulldelete')) {
            fulldelete($dir);
            return;
        }
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
