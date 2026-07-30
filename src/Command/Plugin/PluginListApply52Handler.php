<?php
/**
 * moosh2 — Moodle Shell
 *
 * Ported from moosh's Moosh\Command\Generic\Plugin\PluginListApply.
 *
 * Differences from the original moosh command, all intentional:
 *   - No dry-run flag existed in the original (it always wrote). This port
 *     gates every write behind moosh2's global --run flag, matching
 *     plugin:install/plugin:uninstall - without --run this only previews.
 *   - `-m|--moodle-root` is gone: moosh2 already bootstraps a single
 *     Moodle site per invocation (--moodle-path), so $CFG->dirroot IS the
 *     Moodle root - there's no separate concept to configure here.
 *   - Component install paths are resolved via core_component::get_plugin_types()
 *     (the same live Moodle API plugin:install already uses) instead of a
 *     hand-maintained ~40-row prefix table. This is both more correct
 *     (Moodle's subplugin types are discovered dynamically) and immune to
 *     drifting out of sync with a future Moodle release.
 *   - Install/uninstall call Moodle's plugin manager APIs directly
 *     in-process (core_plugin_manager::uninstall_plugin(), the same
 *     download+extract+upgrade_noncore() flow as PluginInstall52Handler)
 *     instead of shelling out to a second `moosh` process and grepping its
 *     stdout for magic strings. As a direct consequence, the original's
 *     text-matching recovery paths (cannotdowngrade retry, "detected
 *     misplaced plugin" / install_plugins.php fallback, stale
 *     plugins.json auto-refresh-and-retry, a 403-from-moodle.org hint, an
 *     antivirus_clamav false-positive swallow) have no equivalent here and
 *     are dropped - errors now surface directly as PHP exceptions with a
 *     clear message instead of being pattern-matched out of subprocess
 *     output that no longer exists in this architecture.
 *   - No Moosh\PluginChecksum step (no moosh2 equivalent, out of scope for
 *     this port).
 *
 * package_* directories are unaffected by any of the above: their
 * install/uninstall/path-resolution is still delegated to the fixed shell
 * scripts under <component>/bin/, called with cwd = the Moodle root,
 * exactly as moodle_plugins_lib.rc / the original moosh command did.
 *
 * Like the original, this aborts on the first component that fails by
 * default (--keep-going opts into aggregating failures and processing the
 * rest instead).
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\ClamscanRunner;
use Moosh2\Service\PluginApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginListApply52Handler extends BaseHandler
{
    private const SENTINEL_REMOVE_FILES = '-1';
    private const SENTINEL_UNINSTALL = '0';

    /** @var string absolute path to the declarative plugin list directory (--directory) */
    private string $configPluginDirectory = '';

    /** @var string absolute Moodle root ($CFG->dirroot) */
    private string $moodleroot = '';

    private bool $dryRun = true;
    private ?string $proxy = null;

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin_name', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Zero or more Frankenstyle component names. None given: every subdirectory of --directory is applied.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory holding one subdirectory per plugin (the declarative plugin list).', '.')
            ->addOption('keep-going', 'k', InputOption::VALUE_NONE, "Don't abort on the first component that fails; process the rest and report every failure at the end.")
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.');

        if ($command instanceof \Moosh2\Command\BaseCommand) {
            $command->addExampleUsage('Preview applying every plugin directory found in the current directory', '');
            $command->addExampleUsage('Actually apply them', '--run');
            $command->addExampleUsage('Apply only mod_board', '--run mod_board');
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG;

        $this->dryRun = !$input->getOption('run');
        $this->proxy = $input->getOption('proxy');
        $this->moodleroot = rtrim($CFG->dirroot, '/');

        $basedir = rtrim($input->getOption('directory'), '/');
        if ($basedir === '') {
            $basedir = '/';
        }
        if (!is_dir($basedir)) {
            $output->writeln("<e>Directory not found: $basedir</e>");
            return Command::FAILURE;
        }
        $this->configPluginDirectory = realpath($basedir);

        $components = $input->getArgument('plugin_name');
        if (empty($components)) {
            $components = $this->discoverComponents($this->configPluginDirectory);
        }

        if (empty($components)) {
            $output->writeln("No plugin directories found in {$this->configPluginDirectory}.");
            return Command::SUCCESS;
        }

        if ($this->dryRun) {
            $output->writeln('<info>Dry run — previewing what would be applied (use --run to actually apply):</info>');
        }

        $keepgoing = (bool) $input->getOption('keep-going');
        $failed = [];

        foreach ($components as $component) {
            $componentdir = $this->configPluginDirectory . '/' . $component;
            if (!is_dir($componentdir)) {
                $output->writeln("SKIP    $component: directory not found ($componentdir)");
                $failed[] = $component;
                if (!$keepgoing) {
                    break;
                }
                continue;
            }

            try {
                $this->applyComponent($component, $componentdir, $output);
            } catch (\RuntimeException $e) {
                $output->writeln("ERROR   $component: " . $e->getMessage());
                $failed[] = $component;
                if (!$keepgoing) {
                    break;
                }
            }
        }

        if (!empty($failed)) {
            $output->writeln('Failed component(s): ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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

    // -------------------------------------------------------------------
    // Per-component state machine
    // -------------------------------------------------------------------

    /**
     * @throws \RuntimeException on any failure - caller decides whether to
     *   abort immediately or keep going.
     */
    private function applyComponent(string $component, string $componentdir, OutputInterface $output): void
    {
        $componentpath = $this->getComponentPath($component, $componentdir);
        $current = $this->getInstalledVersion($component, $componentdir, $componentpath);
        $requested = $this->getRequestedVersion($component, $componentdir);

        $output->writeln('-----');
        if ($requested === null || $requested === '') {
            throw new \RuntimeException('could not determine requested version, exiting');
        }
        $output->writeln("$component requested: $requested installed: $current");

        if ($current === $requested) {
            $this->runAlwaysRunHookIfPresent($component, $componentdir, $output);
            $output->writeln("OK      $component: already at $current");
            return;
        }

        if ($requested === self::SENTINEL_REMOVE_FILES) {
            if ($this->dryRun) {
                $output->writeln("WOULD REMOVE $component: files at $componentpath (database left untouched)");
                return;
            }
            $this->removePluginFiles($component, $componentdir, $componentpath, $output);
            $output->writeln("REMOVED $component: files removed (database left untouched)");
            return;
        }

        if ($requested === self::SENTINEL_UNINSTALL) {
            if ((int) $current <= -1) {
                if ($this->dryRun) {
                    $output->writeln("OK      $component: already uninstalled (would still best-effort clean up orphan records)");
                    return;
                }
                $this->uninstallForce($component, $componentdir, $output);
                $output->writeln("OK      $component: already uninstalled, database cleaned up (best effort)");
            } else {
                if ($this->dryRun) {
                    $output->writeln("WOULD UNINSTALL $component (currently $current)");
                    return;
                }
                $this->uninstall($component, $componentdir, $componentpath, $output);
                $output->writeln("REMOVED $component: uninstalled");
            }
            return;
        }

        // requested > 1: install or upgrade.
        if ($this->dryRun) {
            $output->writeln("WOULD INSTALL $component: $current -> $requested (target: $componentpath)");
            return;
        }

        $this->installRequestedVersion($component, $componentdir, $requested, $output);

        $updated = $this->getInstalledVersion($component, $componentdir, $componentpath);
        if ($updated === null || $updated === '') {
            throw new \RuntimeException("requested: $requested could not be installed, exiting");
        }
        if ($updated !== $requested) {
            throw new \RuntimeException("requested: $requested could not be upgraded, $updated is still deployed, exiting");
        }

        $scanresult = $this->scanForMalware($component, $componentpath, $output);
        if ($scanresult !== ClamscanRunner::EXIT_CLEAN) {
            throw new \RuntimeException(
                'malware scan ' . ($scanresult === ClamscanRunner::EXIT_MALWARE_FOUND ? 'found malware' : 'failed')
                . " (exit $scanresult) after installing - see {$this->configPluginDirectory}/.clamav/report/clamav.log",
            );
        }

        $this->addIgnorePathsToGitignore($component, $componentdir, $componentpath);
        $output->writeln("INSTALLED $component: $current -> $requested");
    }

    private function runAlwaysRunHookIfPresent(string $component, string $componentdir, OutputInterface $output): void
    {
        $script = $componentdir . '/bin/install_requested_always_run.sh';
        if (!is_file($script)) {
            return;
        }
        if ($this->dryRun) {
            $output->writeln("(would run bin/install_requested_always_run.sh for $component)");
            return;
        }
        [$lines, $exitcode] = $this->runScript($script, []);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        if ($exitcode !== 0) {
            throw new \RuntimeException("bin/install_requested_always_run.sh exited with status $exitcode");
        }
    }

    // -------------------------------------------------------------------
    // version file reading (requested) / version.php reading (installed)
    // -------------------------------------------------------------------

    /**
     * @throws \RuntimeException if the version file/script exists but its
     *   content isn't a valid integer, or a package_* script fails
     */
    private function getRequestedVersion(string $component, string $componentdir): string
    {
        if (str_starts_with($component, 'package_')) {
            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/get_requested_version.sh', []);
            if ($exitcode !== 0) {
                throw new \RuntimeException('bin/get_requested_version.sh exited with status ' . $exitcode . ': ' . implode("\n", $lines));
            }
            $requested = trim(implode("\n", $lines));
        } else {
            $requested = $this->readVersionFile($componentdir . '/version');
            if ($requested === null) {
                $requested = self::SENTINEL_REMOVE_FILES;
            }
        }

        if (!preg_match('/^-?[0-9]+$/', $requested)) {
            throw new \RuntimeException("requested version is not a valid integer: '$requested'");
        }
        return $requested;
    }

    /**
     * @param string $componentpath absolute install path for this component
     * @return string the installed version as a string, or "-1" if not installed
     * @throws \RuntimeException if version.php exists but $plugin->version couldn't be determined
     */
    private function getInstalledVersion(string $component, string $componentdir, string $componentpath): string
    {
        if (str_starts_with($component, 'package_')) {
            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/get_installed_version.sh', []);
            if ($exitcode !== 0) {
                throw new \RuntimeException('bin/get_installed_version.sh exited with status ' . $exitcode . ': ' . implode("\n", $lines));
            }
            return trim(implode("\n", $lines));
        }

        $versionphp = $componentpath . '/version.php';
        if (!is_file($versionphp)) {
            return '-1';
        }

        $version = $this->evalVersionPhp($versionphp);
        if ($version === null) {
            throw new \RuntimeException("could not get \$plugin->version from $versionphp");
        }
        return (string) $version;
    }

    private function readVersionFile(string $versionfile): ?string
    {
        if (!is_file($versionfile)) {
            return null;
        }
        return rtrim(file_get_contents($versionfile), "\r\n");
    }

    /**
     * Evaluate a plugin's version.php in the current process and return
     * $plugin->version (or $module->version - some plugin types use that
     * variable name instead), without needing a second `php -r` subprocess.
     */
    private function evalVersionPhp(string $versionphp): ?int
    {
        foreach ([
            'MOODLE_INTERNAL' => true,
            'MATURITY_ALPHA' => 50,
            'MATURITY_BETA' => 150,
            'MATURITY_RC' => 180,
            'MATURITY_STABLE' => 200,
            'ANY_VERSION' => 'any',
        ] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        $plugin = new \stdClass();
        $module = new \stdClass();

        $loader = function ($__versionphp_path) use ($plugin, $module) {
            ob_start();
            try {
                include $__versionphp_path;
            } catch (\Throwable) {
                // Swallow: some version.php files reference things only a
                // real Moodle bootstrap provides. We only care whether
                // ->version got set before it blew up.
            } finally {
                ob_end_clean();
            }
        };
        $loader($versionphp);

        if (isset($plugin->version) && is_numeric($plugin->version)) {
            return (int) $plugin->version;
        }
        if (isset($module->version) && is_numeric($module->version)) {
            return (int) $module->version;
        }
        return null;
    }

    // -------------------------------------------------------------------
    // component path resolution
    // -------------------------------------------------------------------

    /**
     * @return string absolute install path for this component
     * @throws \RuntimeException if $component matches no known plugin
     *   type, or a package_* script is missing/fails
     */
    private function getComponentPath(string $component, string $componentdir): string
    {
        if (str_starts_with($component, 'package_')) {
            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/get_component_path.sh', []);
            if ($exitcode !== 0) {
                throw new \RuntimeException('bin/get_component_path.sh exited with status ' . $exitcode . ': ' . implode("\n", $lines));
            }
            $relative = trim(implode("\n", $lines));
            if ($relative === '') {
                throw new \RuntimeException('bin/get_component_path.sh produced no output');
            }
            return $this->moodleroot . '/' . ltrim($relative, '/');
        }

        $split = explode('_', $component, 2);
        if (count($split) !== 2) {
            throw new \RuntimeException("unknown component $component (expected type_name format)");
        }
        [$type, $name] = $split;

        $pluginTypes = \core_component::get_plugin_types();
        if (!isset($pluginTypes[$type])) {
            throw new \RuntimeException("unknown component $component (no plugin type '$type')");
        }

        return $pluginTypes[$type] . '/' . $name;
    }

    // -------------------------------------------------------------------
    // uninstall / remove-files
    // -------------------------------------------------------------------

    private function removePluginFiles(string $component, string $componentdir, string $componentpath, OutputInterface $output): void
    {
        if (str_starts_with($component, 'package_')) {
            $this->runPackageUninstallScript($component, $componentdir, $output);
            return;
        }

        if (is_file($componentpath . '/.git')) {
            $output->writeln("plugin $component is managed by git - leaving as is");
            return;
        }
        if (!is_file($componentpath . '/version.php')) {
            // Nothing installed to remove.
            return;
        }

        $output->writeln("Deleting files for $component in $componentpath");
        \fulldelete($componentpath);

        $cwd = getcwd();
        chdir($this->moodleroot);
        exec('git submodule update --recursive --init 2>&1');
        chdir($cwd);
    }

    private function uninstall(string $component, string $componentdir, string $componentpath, OutputInterface $output): void
    {
        if (str_starts_with($component, 'package_')) {
            $this->runPackageUninstallScript($component, $componentdir, $output);
            return;
        }

        global $CFG;
        require_once $CFG->libdir . '/adminlib.php';
        require_once $CFG->libdir . '/upgradelib.php';

        $output->writeln("Uninstalling $component");

        $pluginman = \core_plugin_manager::instance();
        $pluginfo = $pluginman->get_plugin_info($component);

        if ($pluginfo !== null && $pluginman->can_uninstall_plugin($pluginfo->component)) {
            $progress = new \progress_trace_buffer(new \text_progress_trace(), false);
            $pluginman->uninstall_plugin($pluginfo->component, $progress);
            $progress->finished();
        } else {
            $output->writeln("WARN: Moodle reports $component cannot be uninstalled through the plugin manager - removing files only");
        }

        if (is_file($componentpath . '/.git')) {
            $output->writeln("plugin $component is managed by git - leaving as is");
        } elseif (is_dir($componentpath)) {
            \fulldelete($componentpath);
        }

        $this->resetPluginCaches();
    }

    /**
     * Best-effort DB cleanup for a component that's already gone from
     * disk. Always considered a success, matching uninstall_force() in
     * the original moodle_plugins_lib.rc: this path means "already gone,
     * just make sure the database agrees", not "must succeed".
     */
    private function uninstallForce(string $component, string $componentdir, OutputInterface $output): void
    {
        if (str_starts_with($component, 'package_')) {
            $this->runPackageUninstallScript($component, $componentdir, $output);
            return;
        }

        global $DB;

        try {
            $pluginman = \core_plugin_manager::instance();
            $pluginfo = $pluginman->get_plugin_info($component);
            if ($pluginfo !== null && $pluginman->can_uninstall_plugin($pluginfo->component)) {
                $progress = new \progress_trace_buffer(new \text_progress_trace(), false);
                $pluginman->uninstall_plugin($pluginfo->component, $progress);
                $progress->finished();
            } elseif ($DB->get_record('config_plugins', ['plugin' => $component])) {
                $DB->delete_records('config_plugins', ['plugin' => $component]);
            }
            $this->resetPluginCaches();
        } catch (\Throwable $e) {
            $output->writeln("WARN: best-effort cleanup for $component failed, ignoring: " . $e->getMessage());
        }
    }

    /**
     * bin/uninstall_requested_version.sh is used identically by uninstall(),
     * uninstallForce(), AND removePluginFiles() in the original. It's also
     * the one package_* script that commonly doesn't exist yet, so this
     * gives one clear, specific error for that.
     *
     * @throws \RuntimeException if the script is missing or fails
     */
    private function runPackageUninstallScript(string $component, string $componentdir, OutputInterface $output): void
    {
        $script = $componentdir . '/bin/uninstall_requested_version.sh';
        if (!is_file($script)) {
            throw new \RuntimeException(
                "could not find $component/bin/uninstall_requested_version.sh - this package_* plugin " .
                'has no uninstall script yet; add one before requesting version 0 or -1 for it.',
            );
        }
        [$lines, $exitcode] = $this->runScript($script, [$component]);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        if ($exitcode !== 0) {
            throw new \RuntimeException("bin/uninstall_requested_version.sh exited with status $exitcode");
        }
    }

    // -------------------------------------------------------------------
    // install / upgrade
    // -------------------------------------------------------------------

    /**
     * @param int $depth internal recursion guard for 'requires' resolution
     * @throws \RuntimeException
     */
    private function installRequestedVersion(string $component, string $componentdir, string $requestedversion, OutputInterface $output, int $depth = 0): void
    {
        if ($depth > 5) {
            throw new \RuntimeException("dependency resolution recursion too deep for $component - possible circular 'requires'");
        }

        if (str_starts_with($component, 'package_')) {
            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/install_requested_version.sh', [$component, $requestedversion]);
            foreach ($lines as $line) {
                $output->writeln($line);
            }
            if ($exitcode !== 0) {
                throw new \RuntimeException("bin/install_requested_version.sh exited with status $exitcode");
            }
            return;
        }

        $this->installRequiresFileDependencies($component, $componentdir, $output, $depth);

        $componentpath = $this->getComponentPath($component, $componentdir);

        global $CFG;
        require_once $CFG->libdir . '/adminlib.php';
        require_once $CFG->libdir . '/upgradelib.php';
        require_once $CFG->libdir . '/filelib.php';

        $client = new PluginApiClient($this->proxy);
        $version = $client->findBestVersion($component, (string) moodle_major_version(), $requestedversion, true);

        $tempDir = sys_get_temp_dir() . '/moosh_plugin_list_apply_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $output->writeln("Downloading $component {$version->version}");
            $zipFile = $tempDir . '/' . $component . '.zip';
            $client->downloadFile($version->downloadurl, $zipFile);

            $extractDir = $tempDir . '/extracted';
            mkdir($extractDir, 0755, true);
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new \RuntimeException("Failed to open ZIP archive for $component.");
            }
            $zip->extractTo($extractDir);
            $zip->close();

            $extractedPluginDir = $this->findPluginDir($extractDir);
            if ($extractedPluginDir === null) {
                throw new \RuntimeException("The ZIP for $component does not contain a valid plugin (no version.php found).");
            }

            if (file_exists($componentpath)) {
                $output->writeln("Removing existing directory $componentpath");
                \fulldelete($componentpath);
            }

            $output->writeln("Installing to $componentpath");
            if (!@rename($extractedPluginDir, $componentpath)) {
                $error = error_get_last();
                throw new \RuntimeException("Failed to install $component to $componentpath: " . ($error['message'] ?? 'unknown error'));
            }
        } finally {
            $this->removeDirectory($tempDir);
        }

        $this->resetPluginCaches();
    }

    /**
     * Recursively installs each component listed in <componentdir>/requires
     * (one Frankenstyle name per line, '#' comments and blank lines ignored)
     * before the component itself gets installed.
     *
     * @throws \RuntimeException if a requirement is missing, marked for
     *   uninstall, or its own install fails
     */
    private function installRequiresFileDependencies(string $component, string $componentdir, OutputInterface $output, int $depth): void
    {
        $requiresfile = $componentdir . '/requires';
        if (!is_file($requiresfile)) {
            return;
        }

        foreach (file($requiresfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $requiredcomponent = trim($line);
            if ($requiredcomponent === '' || $requiredcomponent[0] === '#') {
                continue;
            }

            $requiredcomponentdir = dirname($componentdir) . '/' . $requiredcomponent;
            if (!is_dir($requiredcomponentdir)) {
                throw new \RuntimeException("$component requires $requiredcomponent but its directory was not found ($requiredcomponentdir)");
            }

            $requiredpath = $this->getComponentPath($requiredcomponent, $requiredcomponentdir);
            $requiredcurrent = $this->getInstalledVersion($requiredcomponent, $requiredcomponentdir, $requiredpath);
            $requiredrequested = $this->getRequestedVersion($requiredcomponent, $requiredcomponentdir);

            $output->writeln("Installing requirement $requiredcomponent for $component (requested: $requiredrequested, installed: $requiredcurrent)");

            if ($requiredcurrent !== $requiredrequested) {
                if ($requiredrequested === self::SENTINEL_UNINSTALL) {
                    throw new \RuntimeException("$component requires $requiredcomponent but $requiredcomponent is marked for uninstall");
                }
                $this->installRequestedVersion($requiredcomponent, $requiredcomponentdir, $requiredrequested, $output, $depth + 1);
            }
        }
    }

    // -------------------------------------------------------------------
    // malware scan (reuses Moosh2\Service\ClamscanRunner)
    // -------------------------------------------------------------------

    /**
     * @return int one of ClamscanRunner::EXIT_CLEAN / EXIT_MALWARE_FOUND / EXIT_ERROR
     */
    private function scanForMalware(string $component, string $componentpath, OutputInterface $output): int
    {
        $binary = ClamscanRunner::findBinary();
        if ($binary === null) {
            $output->writeln("WARN: clamscan not found on system, skipping malware scan for $component");
            return ClamscanRunner::EXIT_CLEAN;
        }

        if (!file_exists($componentpath)) {
            throw new \RuntimeException("cannot scan $component: target path does not exist: $componentpath");
        }

        $reportdir = $this->configPluginDirectory . '/.clamav/report';
        $rulesdir = $this->configPluginDirectory . '/.clamav/rules';
        $exceptionsdir = $this->configPluginDirectory . '/.clamav/exceptions';
        @mkdir($reportdir, 0755, true);
        @mkdir($rulesdir, 0755, true);
        @mkdir($exceptionsdir, 0755, true);

        $databases = [];
        if ($this->dirHasFilesMatching('/var/lib/clamav', ['cvd', 'cld', 'cud'])) {
            $databases[] = '/var/lib/clamav';
        }
        if ($this->dirHasFilesMatching($rulesdir, ['yar', 'yara'])) {
            $databases[] = $rulesdir;
        }
        if ($this->dirHasFilesMatching($exceptionsdir, ['ign2', 'fp', 'yar', 'yara', 'ndb', 'hdb'])) {
            $databases[] = $exceptionsdir;
        }

        $output->writeln("Starting malware scan for $component at $componentpath");
        $options = ['database' => $databases, 'infected' => true, 'log' => $reportdir . '/clamav.log'];
        [$exitcode, $lines] = ClamscanRunner::scan($binary, $componentpath, $options);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        return $exitcode;
    }

    private function dirHasFilesMatching(string $dir, array $extensions): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------
    // .gitignore bookkeeping
    // -------------------------------------------------------------------

    private function addIgnorePathsToGitignore(string $component, string $componentdir, string $componentpath): void
    {
        if (str_starts_with($component, 'package_')) {
            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/get_component_ignore_path.sh', []);
            if ($exitcode !== 0) {
                throw new \RuntimeException('bin/get_component_ignore_path.sh exited with status ' . $exitcode . ': ' . implode("\n", $lines));
            }
            $ignorepaths = trim(implode("\n", $lines));
        } else {
            $ignorepaths = $componentpath;
        }

        if ($ignorepaths === '') {
            return;
        }

        foreach (preg_split('/\r\n|\r|\n/', $ignorepaths) as $ignorepath) {
            $ignorepath = trim($ignorepath);
            if ($ignorepath === '') {
                continue;
            }
            // package_* scripts report paths relative to the Moodle root;
            // non-package componentpath is already absolute.
            $absolute = str_starts_with($ignorepath, '/') ? $ignorepath : $this->moodleroot . '/' . $ignorepath;
            $gitignore = $absolute . '/.gitignore';
            file_put_contents($gitignore, "\n*", FILE_APPEND);
            @chmod($gitignore, 0644 | (fileperms($gitignore) & 0777) | 0044);
        }
    }

    // -------------------------------------------------------------------
    // package_* bin/ script execution
    // -------------------------------------------------------------------

    /**
     * Run a package_ plugin's bin/ script with cwd = the Moodle root,
     * matching moodle_plugins_lib.rc's calling convention exactly.
     *
     * @return array{0: string[], 1: int}
     * @throws \RuntimeException if the script doesn't exist or isn't executable
     */
    private function runScript(string $script, array $args): array
    {
        if (!is_file($script)) {
            throw new \RuntimeException('could not find ' . basename(dirname($script)) . '/bin/' . basename($script));
        }
        if (!is_executable($script)) {
            throw new \RuntimeException(
                basename(dirname($script)) . '/bin/' . basename($script) . ' is not executable ' .
                '(zip archives often lose the exec bit - chmod +x it)',
            );
        }

        $cmd = escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $cwd = getcwd();
        chdir($this->moodleroot);
        exec($cmd . ' 2>&1', $output, $exitcode);
        chdir($cwd);

        return [$output, $exitcode];
    }

    // -------------------------------------------------------------------
    // shared small helpers
    // -------------------------------------------------------------------

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

    private function resetPluginCaches(): void
    {
        global $CFG;
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        $cacheFile = $CFG->cachedir . '/core_component.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
        \core_component::reset(true);
        \core_plugin_manager::reset_caches();
        upgrade_noncore(true);
    }

    private function removeDirectory(string $dir): void
    {
        if (function_exists('fulldelete')) {
            fulldelete($dir);
            return;
        }
        if (!is_dir($dir)) {
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
