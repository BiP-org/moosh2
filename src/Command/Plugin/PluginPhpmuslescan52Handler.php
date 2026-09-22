<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\PhpMusselRunner;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginPhpmuslescan52Handler extends BaseHandler
{
    public function getBootstrapLevel(): ?BootstrapLevel
    {
        // Scanning never needs a working Moodle site.
        return BootstrapLevel::None;
    }

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin', InputArgument::OPTIONAL, 'Frankenstyle plugin name (e.g. mod_attendance). Omit to scan the plugin in the current directory.')
            ->addOption('release', 'r', InputOption::VALUE_REQUIRED, 'Specific version to scan, e.g. 2024010700 (only used with a plugin name). Defaults to the newest available version.')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.')
            ->addOption('infected', 'i', InputOption::VALUE_NONE, 'Only print filenames that ARE infected.')
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'Save scan report to a file.')
            ->addOption(
                'whitelist',
                'w',
                InputOption::VALUE_REQUIRED,
                'Path to an extra whitelist file, on top of the built-in, global '
                . '(~/.moosh2/phpmuslescan-whitelist) and per-plugin ('
                . PhpMusselRunner::WHITELIST_FILENAME . ' in the plugin root) whitelists. '
                . 'One entry per line: "pattern" skips the whole file, or "pattern | reason" '
                . 'only suppresses a detection whose message contains that reason. Patterns are '
                . 'globs by default ("*" within one path segment, "**" across any number, '
                . 'including zero — "**/*.min.js" also matches a root-level file), or prefix '
                . 'with "regex:" for a raw PCRE. "#" for comments.',
            );

        if ($command instanceof \Moosh2\Command\BaseCommand) {
            $command->addExampleUsage('Scan the plugin in the current directory', '');
            $command->addExampleUsage('Download and scan a specific plugin/version', 'mod_board --release=2024010700');
            $command->addExampleUsage('Run both scanners back-to-back', 'mod_board && moosh plugin:clamscan mod_board');
            $command->addExampleUsage(
                'Suppress a false positive without a whitelist file in the plugin root',
                '--whitelist=/path/to/extra-whitelist.txt',
            );
            $command->addExampleUsage(
                'Scope a whitelist entry to one detection instead of the whole file',
                "(in a whitelist file) lib/thirdparty/foo.js | phpMussel-Suspect.DoubleExtension-00",
            );
            $command->addExampleUsage(
                'Whitelist every minified JS file, at any depth, for one detection',
                '(in a whitelist file) **/*.min.js | phpMussel-Suspect.DoubleExtension-00',
            );
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $tempDir = null;

        try {
            $pluginName = $input->getArgument('plugin');

            if ($pluginName !== null) {
                [$pluginRoot, $tempDir] = $this->downloadAndExtractPlugin($pluginName, $input, $output);
            } else {
                $pluginRoot = $this->resolvePluginRootFromCwd(getcwd());
            }

            $extraWhitelist = [];
            if ($whitelistFile = $input->getOption('whitelist')) {
                if (!is_readable($whitelistFile)) {
                    throw new \RuntimeException("Whitelist file not readable: $whitelistFile");
                }
                foreach (file($whitelistFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && !str_starts_with($line, '#')) {
                        $extraWhitelist[] = $line;
                    }
                }
            }

            $output->writeln("Starting phpMussel scan at $pluginRoot");
            $runner = new PhpMusselRunner();
            $result = $runner->scan($pluginRoot, $extraWhitelist);

            $output->writeln($result['output']);

            if ($logFile = $input->getOption('log')) {
                file_put_contents($logFile, $result['output']);
            }

            return $result['exitCode'];
        } catch (\RuntimeException $e) {
            $output->writeln('<e>' . $e->getMessage() . '</e>');
            return PhpMusselRunner::EXIT_ERROR;
        } finally {
            if ($tempDir !== null && is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Confirm $cwd looks like a plugin root (i.e. contains a plugin
     * version.php, not the Moodle core one).
     *
     * @throws \RuntimeException if $cwd doesn't look like a plugin
     */
    private function resolvePluginRootFromCwd(string $cwd): string
    {
        $cwd = rtrim($cwd, '/');
        $versionphp = $cwd . '/version.php';

        if (!is_file($versionphp)) {
            throw new \RuntimeException(
                "No plugin name given and no version.php found in $cwd — " .
                "run this from a plugin's root directory, or pass a plugin name to download and scan.",
            );
        }

        // Moodle's own webroot also has a version.php (core version:
        // $version/$release/$branch, no $plugin object). Checking only
        // for file-existence means running this command from the Moodle
        // root by mistake silently scans the entire codebase instead of
        // failing — only a plugin's version.php assigns to $plugin->...
        if (!$this->isPluginVersionFile($versionphp)) {
            throw new \RuntimeException(
                "No plugin name given and version.php in $cwd doesn't look like a plugin's "
                . "(no \$plugin->... assignment found — this looks like the Moodle root, not a plugin) — "
                . "run this from a plugin's root directory, or pass a plugin name to download and scan.",
            );
        }
        return $cwd;
    }

    /**
     * Distinguishes a plugin's version.php (assigns to $plugin->...) from
     * Moodle core's root version.php (sets bare $version/$release/$branch,
     * no $plugin object) without executing the file.
     */
    private function isPluginVersionFile(string $versionphp): bool
    {
        $contents = @file_get_contents($versionphp);
        if ($contents === false) {
            return false;
        }
        return (bool) preg_match('/\$plugin\s*->\s*[A-Za-z_]/', $contents);
    }

    /**
     * Resolve which version of $pluginName to scan: the exact requested
     * version if given, otherwise the newest version listed.
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
     * @return array{0: string, 1: string} [pluginRoot, tempDir]
     * @throws \RuntimeException on any download/extraction failure
     */
    private function downloadAndExtractPlugin(string $pluginName, InputInterface $input, OutputInterface $output): array
    {
        $client = new PluginApiClient($input->getOption('proxy'));
        $version = $this->resolvePluginVersion($client, $pluginName, $input->getOption('release'));

        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/moosh_plugin_phpmuslescan_' . uniqid();
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

    private function findPluginDir(string $dir): ?string
    {
        if (file_exists($dir . '/version.php') && $this->isPluginVersionFile($dir . '/version.php')) {
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