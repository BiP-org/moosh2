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
