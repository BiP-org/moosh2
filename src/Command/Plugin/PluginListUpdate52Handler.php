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
 * Checksum algorithm: md5, matching install_plugins.php's
 * moodle_official::get_plugin_download_info() / helper::download_cached(),
 * which verify a download against plugins.json's `downloadmd5` field. This
 * command does the same: when plugins.json supplies a downloadmd5 for the
 * resolved version, the freshly downloaded zip is checked against it (a
 * mismatch aborts before anything is written), and the value pinned to
 * `<component>/checksum` is the md5 - not a self-computed sha256 - so the
 * checksum file means the same thing regardless of which tool wrote it.
 *
 * Compatible with install_plugins.php's `update-versions` command: both
 * operate on the same declarative-plugin-list layout (one directory per
 * Frankenstyle component holding a `version` file), so a repository can be
 * managed with either interchangeably. In particular, a `version` file
 * pinned to a non-positive value (0/"uninstall", -1/"remove-files", or any
 * other value install_plugins.php's `$current_version <= 0` check would
 * skip) is left untouched by this command too — see sentinelSkipReason().
 * Also: some package_* components (e.g. package_kaltura) only exist as
 * install_plugins.php's own PHP package_base subclass
 * (`<component>/<component>.php`), not a bin/get_latest_plugin_version.sh
 * script - those are resolved by shelling out to install_plugins.php's
 * `get-latest-version` command, see updateInstallPluginsPhpComponent().
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\HttpRequestException;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginListUpdate52Handler extends BaseHandler
{
    // Same sentinel values (and their human-readable spellings) recognized
    // by plugin:list-apply's normalizeRequestedVersion() - a `version` file
    // holding one of these means "don't touch me", not "an out of date
    // version to bump". Recognizing only the exact string '0' here (as an
    // earlier revision did) is a compatibility bug against both
    // plugin:list-apply and install_plugins.php's update-versions, whose
    // plugin_update_version() skips on *any* version <= 0 (0 = uninstall,
    // -1 = remove-files-only): a plugin pinned to -1 would otherwise get
    // its sentinel silently overwritten with a real version number here,
    // un-pinning it behind the user's back.
    private const SENTINEL_UNINSTALL = '0';
    private const SENTINEL_UNINSTALL_STR = 'uninstall';
    private const SENTINEL_REMOVE_FILES = '-1';
    private const SENTINEL_REMOVE_FILES_STR = 'remove-files';

    /** @var string|null resolved Moodle release to match plugin compatibility against, e.g. '4.5' */
    private ?string $moodleRelease = null;

    /** @var object|null decoded plugins.json, cached for the duration of one handle() call */
    private ?object $pluginsData = null;

    /** @var bool stashed --no-checksum, read once in handle() so the recursive helper methods don't need InputInterface threaded through */
    private bool $noChecksum = false;

    /** @var string|null stashed --install-plugins-script, read once in handle() (see updateInstallPluginsPhpComponent()) */
    private ?string $installPluginsScriptOption = null;

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
            ->addOption('moodle-root', 'm', InputOption::VALUE_REQUIRED, "Working directory used when invoking a component's bin/get_latest_plugin_version.sh (package_* components, or any other component that ships one). Defaults to the parent directory of --directory.")
            ->addOption('install-plugins-script', null, InputOption::VALUE_REQUIRED, "Path to install_plugins.php, used for package_* components that ship a PHP package handler (<component>/<component>.php, e.g. package_kaltura/package_kaltura.php) instead of a bin/get_latest_plugin_version.sh script. Defaults to install_plugins.php inside --directory, matching where install_plugins.php's own __DIR__-relative paths expect it to live.")
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Moodle Marketplace API token, sent as a Bearer token only for requests to marketplace.moodle.com. Defaults to env var MOODLE_MARKETPLACE_TOKEN.')
            ->addOption('no-checksum', null, InputOption::VALUE_NONE, "Don't download zips to pin an md5 checksum next to version.");

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
        $this->installPluginsScriptOption = $input->getOption('install-plugins-script');

        $token = $input->getOption('token') ?: (getenv('MOODLE_MARKETPLACE_TOKEN') ?: null);
        $client = new PluginApiClient($input->getOption('proxy'), $token);
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
                // Three ways a component's "latest version" can be
                // resolved, checked in this order:
                //   1. bin/get_latest_plugin_version.sh - the original
                //      script-based convention. package_* components are
                //      always expected to carry one of these OR (2) below;
                //      any other component is free to opt in simply by
                //      shipping the script.
                //   2. <component>/<component>.php - install_plugins.php's
                //      own PHP-class package_base convention (e.g.
                //      package_kaltura/package_kaltura.php). Not every
                //      package ships a shell script; some (like Kaltura,
                //      which talks to the GitHub releases API) only exist
                //      as one of these classes. Resolved by shelling out to
                //      install_plugins.php itself - see
                //      updateInstallPluginsPhpComponent() - rather than
                //      reimplementing package_base's PHP-class contract
                //      here, since these classes call back into
                //      install_plugins.php's own moodle::/helper::/
                //      cli_output:: helpers and aren't meant to run
                //      standalone.
                //   3. Neither present: package_* errors (nothing else to
                //      try), anything else falls back to the standard
                //      plugins.json lookup exactly as before.
                $hasShellScript = is_file($componentdir . '/bin/get_latest_plugin_version.sh');
                $hasPhpPackage = is_file($componentdir . '/' . $component . '.php');

                if ($hasShellScript) {
                    $message = $this->updatePackageComponent($component, $componentdir, $moodleroot, $dryRun, $client, $output);
                } elseif ($hasPhpPackage) {
                    $message = $this->updateInstallPluginsPhpComponent($component, $componentdir, $basedir, $dryRun, $output);
                } elseif (str_starts_with($component, 'package_')) {
                    throw new \RuntimeException(
                        "could not find $component/bin/get_latest_plugin_version.sh or $component/$component.php",
                    );
                } else {
                    $message = $this->updateStandardComponent($component, $componentdir, $dryRun, $client, $output);
                }
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

        if ($currentversion !== null && ($skipReason = $this->sentinelSkipReason($currentversion)) !== null) {
            return "SKIP   $component: pinned to version $currentversion ($skipReason)";
        }

        $latest = $this->findLatestCompatibleVersion($component);

        if ($latest === null) {
            if (!$dryRun) {
                $this->writeSupportStatus($componentdir, 'not supported for moodle core ' . $this->moodleRelease);
            }
            return "SKIP   $component: no version supports Moodle {$this->moodleRelease}";
        }

        // plugins.json lists every version moodle.org knows about, but some
        // of those are only actually downloadable by Moodle Marketplace
        // subscribers - trying anyway returns HTTP 401 "Not privileged to
        // request the resource" from marketplace.moodle.com. Verify that
        // *before* touching the version file: bumping `version` to point at
        // a release nobody (without a valid --token) can download would
        // leave a later plugin:list-apply broken, so a 401 here means the
        // existing version file is left exactly as it was.
        //
        // Local-version-is-newer is checked first purely to skip this
        // network round trip when applyVersion() would SKIP anyway.
        $localIsNewer = $currentversion !== null && (int) $currentversion > (int) $latest->version;
        $preDownloaded = null;
        if (!$localIsNewer && PluginApiClient::isMarketplaceUrl($latest->downloadurl)) {
            try {
                $preDownloaded = $this->downloadPluginZip($component, (string) $latest->version, $latest->downloadurl, $client);
            } catch (HttpRequestException $e) {
                if ($e->getStatusCode() !== 401) {
                    throw new \RuntimeException(
                        "could not reach Moodle Marketplace for version {$latest->version}: " . $e->getMessage(),
                    );
                }
                $this->reportMarketplaceUnavailable($component, (string) $latest->version, $output);
                $label = $currentversion ?? 'unset';
                return "WARN   $component: version {$latest->version} requires a Moodle Marketplace subscription "
                    . "(HTTP 401 Not privileged to request the resource) - version left at $label";
            }
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
            $checksumline = $this->reconcileChecksum(
                $component,
                $componentdir,
                (string) $latest->version,
                $latest->downloadurl,
                $latest->downloadmd5 ?? null,
                $versionchanged,
                $client,
                $preDownloaded,
            );
            if ($checksumline !== null) {
                $message .= "\n" . $checksumline;
            }
            $preDownloaded = null; // reconcileChecksum() always consumes/cleans it up, dry or not
        }

        if ($preDownloaded !== null && is_dir($preDownloaded[1])) {
            // Downloaded only to verify Marketplace reachability (dry-run,
            // --no-checksum, or a SKIP message) - nothing else needs it.
            $this->removeDirectory($preDownloaded[1]);
        }

        return $message;
    }

    /**
     * Surface a Marketplace-subscription-required (HTTP 401) result: a
     * GitHub Actions workflow annotation when running under GitHub CI
     * (detected via $CI, one of the default environment variables GitHub
     * Actions sets - see
     * https://docs.github.com/en/actions/learn-github-actions/variables#default-environment-variables),
     * or a plain WARNING line everywhere else. Either way the caller leaves
     * the existing version file untouched.
     */
    private function reportMarketplaceUnavailable(string $component, string $version, OutputInterface $output): void
    {
        $message = "$component: version $version is only downloadable with a Moodle Marketplace subscription "
            . '(HTTP 401 Not privileged to request the resource) - leaving the existing version in place.';

        if ($this->isRunningInCi()) {
            $output->writeln("::warning title=Moodle Marketplace subscription required::$message");
        } else {
            $output->writeln("WARNING $message");
        }
    }

    /** True under GitHub Actions (and most other CI systems, which set the same $CI convention). */
    private function isRunningInCi(): bool
    {
        $ci = getenv('CI');
        return $ci !== false && $ci !== '' && strtolower($ci) !== 'false';
    }

    private function hasOptionNoChecksum(): bool
    {
        return $this->noChecksum;
    }

    /**
     * Make sure `<componentdir>/checksum` holds the md5 of $version's zip,
     * downloading it (via the shared PluginZipCache, same as
     * plugin:clamscan) only when that's not already the case.
     *
     * Matches install_plugins.php: the same md5 algorithm, and - when
     * plugins.json supplies one - verified against $expectedMd5 the same
     * way helper::download_cached() verifies its $expected_md5 argument,
     * so a corrupted or tampered download is caught before it's ever
     * pinned or extracted.
     *
     * @param string|null $expectedMd5 the `downloadmd5` plugins.json
     *   supplied for this version, or null if it didn't have one (some
     *   entries omit it - install_plugins.php falls back to downloading
     *   without verification in that case, and so does this).
     * @param array{0: string, 1: string}|null $preDownloaded [downloadedFile,
     *   tempDir] already fetched by updateStandardComponent() while probing
     *   Marketplace reachability - reused here instead of downloading the
     *   same zip a second time. Always consumed (and its tempDir removed)
     *   by this method when passed, whether or not a checksum ends up
     *   being (re)computed.
     * @throws \RuntimeException if the zip can't be downloaded, or if it
     *   doesn't match $expectedMd5
     */
    private function reconcileChecksum(string $component, string $componentdir, string $version, string $downloadurl, ?string $expectedMd5, bool $forceRecompute, PluginApiClient $client, ?array $preDownloaded = null): ?string
    {
        $checksumfile = $componentdir . '/checksum';
        $existing = $this->readVersionFile($checksumfile);
        if (!$forceRecompute && $existing !== null) {
            if ($preDownloaded !== null && is_dir($preDownloaded[1])) {
                $this->removeDirectory($preDownloaded[1]);
            }
            return null;
        }

        $tempdir = null;
        try {
            [$downloadedfile, $tempdir] = $preDownloaded ?? $this->downloadPluginZip($component, $version, $downloadurl, $client);
            $md5 = hash_file('md5', $downloadedfile);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("could not pin checksum for $version: " . $e->getMessage());
        } finally {
            if ($tempdir !== null && is_dir($tempdir)) {
                $this->removeDirectory($tempdir);
            }
        }

        if ($expectedMd5 !== null && $expectedMd5 !== '' && !hash_equals($expectedMd5, $md5)) {
            throw new \RuntimeException(
                "checksum mismatch for $version: plugins.json expects $expectedMd5, downloaded file is $md5",
            );
        }

        file_put_contents($checksumfile, $md5 . "\n");

        return "PIN    $component: checksum $md5 for $version";
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

        // Fail fast with a clear reason if what came back isn't a zip at
        // all (e.g. an HTML/JSON error page) before ever trying to parse
        // it as one; isValidZip() below still does the fuller structural
        // check (and would eventually reject it anyway), but this gives a
        // much more specific error message when it's not even a zip.
        PluginZipCache::assertZipMagicBytes($downloadedfile);

        if (!PluginZipCache::isValidZip($downloadedfile)) {
            @unlink($downloadedfile);
            throw new \RuntimeException("Downloaded file from $downloadurl is not a valid, non-empty zip archive.");
        }

        PluginZipCache::store($component, $version, $downloadedfile);

        return [$downloadedfile, $tempdir];
    }

    /**
     * Handle a component that resolves its latest version via its own
     * bin/get_latest_plugin_version.sh, mirroring the calling convention
     * used by get_latest_plugin_version() in moodle_plugins_lib.rc (no
     * arguments, cwd set to the Moodle root, called via its full path).
     * Every package_* component is routed here (and must ship the script);
     * any other component is routed here too if - and only if - it happens
     * to ship that same script.
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

        if ($currentversion !== null && ($skipReason = $this->sentinelSkipReason($currentversion)) !== null) {
            return "SKIP   $component: pinned to version $currentversion ($skipReason)";
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
     * Handle a component whose latest version is resolved through
     * install_plugins.php's own PHP-class package_base convention -
     * `<component>/<component>.php` defining `install_plugins\<component>
     * extends package_base` (e.g. package_kaltura/package_kaltura.php,
     * which talks to the GitHub releases API rather than moodle.org).
     *
     * These classes call back into install_plugins.php's own moodle::,
     * helper::, and cli_output:: statics and are only ever meant to run
     * inside that script, so rather than reimplementing package_base here,
     * this shells out to `php install_plugins.php get-latest-version
     * <component>` (see runInstallPluginsPhpGetLatestVersion()) and lets
     * install_plugins.php's own package_base::get_handler() resolve it -
     * exactly the same class instantiation install_plugins.php's own
     * `update-versions` / `plugin-update-version` commands use.
     *
     * @throws \RuntimeException
     */
    private function updateInstallPluginsPhpComponent(string $component, string $componentdir, string $basedir, bool $dryRun, OutputInterface $output): string
    {
        $versionfile = $componentdir . '/version';
        $currentversion = $this->readVersionFile($versionfile);

        if ($currentversion !== null && ($skipReason = $this->sentinelSkipReason($currentversion)) !== null) {
            return "SKIP   $component: pinned to version $currentversion ($skipReason)";
        }

        $installPluginsPhp = $this->installPluginsScriptOption ?? ($basedir . '/install_plugins.php');
        if (!is_file($installPluginsPhp)) {
            throw new \RuntimeException(
                "$component ships a PHP package handler ($component/$component.php) but install_plugins.php " .
                "was not found at $installPluginsPhp - pass --install-plugins-script to point at it.",
            );
        }

        $latest = $this->runInstallPluginsPhpGetLatestVersion($installPluginsPhp, $component);

        return $this->applyVersion($component, $versionfile, $currentversion, $latest, $dryRun);
    }

    /**
     * Runs `php <install_plugins.php> get-latest-version <component>` and
     * returns its resolved latest version.
     *
     * Deliberately keeps stdout and stderr on separate pipes (unlike
     * runGetLatestPluginVersionScript()'s `2>&1`): install_plugins.php's
     * cli_output::warning()/info()/verbose() all write to stdout, and a
     * package_base subclass is free to call them (moodle_official::
     * get_latest_plugin_version() does, for its "no exact release match,
     * falling back to X" case) - merging that into the same stream as the
     * version number would break the "stdout is exactly one integer"
     * contract. install_plugins.php's `get-latest-version` command sends
     * any such diagnostic noise to stderr and keeps stdout to just the
     * number for exactly this reason; a nonzero exit surfaces stderr (with
     * that noise included) in the error message instead.
     *
     * @throws \RuntimeException
     */
    private function runInstallPluginsPhpGetLatestVersion(string $installPluginsPhp, string $component): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($installPluginsPhp)
            . ' get-latest-version ' . escapeshellarg($component);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("could not start install_plugins.php for $component");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitcode = proc_close($process);

        if ($exitcode !== 0) {
            $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            throw new \RuntimeException(
                "install_plugins.php get-latest-version $component exited with status $exitcode: $detail",
            );
        }

        $latest = trim($stdout);
        if (!preg_match('/^-?[0-9]+$/', $latest)) {
            throw new \RuntimeException(
                "install_plugins.php get-latest-version did not report a valid integer version for $component: '$latest'",
            );
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

    /**
     * Whether $currentversion pins a component to a "don't touch it" state
     * rather than a real, bumpable version - and if so, a short label
     * describing why, for the SKIP message.
     *
     * Matches, case-insensitively:
     *   - "0" or "uninstall"      (plugin:list-apply's SENTINEL_UNINSTALL)
     *   - "-1" or "remove-files"  (plugin:list-apply's SENTINEL_REMOVE_FILES)
     *   - install_plugins.php's plain `$current_version <= 0` rule: any
     *     other non-positive integer (e.g. a hand-edited "-2") is treated
     *     the same way, so a repo shared between the two tools can't end
     *     up with one of them silently reviving a plugin the other
     *     considers pinned off.
     *
     * @return string|null a human-readable reason, or null if $currentversion
     *   is an ordinary version that update logic should proceed with
     */
    private function sentinelSkipReason(string $currentversion): ?string
    {
        $trimmed = trim($currentversion);
        $lower = strtolower($trimmed);

        if ($trimmed === self::SENTINEL_UNINSTALL || $lower === self::SENTINEL_UNINSTALL_STR) {
            return 'marked for uninstall';
        }

        if ($trimmed === self::SENTINEL_REMOVE_FILES || $lower === self::SENTINEL_REMOVE_FILES_STR) {
            return 'marked for remove-files-only';
        }

        if (preg_match('/^-?[0-9]+$/', $trimmed) === 1 && (int) $trimmed <= 0) {
            return 'not a positive version';
        }

        return null;
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
