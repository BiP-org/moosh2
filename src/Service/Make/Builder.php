<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

use Moosh2\Service\PluginApiClient;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

/**
 * Executes a {@see Manifest}: clones Moodle core and assembles plugins into the
 * correct frankenstyle paths under the destination directory.
 */
final readonly class Builder
{
    public function __construct(
        private PluginApiClient $pluginApi,
    ) {
    }

    /**
     * Render the manifest as a list of human-readable plan lines for dry-run
     * output. Does not touch the filesystem or network.
     *
     * @return list<string>
     */
    public function describePlan(Manifest $manifest, string $destination): array
    {
        $destination = rtrim($destination, '/');
        $lines = [];

        $lines[] = sprintf(
            'Core: %s @ %s → %s',
            $manifest->core->git,
            $manifest->core->branch,
            $destination,
        );

        if ($manifest->plugins === []) {
            $lines[] = '(no plugins)';
            return $lines;
        }

        $lines[] = '';
        $lines[] = 'Plugins:';
        foreach ($manifest->plugins as $plugin) {
            $target = PluginTypePaths::pathFor($plugin->component, $destination);
            $source = match ($plugin->source()) {
                'git' => sprintf(
                    'git: %s @ %s',
                    $plugin->git,
                    $plugin->branch ?? 'HEAD',
                ),
                'zip' => sprintf('zip: %s', $plugin->zip),
                'directory' => sprintf(
                    'moodle.org plugin directory (%s)',
                    $plugin->version ?? 'latest for core ' . $manifest->core->version,
                ),
            };
            $lines[] = sprintf('  %s — %s → %s', $plugin->component, $source, $target);
        }

        return $lines;
    }

    /**
     * Execute the plan: clone core, fetch each plugin, place at its frankenstyle
     * path. The destination must be empty or non-existent — call {@see assertDestinationUsable()}
     * up-front if you want a clean error before any work begins.
     */
    public function run(Manifest $manifest, string $destination, OutputInterface $output): void
    {
        $destination = rtrim($destination, '/');

        $output->writeln(sprintf(
            '<info>Cloning core</info>: %s @ %s → %s',
            $manifest->core->git,
            $manifest->core->branch,
            $destination,
        ));
        $this->cloneGit($manifest->core->git, $manifest->core->branch, $destination, $output);

        foreach ($manifest->plugins as $plugin) {
            $target = PluginTypePaths::pathFor($plugin->component, $destination);
            if (is_dir($target)) {
                throw new RuntimeException(
                    "Plugin destination already exists: $target. "
                    . "This usually means Moodle core ships its own copy of the plugin — remove it first or omit the plugin from the manifest."
                );
            }
            $output->writeln(sprintf(
                '<info>Plugin</info>: %s → %s',
                $plugin->component,
                $target,
            ));
            $this->fetchPlugin($plugin, $manifest->core->version, $target, $output);
        }
    }

    /**
     * Verify the destination is safe to write into (does not exist, or is empty).
     *
     * @throws RuntimeException when the destination is non-empty.
     */
    public function assertDestinationUsable(string $destination): void
    {
        if (!file_exists($destination)) {
            $parent = dirname($destination);
            if (!is_dir($parent) || !is_writable($parent)) {
                throw new RuntimeException("Destination parent is not a writable directory: $parent");
            }
            return;
        }
        if (!is_dir($destination)) {
            throw new RuntimeException("Destination exists but is not a directory: $destination");
        }
        $entries = scandir($destination);
        $entries = $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
        if ($entries !== []) {
            throw new RuntimeException(
                "Destination is not empty: $destination (contains " . count($entries) . " entries). "
                . "Choose a fresh directory or remove its contents first."
            );
        }
    }

    private function fetchPlugin(
        PluginEntry $plugin,
        string $coreVersion,
        string $target,
        OutputInterface $output,
    ): void {
        match ($plugin->source()) {
            'git' => $this->cloneGit($plugin->git, $plugin->branch, $target, $output),
            'zip' => $this->downloadAndExtractZip($plugin->zip, $target, $output),
            'directory' => $this->fetchFromDirectory($plugin, $coreVersion, $target, $output),
        };
    }

    private function fetchFromDirectory(
        PluginEntry $plugin,
        string $coreVersion,
        string $target,
        OutputInterface $output,
    ): void {
        $version = $this->pluginApi->findBestVersion(
            $plugin->component,
            $coreVersion,
            $plugin->version,
        );
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '    moodle.org version %s → %s',
                $version->version,
                $version->downloadurl,
            ));
        }
        $this->downloadAndExtractZip($version->downloadurl, $target, $output);
    }

    private function cloneGit(string $url, ?string $branch, string $target, OutputInterface $output): void
    {
        $cmd = ['git', 'clone', '--depth', '1'];
        if ($branch !== null && $branch !== '') {
            $cmd[] = '--single-branch';
            $cmd[] = '-b';
            $cmd[] = $branch;
        }
        $cmd[] = $url;
        $cmd[] = $target;

        $this->execCommand($cmd, $output);
    }

    private function downloadAndExtractZip(string $url, string $target, OutputInterface $output): void
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'moosh-make-zip-');
        if ($tmpZip === false) {
            throw new RuntimeException('Failed to create temporary file for plugin download.');
        }
        $tmpExtract = $tmpZip . '-extract';

        try {
            if ($output->isVerbose()) {
                $output->writeln("    download $url");
            }
            $this->pluginApi->downloadFile($url, $tmpZip);

            if (!mkdir($tmpExtract, 0755, true) && !is_dir($tmpExtract)) {
                throw new RuntimeException("Failed to create extract directory: $tmpExtract");
            }

            $zip = new ZipArchive();
            $opened = $zip->open($tmpZip);
            if ($opened !== true) {
                throw new RuntimeException("Failed to open zip $tmpZip (ZipArchive code $opened).");
            }
            if (!$zip->extractTo($tmpExtract)) {
                $zip->close();
                throw new RuntimeException("Failed to extract zip $tmpZip into $tmpExtract.");
            }
            $zip->close();

            $sub = $this->singleTopLevelDir($tmpExtract);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException("Failed to create parent directory: $parent");
            }
            if (!rename($sub, $target)) {
                throw new RuntimeException("Failed to move $sub → $target");
            }
        } finally {
            @unlink($tmpZip);
            $this->rmdirRecursive($tmpExtract);
        }
    }

    /**
     * Find the single top-level directory inside an extracted plugin archive.
     * Plugin zips conventionally contain exactly one top-level directory (the
     * plugin shortname), so anything else is treated as malformed.
     */
    private function singleTopLevelDir(string $extractDir): string
    {
        $entries = scandir($extractDir);
        if ($entries === false) {
            throw new RuntimeException("Cannot read extracted directory: $extractDir");
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        if (count($entries) !== 1) {
            throw new RuntimeException(
                "Expected exactly one top-level directory in plugin archive, found " . count($entries) . "."
            );
        }
        $candidate = $extractDir . '/' . $entries[0];
        if (!is_dir($candidate)) {
            throw new RuntimeException("Top-level entry in plugin archive is not a directory: $candidate");
        }
        return $candidate;
    }

    /**
     * @param list<string> $cmd
     */
    private function execCommand(array $cmd, OutputInterface $output): void
    {
        $cmdline = implode(' ', array_map('escapeshellarg', $cmd));
        if ($output->isVerbose()) {
            $output->writeln("    $ $cmdline");
        }
        $captured = [];
        $rc = 0;
        exec($cmdline . ' 2>&1', $captured, $rc);
        if ($rc !== 0) {
            throw new RuntimeException(
                "Command failed (exit $rc): $cmdline\n"
                . implode("\n", $captured),
            );
        }
        if ($output->isVerbose()) {
            foreach ($captured as $line) {
                $output->writeln("    $line");
            }
        }
    }

    private function rmdirRecursive(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->rmdirRecursive($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
