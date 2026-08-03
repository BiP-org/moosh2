<?php
/**
 * moosh2 — Moodle Shell
 *
 * Ported from moosh's Moosh\Command\Generic\Plugin\PluginListUpdate.
 *
 * Differences from the original moosh command, all intentional:
 *   - `-n|--dry-run` is gone. moosh2 already has a global write-gate
 *     (`--run`, absent by default = preview, present = actually write),
 *     so this command uses that instead, matching every other moosh2
 *     write command. This flips the *default*: the old command wrote by
 *     default and needed -n to preview; this one previews by default and
 *     needs --run to write.
 *   - `-v|--version` is gone (Symfony Console reserves -v/-vv/-vvv for
 *     verbosity). The equivalent is `--moodle-version` (long form only).
 *   - No Moosh\PluginChecksum step (no moosh2 equivalent, out of scope for
 *     this port) — checksum pinning still downloads and hashes the zip via
 *     PluginZipCache, it just isn't checked against a pinned expectation.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginListUpdate52Handler extends BaseHandler
{
    /** @var string|null resolved Moodle release to match plugin compatibility against, e.g. '4.5' */
    private ?string $moodleRelease = null;

    /** @var object|null decoded plugins.json, cached for the duration of one handle() call */
    private ?object $pluginsData = null;

    /** @var bool stashed --no-checksum, read once in handle() so the recursive helper methods don't need InputInterface threaded through */
    private bool $noChecksum = false;

    public function getBootstrapLevel(): ?BootstrapLevel
    {
        // A full Moodle bootstrap is only needed to auto-detect the current
        // release when --moodle-version wasn't given explicitly.
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if ($arg === '--moodle-version' || str_starts_with($arg, '--moodle-version=')) {
                return BootstrapLevel::None;
            }
            if ($arg === '-h' || $arg === '--help') {
                return BootstrapLevel::None;
            }
        }
        return BootstrapLevel::Full;
    }

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin_name', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Zero or more Frankenstyle component names. None given: every subdirectory of --directory is treated as a candidate.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory to scan for plugin subdirectories.', '.')
            ->addOption('moodle-version', null, InputOption::VALUE_REQUIRED, 'Moodle major version to match plugin compatibility against (e.g. 4.5). Defaults to the version of the bootstrapped Moodle site.')
            ->addOption('moodle-root', 'm', InputOption::VALUE_REQUIRED, "Working directory used when invoking a package_*'s bin/get_latest_plugin_version.sh. Defaults to the parent directory of --directory.")
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.')
            ->addOption('no-checksum', null, InputOption::VALUE_NONE, "Don't download zips to pin a sha256 checksum next to version.");

        if ($command instanceof \Moosh2\Command\BaseCommand) {
            $command->addExampleUsage('Preview what would change for every plugin directory found in the current directory', '');
            $command->addExampleUsage('Actually write updated version files', '--run');
            $command->addExampleUsage('Only update block_fastnav and mod_board, against Moodle 4.5', '--moodle-version=4.5 --run block_fastnav mod_board');
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->moodleRelease = $input->getOption('moodle-version') ?? (string) moodle_major_version();
        $this->noChecksum = (bool) $input->getOption('no-checksum');

        $client = new PluginApiClient($input->getOption('proxy'));
        $client->ensureCacheFresh();
        $this->pluginsData = $client->getPluginList();

        $basedir = rtrim($input->getOption('directory'), '/');
        if ($basedir === '') {
            $basedir = '/';
        }
        if (!is_dir($basedir)) {
            $output->writeln("<e>Directory not found: $basedir</e>");
            return Command::FAILURE;
        }
        $basedir = realpath($basedir);

        $moodleroot = $input->getOption('moodle-root') ?: dirname($basedir);

        $components = $input->getArgument('plugin_name');
        if (empty($components)) {
            $components = $this->discoverComponents($basedir);
        }

        if (empty($components)) {
            $output->writeln("No plugin directories found in $basedir.");
            return Command::SUCCESS;
        }

        $dryRun = !$input->getOption('run');
        if ($dryRun) {
            $output->writeln('<info>Dry run — showing what would change (use --run to write version files):</info>');
        }

        $exitCode = Command::SUCCESS;

        foreach ($components as $component) {
            $componentdir = $basedir . '/' . $component;
            if (!is_dir($componentdir)) {
                $output->writeln("SKIP   $component: directory not found ($componentdir)");
                $exitCode = Command::FAILURE;
                continue;
            }

            try {
                $message = str_starts_with($component, 'package_')
                    ? $this->updatePackageComponent($component, $componentdir, $moodleroot, $dryRun, $client, $output)
                    : $this->updateStandardComponent($component, $componentdir, $dryRun, $client, $output);
                $output->writeln($message);
            } catch (\RuntimeException $e) {
                $output->writeln('ERROR  ' . $component . ': ' . $e->getMessage());
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @return string[] non-hidden subdirectory names of $basedir, sorted
     */
    private function discoverComponents(string $basedir): array
    {
        $components = [];
        foreach (scandir($basedir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            if (!is_dir($basedir . '/' . $entry)) {
                continue;
            }
            $components[] = $entry;
        }
        sort($components);
        return $components;
    }

    /**
     * Handle a regular (non package_*) component: look its latest
     * compatible version up in plugins.json and reconcile it with the
     * component's version file.
     *
     * @throws \RuntimeException if $component can't be found in plugins.json
     */
    private function updateStandardComponent(string $component, string $componentdir, bool $dryRun, PluginApiClient $client, OutputInterface $output): string
    {
        $versionfile = $componentdir . '/version';
        $currentversion = $this->readVersionFile($versionfile);

        if ($currentversion === '0') {
            return "SKIP   $component: pinned to version 0 (marked for uninstall)";
        }

        $latest = $this->findLatestCompatibleVersion($component);

        if ($latest === null) {
            if (!$dryRun) {
                $this->writeSupportStatus($componentdir, 'not supported for moodle core ' . $this->moodleRelease);
            }
            return "SKIP   $component: no version supports Moodle {$this->moodleRelease}";
        }

        if (!$dryRun) {
            $this->clearSupportStatus($componentdir);
        }

        $message = $this->applyVersion($component, $versionfile, $currentversion, (string) $latest->version, $dryRun);

        if (!$dryRun && !$this->hasOptionNoChecksum() && !str_starts_with($message, 'SKIP   ')) {
            // Recompute/pin whenever the version file actually changed just
            // now; otherwise (the "OK already at latest" case) only backfill
            // a checksum that isn't pinned yet - don't re-download on every
            // run just to re-confirm nothing changed.
            $versionchanged = str_starts_with($message, 'CREATE ') || str_starts_with($message, 'UPDATE ');
            $checksumline = $this->reconcileChecksum($component, $componentdir, (string) $latest->version, $latest->downloadurl, $versionchanged, $client);
            if ($checksumline !== null) {
                $message .= "\n" . $checksumline;
            }
        }

        return $message;
    }

    private function hasOptionNoChecksum(): bool
    {
        return $this->noChecksum;
    }

    /**
     * Make sure `<componentdir>/checksum` holds the sha256 of $version's
     * zip, downloading it (via the shared PluginZipCache, same as
     * plugin:clamscan) only when that's not already the case.
     *
     * @throws \RuntimeException if the zip can't be downloaded
     */
    private function reconcileChecksum(string $component, string $componentdir, string $version, string $downloadurl, bool $forceRecompute, PluginApiClient $client): ?string
    {
        $checksumfile = $componentdir . '/checksum';
        $existing = $this->readVersionFile($checksumfile);
        if (!$forceRecompute && $existing !== null) {
            return null;
        }

        $tempdir = null;
        try {
            [$downloadedfile, $tempdir] = $this->downloadPluginZip($component, $version, $downloadurl, $client);
            $sha256 = hash_file('sha256', $downloadedfile);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("could not pin checksum for $version: " . $e->getMessage());
        } finally {
            if ($tempdir !== null && is_dir($tempdir)) {
                $this->removeDirectory($tempdir);
            }
        }

        file_put_contents($checksumfile, $sha256 . "\n");

        return "PIN    $component: checksum $sha256 for $version";
    }

    /**
     * Download (or fetch from the shared cache) $component's zip for
     * $version, same cache-then-HTTP flow used by plugin:clamscan.
     *
     * @return array{0: string, 1: string} [downloadedFile, tempDir] — caller
     *   must remove tempDir once done with it.
     * @throws \RuntimeException
     */
    private function downloadPluginZip(string $component, string $version, string $downloadurl, PluginApiClient $client): array
    {
        $tempdir = rtrim(sys_get_temp_dir(), '/') . '/moosh_plugin_list_update_' . uniqid();
        if (!mkdir($tempdir, 0755, true) && !is_dir($tempdir)) {
            throw new \RuntimeException("Failed to create temp directory $tempdir.");
        }

        $downloadedfile = $tempdir . '/' . $component . '.zip';

        if (PluginZipCache::fetch($component, $version, $downloadedfile)) {
            return [$downloadedfile, $tempdir];
        }

        $client->downloadFile($downloadurl, $downloadedfile);

        if (!PluginZipCache::isValidZip($downloadedfile)) {
            @unlink($downloadedfile);
            throw new \RuntimeException("Downloaded file from $downloadurl is not a valid, non-empty zip archive.");
        }

        PluginZipCache::store($component, $version, $downloadedfile);

        return [$downloadedfile, $tempdir];
    }

    /**
     * Handle a package_* component: defer to its own
     * bin/get_latest_plugin_version.sh, mirroring the calling convention
     * used by get_latest_plugin_version() in moodle_plugins_lib.rc (no
     * arguments, cwd set to the Moodle root, called via its full path).
     *
     * @throws \RuntimeException
     */
    private function updatePackageComponent(string $component, string $componentdir, string $moodleroot, bool $dryRun, PluginApiClient $client, OutputInterface $output): string
    {
        $script = $componentdir . '/bin/get_latest_plugin_version.sh';
        if (!is_file($script)) {
            throw new \RuntimeException("could not find $component/bin/get_latest_plugin_version.sh");
        }
        if (!is_executable($script)) {
            throw new \RuntimeException("$component/bin/get_latest_plugin_version.sh is not executable");
        }

        $versionfile = $componentdir . '/version';
        $currentversion = $this->readVersionFile($versionfile);

        if ($currentversion === '0') {
            return "SKIP   $component: pinned to version 0 (marked for uninstall)";
        }

        $latest = $this->runGetLatestPluginVersionScript($script, $moodleroot);

        return $this->applyVersion($component, $versionfile, $currentversion, $latest, $dryRun);
    }

    /**
     * @throws \RuntimeException
     */
    private function runGetLatestPluginVersionScript(string $script, string $moodleroot): string
    {
        // __config_plugin_directory / __moodle_root_directory match the
        // variables moodle_plugins_lib.rc exports into the environment a
        // package_* bin/ script runs in.
        putenv('__config_plugin_directory=' . dirname(dirname($script)));
        putenv('__moodle_root_directory=' . $moodleroot);

        $cwd = getcwd();
        chdir($moodleroot);
        exec(escapeshellarg($script) . ' 2>&1', $output, $exitcode);
        chdir($cwd);

        if ($exitcode !== 0) {
            throw new \RuntimeException('bin/get_latest_plugin_version.sh exited with status ' . $exitcode . ': ' . implode("\n", $output));
        }

        $latest = trim(implode("\n", $output));
        if (!preg_match('/^-?[0-9]+$/', $latest)) {
            throw new \RuntimeException("bin/get_latest_plugin_version.sh did not report a valid integer version: '$latest'");
        }

        return $latest;
    }

    /**
     * Reconcile a component's on-disk version file with a newly-resolved
     * latest version, writing the file only when something actually needs
     * to change (and never downgrading a locally newer/pinned version).
     */
    private function applyVersion(string $component, string $versionfile, ?string $currentversion, string $latestversion, bool $dryRun): string
    {
        if ($currentversion === null) {
            if ($dryRun) {
                return "WOULD CREATE $component: version file missing -> would write $latestversion";
            }
            $this->writeVersionFile($versionfile, $latestversion);
            return "CREATE $component: version file missing -> $latestversion";
        }

        if ($currentversion === $latestversion) {
            return "OK     $component: already at latest ($currentversion)";
        }

        if ((int) $currentversion > (int) $latestversion) {
            return "SKIP   $component: local version $currentversion is newer than latest available $latestversion";
        }

        if ($dryRun) {
            return "WOULD UPDATE $component: $currentversion -> $latestversion";
        }
        $this->writeVersionFile($versionfile, $latestversion);
        return "UPDATE $component: $currentversion -> $latestversion";
    }

    private function readVersionFile(string $versionfile): ?string
    {
        if (!is_file($versionfile)) {
            return null;
        }
        $contents = trim(file_get_contents($versionfile));
        return $contents === '' ? null : $contents;
    }

    private function writeVersionFile(string $versionfile, string $version): void
    {
        file_put_contents($versionfile, $version . "\n");
    }

    private function writeSupportStatus(string $componentdir, string $status): void
    {
        file_put_contents($componentdir . '/support_status', $status . "\n");
    }

    private function clearSupportStatus(string $componentdir): void
    {
        $path = $componentdir . '/support_status';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Find the highest version of $component in plugins.json that
     * supports $this->moodleRelease.
     *
     * @return object|null the matched version entry (has ->version), or
     *   null if the plugin exists but has no version for this release
     * @throws \RuntimeException if $component isn't in plugins.json at all
     */
    private function findLatestCompatibleVersion(string $component): ?object
    {
        foreach ($this->pluginsData->plugins as $plugin) {
            if (empty($plugin->component) || $plugin->component !== $component) {
                continue;
            }

            $best = null;
            foreach ($plugin->versions as $version) {
                if (!$this->isSupportedByMoodle($version)) {
                    continue;
                }
                if ($best === null || $version->version > $best->version) {
                    $best = $version;
                }
            }
            return $best;
        }

        throw new \RuntimeException(
            'component not found in plugins.json — check the frankenstyle name, or the plugin may no longer be listed on moodle.org',
        );
    }

    private function isSupportedByMoodle(object $version): bool
    {
        foreach ($version->supportedmoodles as $supported) {
            if ((string) $this->moodleRelease === (string) $supported->release) {
                return true;
            }
        }
        return false;
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
