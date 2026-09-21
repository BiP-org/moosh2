<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginListApplyCommand extends BaseCommand
{
    // Applying a declarative plugin list always needs a working Moodle
    // site to install/uninstall into - unlike plugin:list-update, there's
    // no mode that could skip this.
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::Full;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:list-apply')
            ->setDescription("Apply a declarative plugin list's version files to this Moodle site")
            ->setHelp(
                "Applies a \"declarative plugin list\" (one subdirectory per Frankenstyle " .
                "component, each holding a `version` file - see plugin:list-update, which keeps " .
                "those version files current) to this Moodle installation: install, upgrade, " .
                "uninstall, or remove-files-only, plus a ClamAV scan of anything newly installed.\n\n" .
                "`version` file sentinel values (must already be reconciled by plugin:list-update " .
                "or set by hand - this command only reads them):\n" .
                "  > 1  install/upgrade to this exact version\n" .
                "  0    uninstall completely, including its database tables\n" .
                "  -1   remove the plugin's files only, leave the database untouched\n" .
                " (missing version file is an error - this command does not guess)\n\n" .
                "Patch files next to a component's version file (*.patch, -p1 format as `git diff` " .
                "produces it) are applied to its code after every (re)install, sorted by filename. " .
                "When a patch changes or disappears, the component is downloaded again and the " .
                "current patches applied to the fresh code. package_* components are never patched " .
                "here - they install via their own bin/install_requested_version.sh.\n\n" .
                "Every component confirmed at its requested version (freshly installed or already " .
                "correct) is tracked with a .downloaded-non-core-plugin marker file in its install " .
                "directory. When the full declarative list is scanned (no component names given on " .
                "the command line), any marked directory that is no longer in the list is an orphan: " .
                "--warn-orphans (the default) only reports these, --prune-orphans deletes them " .
                "(still behind --run). A git-managed directory (plain clone or submodule) is never " .
                "marked, overwritten, or deleted.\n\n" .
                "--reuninstall is a manual recovery mode for components requested as uninstall (0) whose plugin files or database version row are already gone. Without its files Moodle can neither run db/uninstall.php nor drop the tables of db/install.xml, and a plugin without a version row is \"Unknown plugin\" to it, so an earlier uninstall leaves data behind that no normal run reaches again. This mode puts each such component back - its files at the version the database, then <component>/version_uninstall (written once from whatever else knew it - commit it), then the git history of the plugin list knows, plus its version row, malware-scanned like any install - then uninstalls it completely and deletes its files. Only components requested as uninstall are touched (nothing else is installed, upgraded or pruned), package_* and git-managed components are skipped, and it cannot be combined with --prune-orphans/--warn-orphans. It does this on every run whether or not anything was left behind, so keep it out of a deploy pipeline. Nothing is uninstalled or deleted if a restore failed (unless --keep-going), and files are only deleted for a component whose uninstall actually worked. Still gated behind --run.\n\n" .
                'Without --run this only previews what would happen, same as plugin:install/plugin:uninstall.',
            );
        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler { return $this->handler; }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $v): BaseHandler
    {
        return new PluginListApply52Handler();
    }
}
