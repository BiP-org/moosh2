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
 * Patch support - ported from install_plugins.php's plugin_install(),
 * get_patches()/are_patches_applied()/apply_patches() - was added on top
 * of the original moosh command, which had none. *.patch files (-p1,
 * `git diff` format) next to a component's `version` file are applied,
 * sorted by filename, after every (re)install of that component via
 * `git apply --directory=... -v`. A `.patches-applied` fingerprint file
 * inside the installed component directory tracks what was last applied;
 * when the patches change or disappear, the component is redownloaded
 * (even if the requested version itself didn't change) and the current
 * patches applied to the fresh code, since reverting old patches in
 * place isn't attempted. package_* components are patched too, see
 * "Package patching" below.
 *
 * Package patching - ported from install_plugins.php's package_install(),
 * which patches a package with the same get_patches()/
 * are_patches_applied()/apply_patches() machinery as a plain plugin. The
 * *.patch files sit next to the package's bin/ directory, exactly like a
 * plain component's, and use the same .patches-applied fingerprint. What
 * differs is only where things are anchored:
 *   - a package bundles several plugin directories and its patches may
 *     touch any of them, so its patch paths are relative to the Moodle
 *     REPOSITORY root (what `git diff` in a Moodle checkout prints - with
 *     the split public/ layout that is the directory ABOVE $CFG->dirroot,
 *     so the paths start with public/), not to the component directory;
 *   - the fingerprint is kept in the directory bin/get_component_path.sh
 *     reports (the package's anchor directory), which therefore has to
 *     exist after the install whenever patches are configured;
 *   - the package's own bin/install_requested_version.sh does the install
 *     and MUST replace its plugin directories rather than merge into them:
 *     unlike the original's package_base::remove_files() there is no
 *     files-only removal script in the bin/ contract (bin/uninstall_-
 *     requested_version.sh also drops the database), so a re-run of the
 *     install script is what gives the patches a fresh copy of the code.
 *     A script that merges would leave old patched files behind - a patch
 *     that then no longer applies fails loudly, one that still does would
 *     go unnoticed.
 * The fingerprint is deleted before the install script runs and only
 * written again once every patch applied, so an install or patch that
 * dies half way is redone by the next run.
 *
 * Like the original, this aborts on the first component that fails by
 * default (--keep-going opts into aggregating failures and processing the
 * rest instead).
 *
 * Orphan/drift tracking - ported from install_plugins.php's
 * .downloaded-non-core-plugin marker file, get_downloaded_plugin_dirs()
 * and remove_unwanted_plugins(). Every non-package_* and package_*
 * component that ends this run installed (freshly downloaded OR already
 * at the requested version - matching plugin_install()'s "plugin is
 * uptodate, ... mark as downloaded" branch in the original) gets a
 * `.downloaded-non-core-plugin` marker file touched in its install
 * directory. Unlike the original there is no RUN_NUMBER written into it -
 * the file's mtime alone is enough metadata to tell when it was last
 * confirmed present, and touch() both creates and refreshes it in one
 * call. This also backfills the marker for any component that was
 * already correctly installed before this feature existed, or installed
 * by hand outside moosh2 - the first list-apply run after upgrading
 * marks it as tracked instead of flagging it as an orphan.
 * A git-managed directory (see isGitManaged()) never gets a marker, and
 * any stale marker found in one is removed - it isn't "downloaded",
 * whatever process manages it owns its lifecycle.
 * After every component in the declarative list has been processed,
 * every `.downloaded-non-core-plugin` marker under $CFG->dirroot whose
 * directory is NOT the resolved install path of a component in this run's
 * list is an orphan: something a previous run downloaded that is no
 * longer declared. --warn-orphans (the default) only reports these,
 * exactly like remove_unwanted_plugins($execute = false) in the original;
 * --prune-orphans deletes them (still gated behind the global --run flag,
 * same as every other destructive action here).
 *
 * git-submodule guard - install_plugins.php checks `file_exists($dir .
 * '/.git')` at the very top of plugin_install(), before ever downloading
 * anything, and leaves a git-managed directory alone. The ported
 * removePluginFiles()/uninstall() here had an equivalent check, but it
 * only tested is_file(...'/.git') - which matches a submodule (where
 * .git is a file pointing at the superproject's gitdir) but silently
 * misses a plain git clone/checkout placed there by hand (where .git is
 * a directory), AND the install/upgrade path (installRequestedVersion())
 * had no check at all, so a git-managed directory could be silently
 * overwritten by a downloaded zip. isGitManaged() now checks for either
 * form (file_exists(), not is_file()) and is used consistently by all
 * three: removePluginFiles(), uninstall(), and installRequestedVersion().
 *
 * Reuninstall recovery (--reuninstall) - ported from install_plugins.php's
 * plugins_reuninstall_all(), get_uninstall_version(),
 * get_last_managed_version() and the $reuninstall branch of
 * plugin_uninstall(). A component requested as "uninstall" (0) whose files
 * are gone can't be uninstalled properly: without its files Moodle can
 * neither run db/uninstall.php nor drop the tables of db/install.xml, so
 * the normal path (uninstallForce()) only removes what it can reach and
 * leaves the rest behind - and a component whose version row is gone from
 * {config_plugins} is "Unknown plugin" to Moodle, so nothing gets cleaned
 * up at all. --reuninstall puts such components back completely and then
 * uninstalls them the way it should have happened. It is a manual mode,
 * never part of a normal run: it restores and uninstalls every
 * uninstall-requested component again, whether or not anything was left
 * behind, which a deploy pipeline must not do on every run.
 *
 * The version to restore is the one the database holds, else the one in
 * <component>/version_uninstall (written once, from whatever the other
 * sources knew - commit it), else the newest positive value of the
 * component's `version` file in the git history of the plugin list
 * (only there in a full checkout, a shallow CI clone has no history).
 * Where the database and version_uninstall disagree the run stops: silently
 * uninstalling a version nobody wrote down is worse than stopping.
 *
 * Two departures from the original, both forced by moosh2's install path:
 *   - the version row is written to {config_plugins} BEFORE the files are
 *     put back (the original does it after). installRequestedVersion()
 *     ends in upgrade_noncore(), which treats a plugin without a version
 *     row as a new installation and runs its install.xml - against
 *     tables that still exist, which is exactly the situation this mode
 *     exists for, that fails with "table already exists".
 *   - the files are restored without resolving the plugin's `requires`
 *     file and version.php dependencies (installRequestedVersion()'s
 *     $skipDependencies): the original's plugin_install() never did, and
 *     here it would install (and even add to the plugin list) things for a
 *     plugin that is about to be uninstalled, or refuse because a
 *     dependency is itself requested as uninstall.
 * The work runs in phases over all selected components at once - plan,
 * restore, purge caches, uninstall, delete files - and stops before
 * anything is uninstalled or deleted if a restore failed (unless
 * --keep-going), because an incomplete run would leave plugins registered
 * in the database without their files. Unlike the original, files are only
 * deleted for a component whose database uninstall actually worked: they
 * belong to a plugin that is still installed otherwise.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\ClamscanRunner;
use Moosh2\Service\PhpMusselRunner;
use Moosh2\Service\PhpMusselSignatureManager;
use Moosh2\Service\PluginApiClient;
use Moosh2\Service\PluginZipCache;
use Moosh2\Service\VersionPhpParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginListApply52Handler extends BaseHandler
{
    private const SENTINEL_REMOVE_FILES = '-1';
    private const SENTINEL_UNINSTALL = '0';

    // String versions of sentinels for more readable config files
    private const SENTINEL_REMOVE_FILES_STR = 'remove-files';
    private const SENTINEL_UNINSTALL_STR = 'uninstall';

    /**
     * Marker file touched (created, or its mtime refreshed) in a
     * component's install directory whenever this command confirms it at
     * the requested version - ported from install_plugins.php's
     * .downloaded-non-core-plugin, minus the RUN_NUMBER content (mtime
     * alone is enough "last verified" metadata here). Powers orphan
     * detection - see the class docblock.
     */
    private const MARKER_FILENAME = '.downloaded-non-core-plugin';

    /**
     * Scanner name → relative path (from $configPluginDirectory) of the
     * report log that scanner writes to. Used to build the post-install
     * failure message so it points at the log that actually exists, not
     * at every scanner's log regardless of which one ran.
     */
    private const SCANNER_REPORT_PATHS = [
        'clamscan'  => '.clamav/report/clamav.log',
        'phpmussel' => '.phpmussel/report/phpmussel.log',
    ];

    /** @var string absolute path to the declarative plugin list directory (--directory) */
    private string $configPluginDirectory = '';

    /** @var string absolute Moodle root ($CFG->dirroot) */
    private string $moodleroot = '';

    private bool $dryRun = true;
    private ?string $proxy = null;
    private ?string $token = null;

    /** @var string[] which malware scanners to run after each install ('clamscan' and/or 'phpmussel') */
    private array $scanners = ['clamscan'];

    /** @var array<string,string> --scanner token (public name) => internal scanner key */
    private const SCANNER_ALIASES = ['clamav' => 'clamscan', 'phpmussel' => 'phpmussel'];

    /** @var bool stashed --archive-fallback (issue #82 §3.2) */
    private bool $archiveFallback = false;

    /** @var bool stashed --suppress-lifecycle-warnings (issue #82 §6.3) */
    private bool $suppressLifecycleWarnings = false;

    /** @var string stashed --archive-annotation-level ("warning"|"notice"), issue #82 §6.3 */
    private string $archiveAnnotationLevel = 'warning';

    /** @var array<string,string> component => archived version, for this run's end-of-run summary (§6.3) */
    private array $archivedComponents = [];

    /** @var bool stashed --prune-orphans (default false = warn-only, matching --warn-orphans) */
    private bool $pruneOrphans = false;

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin_name', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Zero or more Frankenstyle component names. None given: every subdirectory of --directory is applied.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory holding one subdirectory per plugin (the declarative plugin list).', 'plugins')
            ->addOption('keep-going', 'k', InputOption::VALUE_NONE, "Don't abort on the first component that fails; process the rest and report every failure at the end.")
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port). You may also use env var http_proxy.')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Moodle Marketplace API token, sent as a Bearer token only for requests to marketplace.moodle.com. Defaults to env var MOODLE_MARKETPLACE_TOKEN.')
            ->addOption('scanner', null, InputOption::VALUE_REQUIRED, 'Malware scanner(s) to run after each install: clamav, phpmussel, a comma-separated combination of those (e.g. clamav,phpmussel), all (both), or none.', 'clamav')
            ->addOption('archive-fallback', null, InputOption::VALUE_NONE,
                "When a component can't be resolved on moodle.org (withdrawn/expired version), install from "
                . "<component>/archive/*.zip if one was archived (see plugin:list-update --archive) instead "
                . "of failing.")
            ->addOption('archive-annotation-level', null, InputOption::VALUE_REQUIRED,
                'GitHub Actions annotation level for the archived-component lifecycle summary: '                . '"warning" (default) or "notice".', 'warning')
            ->addOption('suppress-lifecycle-warnings', null, InputOption::VALUE_NONE,
                'Silence both the missing-checksum warning (see --archive-fallback) and the archived-'
                . 'component summary for this run - e.g. a baseline "reproduce current production state" '
                . 'run that should not repeat warnings a later, real upgrade run will already surface.')
            ->addOption('prune-orphans', null, InputOption::VALUE_NONE,
                'Delete directories this command previously downloaded (tracked via a '
                . '.downloaded-non-core-plugin marker file) but that are no longer in this run\'s '
                . 'declarative list, instead of only warning about them. Still gated behind --run - '
                . 'without it this only previews which directories would be deleted. A git-managed '
                . 'directory is never touched, marker or not.')
            ->addOption('warn-orphans', null, InputOption::VALUE_NONE,
                'Only warn about orphaned plugin directories (see --prune-orphans) instead of deleting '
                . 'them. This is the default; the flag exists so a script can pass it explicitly.')
            ->addOption('reuninstall', null, InputOption::VALUE_NONE,
                'Recovery mode: for components requested as uninstall (0), put the plugin back completely '
                . '(its files at the version the database, <component>/version_uninstall or the git history of the '
                . 'plugin list knows, plus its version row) and uninstall it properly, so that the tables and data '
                . 'an earlier uninstall could not reach without the plugin files are removed too. Only these '
                . 'components are processed, nothing else is installed, upgraded or pruned. Manual use only: it '
                . 'does this every run, whether or not anything was left behind. Still gated behind --run.');

        if ($command instanceof \Moosh2\Command\BaseCommand) {
            $command->addExampleUsage('Preview applying every plugin directory found in the current directory', '');
            $command->addExampleUsage('Actually apply them', '--run');
            $command->addExampleUsage('Apply only mod_board', '--run mod_board');
            $command->addExampleUsage('Scan installs with both ClamAV and phpMussel', '--run --scanner=all');
            $command->addExampleUsage('Skip malware scanning entirely', '--run --scanner=none');
            $command->addExampleUsage('Recover a plugin requested as uninstall whose files/version row are already gone', '--run --reuninstall mod_board');
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG;

        $this->dryRun = !$input->getOption('run');
        $this->proxy = $input->getOption('proxy');
        $this->token = $input->getOption('token') ?: (getenv('MOODLE_MARKETPLACE_TOKEN') ?: null);
        $this->moodleroot = rtrim($CFG->dirroot, '/');

        // Validate --directory before --scanner. The scanner value can't be
        // acted on at all if there's no directory to scan, and a bad
        // directory is the more fundamental error - reporting it first
        // avoids sending users off to fix a scanner typo when the real
        // problem is that they pointed at the wrong path.
        $basedir = $input->getOption('directory');
        if ($basedir === 'plugins') {
            // Option wasn't explicitly provided; default to moodleroot/plugins.
            $basedir = $this->moodleroot . '/plugins';
        } else {
            $basedir = rtrim($basedir, '/');
        }

        if ($basedir === '') {
            $basedir = '/';
        }
        if (!is_dir($basedir)) {
            $output->writeln("<e>Directory not found: $basedir</e>");
            return Command::FAILURE;
        }
        $this->configPluginDirectory = realpath($basedir);

        try {
            $this->scanners = $this->parseScannerOption((string) $input->getOption('scanner'));
        } catch (\RuntimeException $e) {
            $output->writeln('<e>' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }

        $this->archiveFallback = (bool) $input->getOption('archive-fallback');
        $this->suppressLifecycleWarnings = (bool) $input->getOption('suppress-lifecycle-warnings');
        $this->archiveAnnotationLevel = strtolower((string) $input->getOption('archive-annotation-level'));
        if (!in_array($this->archiveAnnotationLevel, ['warning', 'notice'], true)) {
            $output->writeln('<e>--archive-annotation-level must be "warning" or "notice"</e>');
            return Command::FAILURE;
        }
        $this->archivedComponents = [];

        $this->pruneOrphans = (bool) $input->getOption('prune-orphans');
        if ($this->pruneOrphans && (bool) $input->getOption('warn-orphans')) {
            $output->writeln('<e>--prune-orphans and --warn-orphans cannot be used together</e>');
            return Command::FAILURE;
        }

        // A different mode, not an add-on to the normal run: it only looks
        // at uninstall-requested components and never runs orphan
        // detection, so an orphan flag next to it would silently do
        // nothing - or worse, look like it did.
        $reuninstall = (bool) $input->getOption('reuninstall');
        if ($reuninstall && ($this->pruneOrphans || (bool) $input->getOption('warn-orphans'))) {
            $output->writeln('<e>--reuninstall cannot be combined with --prune-orphans or --warn-orphans (orphan detection does not run in that mode)</e>');
            return Command::FAILURE;
        }

        $components = $input->getArgument('plugin_name');
        // Orphan detection only makes sense when the full declarative
        // list was scanned - a targeted subset run (explicit component
        // names) would otherwise flag every other legitimately-declared
        // component as an orphan just because it wasn't asked for today.
        $scannedFullList = empty($components);
        if ($scannedFullList) {
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

        if ($reuninstall) {
            return $this->reuninstallComponents($components, !$scannedFullList, $keepgoing, $output);
        }

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
            } catch (\Throwable $e) {
                $output->writeln("ERROR   $component: " . $e->getMessage());
                $failed[] = $component;
                if (!$keepgoing) {
                    break;
                }
            }
        }

        if (!empty($this->archivedComponents) && !$this->suppressLifecycleWarnings) {
            $labels = [];
            foreach ($this->archivedComponents as $comp => $ver) {
                $labels[] = "$comp ($ver)";
            }
            $summary = 'Archived component(s) in use (action needed - no longer available on moodle.org): '
                . implode(', ', $labels);
            if ($this->isRunningInCi()) {
                $output->writeln("::{$this->archiveAnnotationLevel} title=Archived plugin components in use::$summary");
            } else {
                $output->writeln($summary);
            }
        }

        if ($scannedFullList) {
            $this->handleOrphans($components, $output);
        }

        if (!empty($failed)) {
            $output->writeln('Failed component(s): ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------
    // Orphan/drift detection (see class docblock)
    // -------------------------------------------------------------------

    /**
     * @param string[] $components every component in this run's
     *   declarative list (the full list - handle() only calls this when
     *   $scannedFullList is true)
     */
    private function handleOrphans(array $components, OutputInterface $output): void
    {
        $markedDirs = $this->discoverMarkedPluginDirs();
        if (!$markedDirs) {
            return;
        }

        $wantedDirs = $this->buildWantedComponentPaths($components);
        $orphans = array_diff($markedDirs, $wantedDirs);
        if (!$orphans) {
            return;
        }

        sort($orphans);
        foreach ($orphans as $orphanDir) {
            if ($this->isGitManaged($orphanDir)) {
                // Shouldn't normally happen - a git-managed directory
                // never gets a marker written into it in the first
                // place (see touchDownloadedMarker()) - but a marker
                // left over from before a directory became git-managed
                // is possible, so be defensive rather than delete it.
                continue;
            }

            $componentLabel = $this->identifyOrphanComponent($orphanDir);

            if (!$this->pruneOrphans) {
                $output->writeln(
                    "WARN    $componentLabel is installed in $orphanDir, but is not in this run's declarative list",
                );
                $output->writeln(
                    '        => to remove it, restore it in the declarative list with version 0 (uninstall, '
                    . 'incl. database) or -1 (remove files only), or re-run with --prune-orphans --run to '
                    . 'delete the directory',
                );
                continue;
            }

            if ($this->dryRun) {
                $output->writeln("WOULD DELETE orphan $componentLabel: $orphanDir");
                continue;
            }

            $output->writeln("Deleting orphaned plugin directory: $orphanDir ($componentLabel)");
            $this->removeDirectory($orphanDir);
        }
    }

    /**
     * Every directory anywhere under $CFG->dirroot that currently holds a
     * MARKER_FILENAME, as an array of realpath()'d directory paths.
     * Mirrors install_plugins.php's get_downloaded_plugin_dirs(): searches
     * the whole Moodle root (not just this run's --directory target type)
     * so plugins installed under an older layout are still found, and
     * prunes node_modules/.git from the search for speed and to never
     * walk into a submodule's own internals.
     *
     * @return string[]
     */
    private function discoverMarkedPluginDirs(): array
    {
        $cmd = 'find ' . escapeshellarg($this->moodleroot)
            . ' -name node_modules -prune'
            . ' -o -name .git -prune'
            . ' -o -name ' . escapeshellarg(self::MARKER_FILENAME) . ' -print'
            . ' 2>/dev/null';
        exec($cmd, $lines);

        $dirs = [];
        foreach ($lines as $markerFile) {
            $dir = dirname($markerFile);
            $real = realpath($dir);
            $dirs[] = $real !== false ? $real : $dir;
        }
        return array_unique($dirs);
    }

    /**
     * Resolved install-path of every component in $components that could
     * be resolved at all - best-effort, since a component whose plugin
     * type Moodle no longer knows about (see applyComponent()'s handling
     * of that same case) simply can't contribute a path to compare
     * against, and shouldn't abort orphan detection for every other
     * component.
     *
     * @param string[] $components
     * @return string[] realpath()'d directory paths
     */
    private function buildWantedComponentPaths(array $components): array
    {
        $wanted = [];
        foreach ($components as $component) {
            $componentdir = $this->configPluginDirectory . '/' . $component;
            if (!is_dir($componentdir)) {
                continue;
            }
            try {
                $path = $this->getComponentPath($component, $componentdir);
            } catch (\Throwable $e) {
                continue;
            }
            $real = realpath($path);
            $wanted[] = $real !== false ? $real : rtrim($path, '/');
        }
        return $wanted;
    }

    /** Best-effort human-readable label for an orphan directory: its declared component name, or its basename. */
    private function identifyOrphanComponent(string $dir): string
    {
        $versionfile = $dir . '/version.php';
        if (is_file($versionfile)) {
            try {
                $component = VersionPhpParser::parseFile($versionfile)['component'] ?? null;
                if (!empty($component)) {
                    return $component;
                }
            } catch (\Throwable $e) {
                // fall through to the directory name
            }
        }
        return basename($dir);
    }

    /**
     * Create (or, if it already exists, refresh the mtime of) the
     * MARKER_FILENAME in $componentpath - see the class docblock. A
     * no-op for a git-managed directory: any marker found there is
     * removed instead, since a git-managed directory was never
     * "downloaded" by this command and touching it isn't ours to do.
     */
    private function touchDownloadedMarker(string $componentpath): void
    {
        $marker = $componentpath . '/' . self::MARKER_FILENAME;

        if ($this->isGitManaged($componentpath)) {
            if (is_file($marker)) {
                @unlink($marker);
            }
            return;
        }

        if (!is_dir($componentpath)) {
            return;
        }

        touch($marker);
    }

    /**
     * True if $path is managed by git in either form: a plain clone
     * (.git is a directory) or a submodule (.git is a file pointing at
     * the superproject's gitdir). Ported from install_plugins.php's
     * `file_exists($dir . '/.git')` check at the top of plugin_install() -
     * see the class docblock for why this replaces the narrower
     * is_file(...) checks this file previously had in some places only.
     */
    private function isGitManaged(string $path): bool
    {
        return file_exists($path . '/.git');
    }

    /** True under GitHub Actions (and most other CI systems, which set the same $CI convention). */
    private function isRunningInCi(): bool
    {
        $ci = getenv('CI');
        return $ci !== false && $ci !== '' && strtolower($ci) !== 'false';
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
    // Reuninstall recovery (see class docblock)
    // -------------------------------------------------------------------

    /**
     * --reuninstall mode: puts every uninstall-requested component back
     * completely and uninstalls it properly. Ported from
     * install_plugins.php's plugins_reuninstall_all().
     *
     * Runs in phases over all components at once, so an incomplete run
     * never leaves a plugin registered in the database without its files:
     *   0. plan: decide what to restore for each component, changing
     *      nothing (so a dry run can show the whole plan, and a component
     *      that can't be planned stops the run before any change)
     *   1. restore: version row, then files, then malware scan
     *   2. purge caches once (Moodle keeps the installed plugins in an
     *      application cache and would not see what phase 1 wrote)
     *   3. uninstall each restored plugin from the database
     *   4. delete the files of every plugin phase 3 really uninstalled
     *
     * @param string[] $components
     * @param bool $explicit true if the components were named on the
     *   command line rather than taken from the whole plugin list - a
     *   named component that is not requested as uninstall is then an
     *   error, in a full-list run it is simply not this mode's business
     */
    private function reuninstallComponents(array $components, bool $explicit, bool $keepgoing, OutputInterface $output): int
    {
        $failed = [];

        /** @var array<string,array{componentdir:string,componentpath:string,version:int,source:string,writeversionfile:bool}> $plan */
        $plan = [];

        foreach ($components as $component) {
            $componentdir = $this->configPluginDirectory . '/' . $component;
            if (!is_dir($componentdir)) {
                $output->writeln("SKIP    $component: directory not found ($componentdir)");
                $failed[] = $component;
                continue;
            }

            try {
                $requested = $this->getRequestedVersion($component, $componentdir);
                if (!$this->isUninstallSentinel($requested)) {
                    if ($explicit) {
                        $output->writeln(
                            "SKIP    $component: requested is " . $this->getDisplayVersion($requested)
                            . ' - --reuninstall only applies to components requested as uninstall (0)',
                        );
                        $failed[] = $component;
                    }
                    continue;
                }

                // A package is a bundle of plugins with its own versioning,
                // reinstalling it by component name doesn't work. Its own
                // bin/uninstall_requested_version.sh does all of it, which
                // is what a normal run of version 0 calls.
                if (str_starts_with($component, 'package_')) {
                    $output->writeln("SKIP    $component: a package_* is uninstalled by a normal plugin:list-apply run (version 0), not by --reuninstall");
                    continue;
                }

                try {
                    $componentpath = $this->getComponentPath($component, $componentdir);
                } catch (\RuntimeException $e) {
                    // Same case as in applyComponent(): a plugin type Moodle
                    // no longer knows has no place to restore the files to.
                    $output->writeln("SKIP    $component: " . $e->getMessage() . ' (nowhere to restore its files to, nothing to do)');
                    continue;
                }

                if ($this->isGitManaged($componentpath)) {
                    $output->writeln("plugin $component is managed by git - leaving as is");
                    continue;
                }

                $resolved = $this->resolveUninstallVersion($component, $componentdir);
                $plan[$component] = [
                    'componentdir' => $componentdir,
                    'componentpath' => $componentpath,
                    'version' => $resolved['version'],
                    'source' => $resolved['source'],
                    'writeversionfile' => $resolved['writeversionfile'],
                ];
            } catch (\Throwable $e) {
                $output->writeln("ERROR   $component: " . $e->getMessage());
                $failed[] = $component;
            }
        }

        if ($failed && !$keepgoing) {
            $output->writeln('Aborting before anything was restored, uninstalled or deleted, these components could not be planned: ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        if (empty($plan)) {
            $output->writeln('No component requested as uninstall to reuninstall.');
            if ($failed) {
                $output->writeln('Failed component(s): ' . implode(', ', $failed));
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        $output->writeln('Reinstalling ' . count($plan) . ' plugin(s) to uninstall them properly');

        if ($this->dryRun) {
            foreach ($plan as $component => $p) {
                $output->writeln(
                    "WOULD REUNINSTALL $component: restore version {$p['version']} (from {$p['source']}), "
                    . 'register it in the database if missing, uninstall it completely, delete its files'
                    . ($p['writeversionfile'] ? ", write $component/version_uninstall" : ''),
                );
            }
            if ($failed) {
                $output->writeln('Failed component(s): ' . implode(', ', $failed));
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        // Phase 1: restore. Collect the failures instead of stopping at the
        // first one, they are all reported together.
        $restored = [];
        $restorefailed = [];
        foreach ($plan as $component => $p) {
            try {
                $this->restoreForReuninstall($component, $p['componentdir'], $p['componentpath'], $p['version'], $p['writeversionfile'], $output);
                $restored[$component] = $p;
            } catch (\Throwable $e) {
                $output->writeln("ERROR   $component: failed to reinstall: " . $e->getMessage());
                $restorefailed[] = $component;
                $failed[] = $component;
            }
        }

        // Stop here, nothing is uninstalled or deleted yet: an incomplete
        // run would leave plugins registered in the database without their
        // files, which is the state this mode exists to get rid of.
        if ($restorefailed && !$keepgoing) {
            $output->writeln('Aborting, nothing was uninstalled or deleted. These plugins could not be reinstalled: ' . implode(', ', $restorefailed));
            return Command::FAILURE;
        }

        if (!empty($restored)) {
            // Phase 2: one purge for everything phase 1 wrote. The files
            // are all on disk and every version row matches them by now,
            // so the upgrade_noncore() inside has nothing to install.
            $this->resetPluginCaches();

            // Phase 3: uninstall from the database, files still in place -
            // Moodle needs them for db/uninstall.php and install.xml. No
            // resetPluginCaches() between the uninstalls or before the
            // files are gone: its upgrade_noncore() would find the
            // just-uninstalled plugin's files without a version row and
            // install the plugin again.
            $uninstalled = [];
            foreach ($restored as $component => $p) {
                try {
                    $this->uninstallRestoredPlugin($component, $output);
                    $uninstalled[$component] = $p;
                } catch (\Throwable $e) {
                    $output->writeln("ERROR   $component: " . $e->getMessage());
                    $failed[] = $component;
                }
            }

            // Phase 4: only the plugins that really are uninstalled now lose
            // their files - the others are still installed and their files
            // belong to them.
            foreach ($uninstalled as $component => $p) {
                try {
                    $this->removePluginFiles($component, $p['componentdir'], $p['componentpath'], $output);
                    $output->writeln("REMOVED $component: reuninstalled");
                } catch (\Throwable $e) {
                    $output->writeln("ERROR   $component: uninstalled, but its files could not be deleted: " . $e->getMessage());
                    $failed[] = $component;
                }
            }

            if (!empty($uninstalled)) {
                $this->resetPluginCaches();
            }
        }

        if ($failed) {
            $output->writeln('Failed component(s): ' . implode(', ', array_unique($failed)));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Which version to restore $component with, and where that came from.
     * Ported from install_plugins.php's get_uninstall_version(): the
     * database first (it knows what is really installed here, that beats
     * any record in the plugin list), then <componentdir>/version_uninstall,
     * then the newest positive value of the component's `version` file in
     * the plugin list's git history.
     *
     * Changes nothing - `writeversionfile` tells the caller to write
     * version_uninstall (write-once: the file is the version the
     * repository decided on, and every environment sharing the branch may
     * have a different one installed).
     *
     * @return array{version:int,source:string,writeversionfile:bool}
     * @throws \RuntimeException if nothing knows a version, or the database
     *   and version_uninstall disagree
     */
    private function resolveUninstallVersion(string $component, string $componentdir): array
    {
        $file = $componentdir . '/version_uninstall';
        $fileexists = is_file($file);
        $fileversion = $fileexists ? (int) trim((string) file_get_contents($file)) : 0;
        $dbversion = $this->getDbPluginVersion($component);

        if ($dbversion) {
            $version = $dbversion;
            $source = 'the database';
        } elseif ($fileversion) {
            $version = $fileversion;
            $source = "$component/version_uninstall";
        } else {
            $version = $this->getLastManagedVersion($component);
            $source = 'the git history';
        }

        if (!$version) {
            throw new \RuntimeException('no version found in the database, in version_uninstall or in the git history');
        }

        // Not decided here: the file may be the right one for other
        // environments sharing this branch, and silently uninstalling a
        // version nobody wrote down is worse than stopping.
        if ($fileexists && $dbversion && $dbversion !== $fileversion) {
            throw new \RuntimeException("installed with version $dbversion, but $component/version_uninstall says $fileversion");
        }

        return ['version' => $version, 'source' => $source, 'writeversionfile' => !$fileexists];
    }

    /**
     * The version row of $component in {config_plugins}, or null if there
     * is none. Read from the table, not through get_config(): that one is
     * served from Moodle's config cache, which doesn't notice a row that
     * was deleted by hand - and a deleted row is the very situation this
     * mode is for. Upgrade_plugins() reads it the same uncached way.
     */
    private function getDbPluginVersion(string $component): ?int
    {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', ['plugin' => $component, 'name' => 'version']);
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * The newest positive value the plugin list's git history holds for
     * <component>/version, or null. Ported from install_plugins.php's
     * get_last_managed_version(). Only works in a full checkout, a shallow
     * one as CI creates it has no history to search - and the plugin list
     * must be inside a git work tree at all.
     *
     * The path for `git show` is written ./<component>/version so it is
     * relative to the plugin list directory, which is not necessarily the
     * repository root (the original's script sits at the root).
     */
    private function getLastManagedVersion(string $component): ?int
    {
        $git = 'git -C ' . escapeshellarg($this->configPluginDirectory);

        $shas = [];
        exec($git . ' log --format=%H -- ' . escapeshellarg($component . '/version') . ' 2>/dev/null', $shas);

        foreach ($shas as $sha) {
            $lines = [];
            exec($git . ' show ' . escapeshellarg($sha . ':./' . $component . '/version') . ' 2>/dev/null', $lines);

            // A commit that deleted the file, or set it to 0/-1/a sentinel
            // word, reads as 0 here: keep going back in time.
            $version = (int) trim(implode('', $lines));
            if ($version > 0) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Phase 1 for one component: version_uninstall, the version row, the
     * files at $version, the malware scan.
     *
     * @throws \RuntimeException on any failure - the caller collects them
     */
    private function restoreForReuninstall(string $component, string $componentdir, string $componentpath, int $version, bool $writeversionfile, OutputInterface $output): void
    {
        if ($writeversionfile) {
            $file = $componentdir . '/version_uninstall';
            if (@file_put_contents($file, $version . "\n") !== false) {
                $output->writeln("WARN    Wrote version $version to $component/version_uninstall, please commit it");
            } else {
                $output->writeln("WARN    Could not write $file - the version to restore is $version, write it there by hand so it is not lost");
            }
        }

        // Before the files, not after (see the class docblock): the
        // upgrade_noncore() at the end of installRequestedVersion() must
        // find this plugin already installed at exactly this version.
        // set_config(), unlike a plain INSERT, also drops Moodle's cached
        // copy of the plugin's config.
        if ($this->getDbPluginVersion($component) === null) {
            $output->writeln("Registering $component with version $version");
            set_config('version', (string) $version, $component);
        }

        $installed = '-1';
        try {
            $installed = $this->getInstalledVersion($component, $componentdir, $componentpath);
        } catch (\Throwable $e) {
            // A version.php that can't be read is no reason to give up:
            // installing below replaces the directory.
        }

        if ($installed === (string) $version && $this->arePatchesApplied($component, $componentdir, $componentpath)) {
            $output->writeln("OK      $component: files already at $version");
            return;
        }

        $output->writeln("Restoring $component $version");
        $this->installRequestedVersion($component, $componentdir, (string) $version, $output, 0, true);

        $now = $this->getInstalledVersion($component, $componentdir, $componentpath);
        if ($now !== (string) $version) {
            throw new \RuntimeException("restoring version $version left $now in $componentpath, exiting");
        }

        // The same check applyComponent() makes after every install: this
        // code is about to run (db/uninstall.php), so it is scanned like
        // any other install.
        $scan = $this->runScanners($component, $componentpath, $output);
        if ($scan['exitCode'] !== ClamscanRunner::EXIT_CLEAN) {
            $relativeLog = self::SCANNER_REPORT_PATHS[$scan['failedScanner']] ?? '';
            $logPath = $relativeLog !== ''
                ? "{$this->configPluginDirectory}/{$relativeLog}"
                : $this->configPluginDirectory;
            throw new \RuntimeException(
                'malware scan ' . ($scan['exitCode'] === ClamscanRunner::EXIT_MALWARE_FOUND ? 'found malware' : 'failed')
                . " (exit {$scan['exitCode']}) after restoring - see $logPath",
            );
        }
    }

    /**
     * Phase 3 for one component: remove it from the database through
     * Moodle's plugin manager, files untouched. Ported from
     * install_plugins.php's plugin_uninstall_db() plus the check its
     * callers make afterwards.
     *
     * @throws \RuntimeException if the plugin is still installed afterwards
     */
    private function uninstallRestoredPlugin(string $component, OutputInterface $output): void
    {
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
            $output->writeln("WARN: Moodle refuses to uninstall $component through the plugin manager (unknown to it, or another plugin requires it)");
        }

        // The uninstall can fail without Moodle saying so, e.g. when
        // another plugin still requires this one. The files stay in that
        // case: they belong to a plugin that is still installed.
        if ($this->getDbPluginVersion($component) !== null) {
            throw new \RuntimeException("$component is still installed after the uninstall, see the output above - its files are kept");
        }
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
        // Read the requested version first (before resolving the install
        // path): a plugin type Moodle no longer knows about (e.g. atto_*
        // after the Atto editor was removed from core) can never be
        // resolved to a path, but if the declarative list says "uninstall"
        // for it, there's nothing to do - skip instead of erroring, since
        // there's no path to check and nothing installed to remove.
        $requested = $this->getRequestedVersion($component, $componentdir);
        if ($requested === null || $requested === '') {
            throw new \RuntimeException('could not determine requested version, exiting');
        }

        try {
            $componentpath = $this->getComponentPath($component, $componentdir);
        } catch (\RuntimeException $e) {
            if ($this->isUninstallSentinel($requested)) {
                $output->writeln(
                    "SKIP    $component: " . $e->getMessage() .
                    ' (requested is uninstall - nothing to do)',
                );
                return;
            }
            throw $e;
        }

        // §2: a git-managed directory (plain clone or submodule) is left
        // completely alone - checked here, before ever reading its
        // version.php, exactly where install_plugins.php's
        // plugin_install() checks it: version comparison is meaningless
        // for a path whose real version.php is git's/the submodule's
        // problem, not this command's. Getting this right matters
        // structurally, not just cosmetically: getInstalledVersion()
        // below can throw on a version.php this command doesn't
        // recognize, and even if it didn't, current would almost never
        // equal $requested for a git-managed component (nothing here
        // wrote it), which would otherwise fall through to
        // installRequestedVersion() and then fail the post-install
        // "could not be upgraded" check purely because git, correctly,
        // wasn't touched. Not applicable to package_* - those never
        // resolve to a git-managed path via this check; a package_*
        // submodule (if ever needed) is the responsibility of its own
        // bin/ scripts.
        if (!str_starts_with($component, 'package_') && $this->isGitManaged($componentpath)) {
            $output->writeln("plugin $component is managed by git - leaving as is");
            if (!$this->dryRun) {
                $this->touchDownloadedMarker($componentpath);
            }
            return;
        }

        $current = $this->getInstalledVersion($component, $componentdir, $componentpath);

        $output->writeln('-----');

        // Display human-readable version for output
        $displayRequested = $this->getDisplayVersion($requested);
        $displayCurrent = $this->getDisplayVersion($current);
        $output->writeln("$component requested: $displayRequested installed: $displayCurrent");

        $isPackage = str_starts_with($component, 'package_');
        $requestedIsSentinel = $this->isRemoveFilesSentinel($requested) || $this->isUninstallSentinel($requested);

        if ($current === $requested) {
            // Patches can change without the requested version changing -
            // checked here too, mirroring install_plugins.php's
            // plugin_install() and package_install() (which is why this
            // covers package_* components as well). Not relevant when the
            // requested state is remove-files/uninstall.
            $patchesOk = $requestedIsSentinel
                || $this->arePatchesApplied($component, $componentdir, $componentpath);

            if ($patchesOk) {
                $this->runAlwaysRunHookIfPresent($component, $componentdir, $output);
                // Backfill: marks a pre-existing/hand-installed component
                // as tracked on the very first run that sees it already
                // correct, instead of it looking orphaned - matching
                // plugin_install()'s "plugin is uptodate, ... mark as
                // downloaded" branch in install_plugins.php. No-op (and
                // in dry-run mode, no disk write at all) for a
                // git-managed directory.
                if (!$this->dryRun) {
                    $this->touchDownloadedMarker($componentpath);
                }
                $suffix = (!$requestedIsSentinel && $this->hasPatches($component, $componentdir))
                    ? ' (including local patches)' : '';
                $output->writeln("OK      $component: already at $displayRequested$suffix");
                return;
            }

            if ($this->dryRun) {
                $output->writeln(
                    "WOULD REAPPLY PATCHES $component: local patches changed, "
                    . ($isPackage
                        ? 'bin/install_requested_version.sh will run again and the patches be re-applied'
                        : 'files will be downloaded again and re-patched'),
                );
                return;
            }

            $output->writeln(
                "$component: local patches changed - "
                . ($isPackage
                    ? 'running bin/install_requested_version.sh again and applying current patches'
                    : 'downloading files again and applying current patches'),
            );
            // Falls through to the install/upgrade path below, which
            // reinstalls $requested (same value as $current) and
            // applies the current patches to the fresh code.
        }

        if ($this->isRemoveFilesSentinel($requested)) {
            if ($this->dryRun) {
                $output->writeln("WOULD REMOVE $component: files at $componentpath (database left untouched)");
                return;
            }
            $this->removePluginFiles($component, $componentdir, $componentpath, $output);
            $output->writeln("REMOVED $component: files removed (database left untouched)");
            return;
        }

        if ($this->isUninstallSentinel($requested)) {
            if ((int) $current <= -1) {
                if ($this->dryRun) {
                    $output->writeln("OK      $component: already uninstalled (would still best-effort clean up orphan records)");
                    return;
                }
                $this->uninstallForce($component, $componentdir, $output);
                $output->writeln("OK      $component: already uninstalled, database cleaned up (best effort)");
            } else {
                if ($this->dryRun) {
                    $output->writeln("WOULD UNINSTALL $component (currently $displayCurrent)");
                    return;
                }
                $this->uninstall($component, $componentdir, $componentpath, $output);
                $output->writeln("REMOVED $component: uninstalled");
            }
            return;
        }

        // requested > 1: install or upgrade.
        if ($this->dryRun) {
            $output->writeln("WOULD INSTALL $component: $displayCurrent -> $displayRequested (target: $componentpath)");
            return;
        }

        $this->installRequestedVersion($component, $componentdir, $requested, $output);

        $updated = $this->getInstalledVersion($component, $componentdir, $componentpath);
        if ($updated === null || $updated === '') {
            throw new \RuntimeException("requested: $displayRequested could not be installed, exiting");
        }
        if ($updated !== $requested) {
            throw new \RuntimeException("requested: $displayRequested could not be upgraded, $displayCurrent is still deployed, exiting");
        }

        $scan = $this->runScanners($component, $componentpath, $output);
        if ($scan['exitCode'] !== ClamscanRunner::EXIT_CLEAN) {
            // Point only at the log the failing scanner actually wrote,
            // not at every scanner's log regardless of which ran.
            $relativeLog = self::SCANNER_REPORT_PATHS[$scan['failedScanner']] ?? '';
            $logPath = $relativeLog !== ''
                ? "{$this->configPluginDirectory}/{$relativeLog}"
                : $this->configPluginDirectory;
            throw new \RuntimeException(
                'malware scan ' . ($scan['exitCode'] === ClamscanRunner::EXIT_MALWARE_FOUND ? 'found malware' : 'failed')
                . " (exit {$scan['exitCode']}) after installing - see $logPath",
            );
        }

        $this->addIgnorePathsToGitignore($component, $componentdir, $componentpath);

        $patchSuffix = $this->hasPatches($component, $componentdir) ? ' (including local patches)' : '';

        if (isset($this->archivedComponents[$component])) {
            $output->writeln(
                "ARCHIVED $component: installed from local archive ({$this->archivedComponents[$component]}) "
                . '- not available on moodle.org, see plugin:list-update --archive documentation' . $patchSuffix,
            );
        } else {
            $output->writeln("INSTALLED $component: $displayCurrent -> $displayRequested$patchSuffix");
        }
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
     * Normalize a requested version string to one of the sentinel constants
     * or the numeric version.
     *
     * Recognizes:
     *   - "-1" or "remove-files" (case-insensitive) -> SENTINEL_REMOVE_FILES
     *   - "0" or "uninstall" (case-insensitive) -> SENTINEL_UNINSTALL
     *   - Any other numeric string -> returned as-is
     *
     * @param string $requested The raw requested version from the version file
     * @return string Normalized version string (sentinel constant or numeric)
     * @throws \RuntimeException if the version is not a valid integer or recognized sentinel
     */
    private function normalizeRequestedVersion(string $requested): string
    {
        $trimmed = trim($requested);
        $lower = strtolower($trimmed);

        // Check for string sentinels first (case-insensitive)
        if ($lower === strtolower(self::SENTINEL_REMOVE_FILES_STR)) {
            return self::SENTINEL_REMOVE_FILES;
        }

        if ($lower === strtolower(self::SENTINEL_UNINSTALL_STR)) {
            return self::SENTINEL_UNINSTALL;
        }

        // Check for numeric sentinels
        if ($trimmed === self::SENTINEL_REMOVE_FILES || $trimmed === self::SENTINEL_UNINSTALL) {
            return $trimmed;
        }

        // Must be a valid integer
        if (!preg_match('/^-?[0-9]+$/', $trimmed)) {
            throw new \RuntimeException(
                "requested version is not a valid integer or recognized sentinel: '$requested' " .
                "(valid: -1, 0, 'remove-files', 'uninstall')"
            );
        }

        return $trimmed;
    }

    /**
     * Check if a version string represents the "remove files" sentinel.
     * Handles both "-1" and "remove-files" (case-insensitive).
     */
    private function isRemoveFilesSentinel(string $version): bool
    {
        $trimmed = trim($version);
        return $trimmed === self::SENTINEL_REMOVE_FILES ||
               strtolower($trimmed) === strtolower(self::SENTINEL_REMOVE_FILES_STR);
    }

    /**
     * Check if a version string represents the "uninstall" sentinel.
     * Handles both "0" and "uninstall" (case-insensitive).
     */
    private function isUninstallSentinel(string $version): bool
    {
        $trimmed = trim($version);
        return $trimmed === self::SENTINEL_UNINSTALL ||
               strtolower($trimmed) === strtolower(self::SENTINEL_UNINSTALL_STR);
    }

    /**
     * Get a human-readable display version string.
     * Converts sentinel constants to their readable equivalents.
     */
    private function getDisplayVersion(string $version): string
    {
        if ($this->isRemoveFilesSentinel($version)) {
            return self::SENTINEL_REMOVE_FILES_STR;
        }
        if ($this->isUninstallSentinel($version)) {
            return self::SENTINEL_UNINSTALL_STR;
        }
        return $version;
    }

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
            $requested = $this->lastScriptLine($lines);
        } else {
            $requested = $this->readVersionFile($componentdir . '/version');
            if ($requested === null) {
                $requested = self::SENTINEL_REMOVE_FILES;
            }
        }

        return $this->normalizeRequestedVersion($requested);
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
            return $this->lastScriptLine($lines);
        }

        $versionphp = $componentpath . '/version.php';
        if (!is_file($versionphp)) {
            return '-1';
        }

        $version = VersionPhpParser::parseFile($versionphp)['version'];
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
            $relative = $this->lastScriptLine($lines);
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

        if ($this->isGitManaged($componentpath)) {
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

        if ($this->isGitManaged($componentpath)) {
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
     * @param bool $skipDependencies put the files back without resolving the
     *   component's <componentdir>/requires file or its version.php
     *   $plugin->dependencies - for --reuninstall, which restores a plugin
     *   only to uninstall it again (see the class docblock). Those steps
     *   install other components and can even write new entries into the
     *   plugin list, none of which belongs in a recovery.
     * @throws \RuntimeException
     */
    private function installRequestedVersion(string $component, string $componentdir, string $requestedversion, OutputInterface $output, int $depth = 0, bool $skipDependencies = false): void
    {
        if ($depth > 5) {
            throw new \RuntimeException("dependency resolution recursion too deep for $component - possible circular 'requires'");
        }

        if (str_starts_with($component, 'package_')) {
            $hasPatches = $this->hasPatches($component, $componentdir);

            // Resolved before the install script runs: the patch
            // fingerprint lives in this directory and has to be dropped
            // first (see the class docblock, "Package patching").
            $componentpath = null;
            try {
                $componentpath = $this->getComponentPath($component, $componentdir);
            } catch (\Throwable $e) {
                // Only fatal if there is something to patch. Otherwise
                // this is best-effort, as it always was: a package_*
                // whose get_component_path.sh can't resolve a path is an
                // edge case orphan detection can live without.
                if ($hasPatches) {
                    throw new \RuntimeException("cannot patch $component: " . $e->getMessage(), 0, $e);
                }
            }
            if ($componentpath !== null) {
                @unlink($componentpath . '/.patches-applied');
            }

            [$lines, $exitcode] = $this->runScript($componentdir . '/bin/install_requested_version.sh', [$component, $requestedversion]);
            foreach ($lines as $line) {
                $output->writeln($line);
            }
            if ($exitcode !== 0) {
                throw new \RuntimeException("bin/install_requested_version.sh exited with status $exitcode");
            }

            if ($componentpath !== null) {
                // Also drops a stale fingerprint when the patches are gone.
                $this->applyPatches($component, $componentdir, $componentpath, $output);
                if ($hasPatches) {
                    // The install script usually ran Moodle's upgrade
                    // already - before the patches were there. Same reset
                    // a plain plugin gets at the end of its install, so
                    // Moodle sees what the patches changed.
                    $this->resetPluginCaches();
                }
                try {
                    $this->touchDownloadedMarker($componentpath);
                } catch (\Throwable $e) {
                    // Best-effort only, see above.
                }
            }
            return;
        }

        if (!$skipDependencies) {
            $this->installRequiresFileDependencies($component, $componentdir, $output, $depth);
        }

        $componentpath = $this->getComponentPath($component, $componentdir);

        // §2: defense-in-depth - the top-level call from applyComponent()
        // now short-circuits before ever reaching here for a git-managed
        // top-level component (see applyComponent()), so this mainly
        // protects the OTHER caller of this method: recursive
        // version.php-dependency resolution (resolveSingleDependency() ->
        // installRequestedVersion() for a dependency component), which
        // has no equivalent early check of its own. Never download over
        // a git-managed path, exactly as install_plugins.php's
        // plugin_install() does before touching anything.
        if ($this->isGitManaged($componentpath)) {
            $output->writeln("plugin $component is managed by git - leaving as is");
            if (!$this->dryRun) {
                $this->touchDownloadedMarker($componentpath);
            }
            return;
        }

        global $CFG;
        require_once $CFG->libdir . '/adminlib.php';
        require_once $CFG->libdir . '/upgradelib.php';
        require_once $CFG->libdir . '/filelib.php';

        // download+extract+upgrade_noncore() below loads the full moodle.org
        // plugin directory JSON (decoded into PHP objects, which costs far
        // more memory than the raw JSON) and then runs Moodle's own plugin
        // upgrade code - both can exceed the default CLI memory_limit on
        // large/many-plugin sites, same as context:rebuild.
        raise_memory_limit(MEMORY_EXTRA);

        $client = new PluginApiClient($this->proxy, $this->token);

        $tempDir = sys_get_temp_dir() . '/moosh_plugin_list_apply_' . uniqid();
        mkdir($tempDir, 0755, true);

        $usedArchive = false;
        $archivedVersion = null;

        try {
            $zipFile = $tempDir . '/' . $component . '.zip';

            try {
                $version = $client->findBestVersion($component, (string) moodle_major_version(), $requestedversion, true);
                $output->writeln("Downloading $component {$version->version}");
                $client->downloadFile($version->downloadurl, $zipFile);
            } catch (\RuntimeException $e) {
                // §3.2: only a "component missing entirely" / "version not
                // found" failure is eligible for the archive fallback - a
                // genuine network failure, bad token, or malformed
                // plugins.json should still fail loudly, not be silently
                // swallowed as if it were a legitimate withdrawn-version
                // scenario. Also never on by default - --archive-fallback
                // must be explicitly requested.
                if (!$this->archiveFallback || !self::isPluginNotFoundError($e)) {
                    throw $e;
                }
                $archivedVersion = $this->installFromArchive($component, $componentdir, $requestedversion, $zipFile, $output);
                if ($archivedVersion === null) {
                    // No archive found (or none usable) either - re-throw
                    // the original moodle.org failure, unchanged.
                    throw $e;
                }
                $usedArchive = true;
            }

            // Fail fast on anything that isn't actually a zip (an error
            // page, a truncated download, ...) before ever handing it to
            // ZipArchive - see PluginZipCache::assertZipMagicBytes().
            PluginZipCache::assertZipMagicBytes($zipFile);

            // Before doing anything else with it - extracting it,
            // resolving its version.php dependencies, moving it into
            // $CFG->dirroot, ... - confirm the zip's own version.php
            // actually declares the Frankenstyle component we asked for.
            // Catches a stale/incorrect downloadurl (or a Marketplace/API
            // mixup) silently installing the wrong plugin under this
            // component's name. Applies identically to an archive-sourced
            // zip - a drifted archive shouldn't install under the wrong
            // component's name either.
            PluginZipCache::assertZipComponent($zipFile, $component);

            // §3.4: verify (or warn about a missing) pinned checksum - for
            // a freshly-downloaded zip and an archive-sourced one alike.
            $this->verifyChecksum($component, $componentdir, $zipFile, $usedArchive, $output);

            if ($usedArchive) {
                $this->archivedComponents[$component] = $archivedVersion;
            }

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

            // Resolve any $plugin->dependencies declared in the plugin's
            // own version.php (e.g. a theme requiring a specific parent
            // theme version) - and get them installed - before this
            // component itself is moved into place, so nothing ends up
            // installed with an unmet dependency.
            if (!$skipDependencies) {
                $this->resolveVersionPhpDependencies($component, $componentdir, $extractedPluginDir, $output, $depth);
            }

            if (file_exists($componentpath)) {
                $output->writeln("Removing existing directory $componentpath");
                \fulldelete($componentpath);
            }

            $output->writeln("Installing to $componentpath");
            try {
                $this->moveDirectory($extractedPluginDir, $componentpath);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Failed to install $component to $componentpath: " . $e->getMessage());
            }
        } finally {
            $this->removeDirectory($tempDir);
        }

        $this->applyPatches($component, $componentdir, $componentpath, $output);

        $this->touchDownloadedMarker($componentpath);

        $this->resetPluginCaches();
    }

    /**
     * §3.2: does $e's message match one of PluginApiClient::findBestVersion()'s
     * two "this component/version genuinely isn't on moodle.org" failure
     * strings - as opposed to its third ("not supported for Moodle
     * $release") or any other \RuntimeException, which a local archive
     * can't fix and shouldn't be treated as if it could.
     */
    private static function isPluginNotFoundError(\RuntimeException $e): bool
    {
        $message = $e->getMessage();
        if (str_contains($message, 'not found in the moodle.org directory')) {
            return true;
        }
        return (bool) preg_match('/^Could not find .* version/', $message);
    }

    /**
     * §3.2: install-from-archive fallback. Looks for exactly one zip under
     * <componentdir>/archive/ (written by plugin:list-update --archive,
     * see issue #82 §3.1) and copies it to $targetZipPath so the caller
     * can fall straight through into the same
     * assertZipMagicBytes/assertZipComponent/extract/dependency-resolve/     * move pipeline used for a fresh moodle.org download - not a separate,
     * hand-rolled install path.
     *
     * @return string|null the archived version (parsed from the zip's
     *   filename), or null if no archive exists - the caller re-throws the
     *   original moodle.org failure in that case
     * @throws \RuntimeException if more than one zip is found under
     *   archive/ (ambiguous - the archive convention keeps exactly one at
     *   a time) or the copy itself fails
     */
    private function installFromArchive(string $component, string $componentdir, string $requestedversion, string $targetZipPath, OutputInterface $output): ?string
    {
        $archivedir = $componentdir . '/archive';
        if (!is_dir($archivedir)) {
            return null;
        }
        $zips = glob($archivedir . '/*.zip') ?: [];
        if (count($zips) === 0) {
            return null;
        }
        if (count($zips) > 1) {
            throw new \RuntimeException(
                "multiple archived zips found in $archivedir - expected exactly one ("
                . implode(', ', array_map('basename', $zips)) . ')',
            );
        }
        $archivedZip = $zips[0];

        $output->writeln(
            "$component: version $requestedversion not resolvable on moodle.org - "
            . 'falling back to archived zip ' . basename($archivedZip),
        );

        if (!copy($archivedZip, $targetZipPath)) {
            throw new \RuntimeException("could not copy archived zip $archivedZip to $targetZipPath");
        }

        $base = basename($archivedZip, '.zip');
        $prefix = $component . '-';
        return str_starts_with($base, $prefix) ? substr($base, strlen($prefix)) : $requestedversion;
    }

    /**
     * §3.4: verify a zip (freshly downloaded or archive-sourced, see
     * $fromArchive) against <componentdir>/checksum, the md5 pinned by
     * plugin:list-update (PluginListUpdate52Handler::reconcileChecksum()).
     *
     * A mismatch is a hard failure - don't install a zip that doesn't
     * match its pinned checksum, downloaded fresh or from the archive
     * alike. A missing checksum file only warns (doesn't block) - failing
     * hard here would break every existing declarative plugin list that
     * predates this checksum-pinning convention, and some plugins already
     * fell out of the moodle.org directory before it existed for them, so
     * plugin:list-update can no longer backfill one on its own.
     *
     * @throws \RuntimeException on a checksum mismatch
     */
    private function verifyChecksum(string $component, string $componentdir, string $zipFile, bool $fromArchive, OutputInterface $output): void
    {
        $checksumfile = $componentdir . '/checksum';

        if (!is_file($checksumfile)) {
            if ($this->suppressLifecycleWarnings) {
                return;
            }
            $howto = $fromArchive
                ? "md5sum $componentdir/archive/*.zip > $checksumfile"
                : "md5sum $componentdir/archive/*.zip > $checksumfile (once archived via plugin:list-update "
                    . "--archive), or md5sum <the zip you're sourcing this plugin from> > $checksumfile";
            $message = "$component: no checksum pinned at $checksumfile - the "
                . ($fromArchive ? 'archived' : 'downloaded') . ' zip could not be integrity-verified against a '                . "known-good value. Create it by hand once you have a trusted zip, e.g.: $howto";
            if ($this->isRunningInCi()) {
                $output->writeln("::warning title=Missing plugin checksum::$message");
            } else {
                $output->writeln("WARNING $message");
            }
            return;
        }

        $expected = trim((string) file_get_contents($checksumfile));
        $actual = hash_file('md5', $zipFile);
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                "checksum mismatch for $component: $checksumfile expects '$expected', "
                . ($fromArchive ? 'archived' : 'downloaded') . " zip is '$actual'",
            );
        }
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

            $displayRequested = $this->getDisplayVersion($requiredrequested);
            $displayCurrent = $this->getDisplayVersion($requiredcurrent);
            $output->writeln("Installing requirement $requiredcomponent for $component (requested: $displayRequested, installed: $displayCurrent)");

            if ($requiredcurrent !== $requiredrequested) {
                if ($this->isUninstallSentinel($requiredrequested)) {
                    throw new \RuntimeException("$component requires $requiredcomponent but $requiredcomponent is marked for uninstall");
                }
                $this->installRequestedVersion($requiredcomponent, $requiredcomponentdir, $requiredrequested, $output, $depth + 1);
            }
        }
    }

    // -------------------------------------------------------------------
    // version.php-declared dependencies ($plugin->dependencies)
    // -------------------------------------------------------------------

    /**
     * Unlike the <componentdir>/requires file above (a moosh2-specific,
     * hand-maintained list), $plugin->dependencies inside version.php is
     * how the plugin itself - as shipped by its author - declares what it
     * needs (e.g. theme_boost_union requiring a minimum theme_boost
     * version). Read from the just-downloaded/extracted copy via
     * VersionPhpParser (never executed - see that class), not the
     * eventually-installed one, since that's the authoritative copy for
     * the version about to be installed.
     *
     * @throws \RuntimeException if a dependency can't be resolved (see
     *   resolveSingleDependency())
     */
    private function resolveVersionPhpDependencies(string $component, string $componentdir, string $extractedPluginDir, OutputInterface $output, int $depth): void
    {
        $versionphp = $extractedPluginDir . '/version.php';
        if (!is_file($versionphp)) {
            return;
        }

        $dependencies = VersionPhpParser::parseFile($versionphp)['dependencies'];
        foreach ($dependencies as $depcomponent => $requiredversion) {
            $this->resolveSingleDependency($component, $componentdir, $depcomponent, $requiredversion, $output, $depth);
        }
    }

    /**
     * Resolve one version.php-declared dependency of $component, in order:
     *   1. Already installed (on disk) at a sufficient version? Nothing to do -
     *      this is what makes a dependency on e.g. theme_boost (shipped
     *      with Moodle core) a no-op on a normal install.
     *   2. Already declared in the same declarative plugin list (a sibling
     *      directory next to $componentdir)? Install/upgrade it from there,
     *      recursing through installRequestedVersion() like a <requires>
     *      file entry would.
     *   3. Otherwise, look it up in plugins.json (moodle.org's plugin
     *      directory - the same data plugin:list-update reads) as a
     *      third-party plugin: if found, write it into the declarative
     *      plugin list on disk (so plugin:list-update/list-apply see it
     *      from now on too) and install it immediately so $component's
     *      own install can proceed.
     *   4. Can't be resolved any of those ways -> throw. A dependency that
     *      silently doesn't get installed is worse than a loud failure
     *      here.
     *
     * @param int|string $requiredversion an int version, or the string
     *   'any' for ANY_VERSION
     * @throws \RuntimeException if depth exceeds the recursion guard, or
     *   the dependency can't be resolved
     */
    private function resolveSingleDependency(string $component, string $componentdir, string $depcomponent, int|string $requiredversion, OutputInterface $output, int $depth): void
    {
        if ($depth > 5) {
            throw new \RuntimeException(
                "dependency resolution recursion too deep resolving $depcomponent for $component - possible circular dependency",
            );
        }
        if (str_starts_with($depcomponent, 'package_')) {
            throw new \RuntimeException(
                "$component declares a version.php dependency on $depcomponent, but package_* pseudo-components "
                . "can't be resolved as a version.php dependency - add it to the plugin list manually instead.",
            );
        }

        $requiredlabel = $requiredversion === 'any' ? 'any version' : (string) $requiredversion;
        $depcomponentdir = dirname($componentdir) . '/' . $depcomponent;

        // 1) Already installed/present on disk at a sufficient version?
        //    Covers plugins shipped with Moodle core itself (e.g.
        //    theme_boost) exactly the same way as a separately-installed
        //    third-party one - both just mean "already on disk".
        $deppath = $this->getComponentPath($depcomponent, $depcomponentdir);
        $installed = $this->getInstalledVersionAt($deppath);
        if ($installed !== null && $this->dependencySatisfiedBy($installed, $requiredversion)) {
            $output->writeln("  OK      $depcomponent: dependency of $component already installed ($installed, needs >= $requiredlabel)");
            return;
        }

        // 2) Already declared in this declarative plugin list?
        if (is_dir($depcomponentdir)) {
            $requested = $this->getRequestedVersion($depcomponent, $depcomponentdir);
            if (
                $requested !== self::SENTINEL_UNINSTALL
                && $requested !== self::SENTINEL_REMOVE_FILES
                && $this->dependencySatisfiedBy($requested, $requiredversion)
            ) {
                $output->writeln("  Installing dependency $depcomponent (needs >= $requiredlabel) for $component - already in the plugin list, requested $requested");
                $this->installRequestedVersion($depcomponent, $depcomponentdir, $requested, $output, $depth + 1);
                return;
            }
            $requestedlabel = match ($requested) {
                self::SENTINEL_UNINSTALL => 'uninstall (0)',
                self::SENTINEL_REMOVE_FILES => 'no version / removed (-1)',
                default => $requested,
            };
            throw new \RuntimeException(
                "$component depends on $depcomponent >= $requiredlabel, but the plugin list at $depcomponentdir "
                . "requests $requestedlabel - update its version file to satisfy the dependency.",
            );
        }

        // 3) Not installed, not declared - is it a third-party plugin
        //    listed in plugins.json (moodle.org's directory)?
        $client = new PluginApiClient($this->proxy, $this->token);
        if ($client->findPlugin($depcomponent) === null) {
            throw new \RuntimeException(
                "$component depends on $depcomponent (>= $requiredlabel), but it's not installed, not declared in "
                . "the plugin list, and not listed in the moodle.org plugin directory - cannot resolve automatically.",
            );
        }

        $resolved = $client->findBestVersion($depcomponent, (string) moodle_major_version(), null, true);
        if (!$this->dependencySatisfiedBy((string) $resolved->version, $requiredversion)) {
            throw new \RuntimeException(
                "$component depends on $depcomponent >= $requiredlabel, but the latest version available for "
                . "this Moodle release ({$resolved->version}) doesn't satisfy that.",
            );
        }

        $output->writeln(
            "  ADD     $depcomponent: third-party dependency of $component (needs >= $requiredlabel), not found locally - "
            . "adding {$resolved->version} to the plugin list at $depcomponentdir and installing it now",
        );
        if (!mkdir($depcomponentdir, 0755, true) && !is_dir($depcomponentdir)) {
            throw new \RuntimeException("Failed to create plugin list directory $depcomponentdir for dependency $depcomponent.");
        }
        file_put_contents($depcomponentdir . '/version', $resolved->version . "\n");

        $this->installRequestedVersion($depcomponent, $depcomponentdir, (string) $resolved->version, $output, $depth + 1);
    }

    /**
     * @return string|null the installed version at an arbitrary component
     *   path (which may belong to a dependency this run never otherwise
     *   touches), or null if nothing valid is installed there
     */
    private function getInstalledVersionAt(string $componentpath): ?string
    {
        $versionphp = $componentpath . '/version.php';
        if (!is_file($versionphp)) {
            return null;
        }
        $version = VersionPhpParser::parseFile($versionphp)['version'];
        return $version !== null ? (string) $version : null;
    }

    /** @param int|string $requiredversion an int version, or 'any' */
    private function dependencySatisfiedBy(?string $actual, int|string $requiredversion): bool
    {
        if ($actual === null) {
            return false;
        }
        if ($requiredversion === 'any') {
            return (int) $actual > 0;
        }
        return (int) $actual >= (int) $requiredversion;
    }

    // -------------------------------------------------------------------
    // malware scanning
    // -------------------------------------------------------------------

    /**
     * Parse the --scanner option value into a list of scanner names.
     *
     * Accepts, case-insensitively: 'none'; 'all' (both scanners); a single
     * scanner ('clamav' or 'phpmussel'); or a comma-separated combination
     * of scanners (e.g. 'clamav,phpmussel'). 'none' and 'all' cannot be
     * combined with anything else via a comma.
     *
     * @return string[] zero or more of 'clamscan', 'phpmussel' (internal
     *   scanner keys - 'clamav' is only the public-facing --scanner name)
     * @throws \RuntimeException on an unrecognised or malformed value
     */
    private function parseScannerOption(string $value): array
    {
        $tokens = array_filter(
            array_map('trim', explode(',', strtolower($value))),
            static fn(string $t): bool => $t !== '',
        );

        if ($tokens === []) {
            throw new \RuntimeException($this->scannerOptionError($value));
        }

        if (in_array('none', $tokens, true)) {
            if (count($tokens) > 1) {
                throw new \RuntimeException($this->scannerOptionError($value));
            }
            return [];
        }

        if (in_array('all', $tokens, true)) {
            if (count($tokens) > 1) {
                throw new \RuntimeException($this->scannerOptionError($value));
            }
            return array_values(self::SCANNER_ALIASES);
        }

        $scanners = [];
        foreach ($tokens as $token) {
            if (!isset(self::SCANNER_ALIASES[$token])) {
                throw new \RuntimeException($this->scannerOptionError($value));
            }
            $scanners[self::SCANNER_ALIASES[$token]] = true;
        }

        return array_keys($scanners);
    }

    private function scannerOptionError(string $value): string
    {
        return "Unknown --scanner value '$value' (valid: clamav, phpmussel, a comma-separated "
            . "combination such as clamav,phpmussel, all, or none)";
    }

    /**
     * Run every scanner selected via --scanner, and return the worst exit
     * code observed (CLEAN < MALWARE_FOUND < ERROR) together with the name
     * of the scanner that produced it (or null when everything was clean).
     * The caller uses failedScanner to point at the log the failing
     * scanner actually wrote.
     *
     * Throws only for configuration errors; scanner unavailability is a
     * WARN + CLEAN skip, matching the pre-existing ClamAV-only behaviour.
     *
     * A MALWARE_FOUND result short-circuits the remaining scanners: once
     * the install has already failed, there's no value in running the
     * second scanner on top.
     *
     * @return array{exitCode:int, failedScanner:?string}
     */
    private function runScanners(string $component, string $componentpath, OutputInterface $output): array
    {
        if ($this->scanners === []) {
            return ['exitCode' => ClamscanRunner::EXIT_CLEAN, 'failedScanner' => null];
        }

        $worst = ClamscanRunner::EXIT_CLEAN;
        $failedScanner = null;
        foreach ($this->scanners as $scanner) {
            $result = match ($scanner) {
                'clamscan'  => $this->scanWithClamav($component, $componentpath, $output),
                'phpmussel' => $this->scanWithPhpMussel($component, $componentpath, $output),
                default     => throw new \RuntimeException("Unknown scanner '$scanner'"),
            };
            if ($result > $worst) {
                $worst = $result;
                $failedScanner = $scanner;
            }
            if ($result === ClamscanRunner::EXIT_MALWARE_FOUND) {
                break;
            }
        }
        return ['exitCode' => $worst, 'failedScanner' => $failedScanner];
    }

    /**
     * @return int one of ClamscanRunner::EXIT_CLEAN / EXIT_MALWARE_FOUND / EXIT_ERROR
     */
    private function scanWithClamav(string $component, string $componentpath, OutputInterface $output): int
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

        if ($databases === []) {
            $output->writeln("WARN: no clamscan database available (checked /var/lib/clamav, $rulesdir, $exceptionsdir), skipping malware scan for $component");
            return ClamscanRunner::EXIT_CLEAN;
        }

        $output->writeln("Starting malware scan for $component at $componentpath");
        $options = ['database' => $databases, 'infected' => true, 'log' => $reportdir . '/clamav.log'];
        [$exitcode, $lines] = ClamscanRunner::scan($binary, $componentpath, $options);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
        return $exitcode;
    }

    /**
     * @return int one of ClamscanRunner::EXIT_CLEAN / EXIT_MALWARE_FOUND / EXIT_ERROR
     */
    private function scanWithPhpMussel(string $component, string $componentpath, OutputInterface $output): int
    {
        $signatureManager = new PhpMusselSignatureManager();
        $signatureDir = $signatureManager->getSignatureDir();
        $configPath = $signatureManager->getConfigPath();

        // Require BOTH signature files AND the phpmussel.ini that activates
        // them. A directory with .hdb/.ndb/.db/.fdb files but no config is
        // an interrupted update-signatures run: phpMussel's Loader would
        // fall back to its ~40-file default active list, find none of them,
        // and every scan would return EXIT_ERROR. Treating that as a hard
        // install failure would be inconsistent with the "no signatures at
        // all" case, which is already a warn-and-skip. The two conditions
        // are the same failure from the operator's point of view: the
        // scanner isn't usable, so skip it and let the install proceed.
        if (!$this->dirHasFilesMatching($signatureDir, ['hdb', 'ndb', 'db', 'fdb'])
            || !is_file($configPath)) {
            $output->writeln(
                "WARN: no phpMussel signatures available at $signatureDir (or config missing at $configPath), "
                . "skipping phpMussel scan for $component (run: moosh plugin:phpmuslescan:update-signatures)",
            );
            return ClamscanRunner::EXIT_CLEAN;
        }

        if (!file_exists($componentpath)) {
            throw new \RuntimeException("cannot scan $component: target path does not exist: $componentpath");
        }

        $reportdir = $this->configPluginDirectory . '/.phpmussel/report';
        @mkdir($reportdir, 0755, true);

        $output->writeln("Starting phpMussel scan for $component at $componentpath");
        $runner = new PhpMusselRunner($signatureManager);
        $result = $runner->scan($componentpath);

        foreach (explode("\n", $result['output']) as $line) {
            $output->writeln($line);
        }
        file_put_contents($reportdir . '/phpmussel.log', $result['output']);

        return $result['exitCode'];
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

            // Check if we need to add the ignore rule
            if ($this->shouldAddGitignoreRule($gitignore)) {
                file_put_contents($gitignore, "\n*", FILE_APPEND);
                @chmod($gitignore, 0644 | (fileperms($gitignore) & 0777) | 0044);
            }
        }
    }

    /**
     * Determine if a .gitignore file needs the "*" rule added.
     * Returns true if:
     * - The .gitignore file doesn't exist
     * - The .gitignore exists but doesn't already have a rule that ignores everything
     *
     * @param string $gitignore Absolute path to the .gitignore file
     * @return bool True if the "*" rule should be added
     */
    private function shouldAddGitignoreRule(string $gitignore): bool
    {
        if (!is_file($gitignore)) {
            return true;
        }

        $content = file_get_contents($gitignore);
        if ($content === false) {
            return true; // Can't read it, try to append
        }

        // Check if there's already a rule that ignores everything in the current directory.
        // This matches: "*" (with optional whitespace), "/*", or just "*" with whitespace.
        // Also handles cases where "*" is on its own line or with comments.
        $patterns = [
            '/^[*][\s]*$/m',                    // Exactly "*" on a line
            '/^[\s]*[*][\s]*$/m',               // "*" with whitespace
            '/^[*][\s]*#/m',                    // "*" followed by a comment (e.g., "* # ignore everything")
            '/^[*][\s]*[^\/]/m',                // "*" with something after it (like "*." or "*~")
            '/^\/[*][\s]*$/m',                  // "/*"
            '/^[\s]*\/[*][\s]*$/m',             // "/*" with whitespace
            '/^[*][\/]?[\s]*$/m'                // "*" or "*/"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false; // Already has a rule that ignores everything
            }
        }

        return true; // No catch-all rule found, we should add it
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

        // Export MOODLEROOT environment variable for the external script.
        putenv('MOODLEROOT=' . $this->moodleroot);

        exec($cmd . ' 2>&1', $output, $exitcode);
        chdir($cwd);

        return [$output, $exitcode];
    }

    /**
     * Extract the actual return value from a runScript() capture.
     *
     * runScript() merges stderr into stdout (`2>&1`), and for package_*
     * scripts that source moodle_plugins_lib.rc - currently only
     * bin/get_installed_version.sh, bin/get_requested_version.sh and
     * bin/get_component_path.sh - that library enables `set -x` whenever
     * GitHub Actions debug logging is on (RUNNER_DEBUG=1), which writes a
     * full shell trace to stderr. Without this, $lines would be a huge
     * trace dump with the real value only as its last line, so any
     * comparison against it (e.g. installed-version-equals-requested)
     * would always fail - not because the check is wrong, but purely
     * because debug logging happened to be enabled. These scripts only
     * ever emit their actual result as the final line of output, so take
     * that line specifically instead of the whole capture.
     *
     * Do NOT use this for bin/get_component_ignore_path.sh - that script
     * legitimately returns multiple lines (one path per line), all of
     * which are needed; see addIgnorePathsToGitignore().
     *
     * @param string[] $lines full captured stdout+stderr from runScript()
     */
    private function lastScriptLine(array $lines): string
    {
        if ($lines === []) {
            return '';
        }
        return trim((string) $lines[array_key_last($lines)]);
    }

    // -------------------------------------------------------------------
    // patch support (ported from install_plugins.php)
    // -------------------------------------------------------------------

    /**
     * Returns the contents of a component's patch files, filename => content,
     * in apply order.
     *
     * The patches live next to the `version` file (i.e. directly in
     * $componentdir) and are applied sorted by filename, so a numeric
     * prefix (01-..., 02-...) controls the order.
     */
    private function getPatches(string $componentdir): array
    {
        $files = glob($componentdir . '/*.patch') ?: [];
        sort($files);

        $patches = [];
        foreach ($files as $file) {
            $patches[basename($file)] = file_get_contents($file);
        }

        return $patches;
    }

    /** Whether $component (plain or package_*) has any patch files configured. */
    private function hasPatches(string $component, string $componentdir): bool
    {
        return $this->getPatches($componentdir) !== [];
    }

    /**
     * One fingerprint over all patches. The filenames are part of it -
     * renaming a patch changes the apply order and so counts as a change.
     */
    private function getPatchesFingerprint(array $patches): string
    {
        return md5(serialize($patches));
    }

    /**
     * Reports whether $componentpath's code already matches the
     * component's current patches.
     *
     * What was applied is remembered as a fingerprint in `.patches-applied`
     * inside the installed component directory. False means the patches
     * changed (or disappeared) since then: applyComponent() redownloads
     * the component and applyPatches() patches the fresh code - simpler
     * and more reliable than reverting the old patches in place.
     */
    private function arePatchesApplied(string $component, string $componentdir, string $componentpath): bool
    {
        $patches = $this->getPatches($componentdir);
        $markerFile = $componentpath . '/.patches-applied';

        if ($patches === []) {
            // A leftover marker means the code is still patched from an earlier run.
            return !is_file($markerFile);
        }

        if (!is_dir($componentpath)) {
            return false;
        }

        return is_file($markerFile)
            && file_get_contents($markerFile) === $this->getPatchesFingerprint($patches);
    }

    /**
     * Applies $component's patches (if any) to the freshly-installed
     * $componentpath, or throws if one doesn't apply cleanly. Patches must
     * be in -p1 format, as `git diff` produces them: modifying, adding,
     * deleting and renaming files all work.
     *
     * A package_* component's patches are relative to the Moodle
     * repository root instead (see the class docblock, "Package
     * patching"); $componentpath is then its anchor directory, which holds
     * the fingerprint.
     *
     * @throws \RuntimeException if a patch fails to apply
     */
    private function applyPatches(string $component, string $componentdir, string $componentpath, OutputInterface $output): void
    {
        $markerFile = $componentpath . '/.patches-applied';
        $patches = $this->getPatches($componentdir);

        if ($patches === []) {
            // A stale marker from an earlier patched install would otherwise
            // make arePatchesApplied() report "changed" forever.
            if (is_file($markerFile)) {
                @unlink($markerFile);
            }
            return;
        }

        // The fingerprint needs a directory to live in.
        if (!is_dir($componentpath)) {
            throw new \RuntimeException(
                "cannot apply the patches of $component: $componentpath does not exist, so there is nowhere to keep "
                . 'the patch fingerprint' . (str_starts_with($component, 'package_')
                    ? ' (bin/get_component_path.sh must report a directory the install creates)' : ''),
            );
        }

        // Inside a git checkout, `git apply` resolves the patched paths
        // against the repository root and silently skips ("Skipped
        // patch") everything outside the current directory. So it always
        // runs from the repository root: a plain component's install path
        // is prepended via --directory (with the split public/ layout
        // that includes the public/ prefix - relative to $CFG->dirroot
        // the patch was skipped in a git checkout, yet reported as
        // applied), a package's patches carry the full path themselves.
        $root = $this->getMoodleRepoRoot();
        if (str_starts_with($component, 'package_')) {
            $base = $root;
            $subdir = '';
        } else {
            $base = str_starts_with($componentpath, $root . '/') ? $root : $this->moodleroot;
            $subdir = trim(substr($componentpath, strlen($base)), '/');
        }

        // Marked as incomplete first: if a patch fails half way (or the
        // process dies), the code must not look unpatched or fully
        // patched - the next run has to download the files again.
        file_put_contents($markerFile, "incomplete\n");

        foreach (array_keys($patches) as $name) {
            $output->writeln("Applying patch $name to $component");

            $patchFile = $componentdir . '/' . $name;

            // git apply instead of `patch`: it applies a patch completely
            // or not at all, so a failure cannot leave the code half
            // patched, and it understands everything `git diff` produces.
            $cmd = 'git -C ' . escapeshellarg($base) . ' apply -v'
                . ($subdir !== '' ? ' --directory=' . escapeshellarg($subdir) : '')
                . ' ' . escapeshellarg($patchFile) . ' 2>&1';
            // exec() appends to $lines rather than resetting it, so it
            // must be cleared before every call or later patches' output
            // would repeat everything already printed.
            $lines = [];
            exec($cmd, $lines, $exitcode);
            foreach ($lines as $line) {
                $output->writeln('  ' . $line);
            }

            if ($exitcode !== 0) {
                throw new \RuntimeException("applying patch $name to $component failed: $patchFile");
            }

            // git apply exits 0 for a patch (or part of it) that it
            // skipped because the paths are outside its working
            // directory. Never let that pass as applied.
            foreach ($lines as $line) {
                if (str_starts_with($line, 'Skipped patch ')) {
                    throw new \RuntimeException(
                        "patch $name for $component was skipped by git apply (its paths are not under $base): $patchFile",
                    );
                }
            }
        }

        // The fingerprint of what was applied, to detect changed patches on the next run.
        file_put_contents($markerFile, $this->getPatchesFingerprint($patches));
    }

    /**
     * The root of the Moodle code base: $CFG->dirroot, or with the split
     * layout (dirroot is public/) its parent - the directory `git diff`
     * paths are relative to. Same test as ArchivePathsTrait uses.
     */
    private function getMoodleRepoRoot(): string
    {
        $parent = dirname($this->moodleroot);
        if (basename($this->moodleroot) === 'public' && file_exists($parent . '/composer.json')) {
            return $parent;
        }
        return $this->moodleroot;
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

        // upgrade_noncore() below needs this. uninstallForce() (the
        // already-uninstalled / best-effort DB cleanup path) never loads
        // upgradelib.php itself, unlike install() and uninstall() - require
        // it here so resetPluginCaches() works no matter which caller
        // reaches it.
        require_once $CFG->libdir . '/upgradelib.php';
        raise_memory_limit(MEMORY_EXTRA);

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
        $this->removeDirectory($src);
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