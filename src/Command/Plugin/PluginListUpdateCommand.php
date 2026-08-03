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

class PluginListUpdateCommand extends BaseCommand
{
    // Overridden per-request by the handler's getBootstrapLevel() — Full
    // is only actually needed when -v/--moodle-version wasn't given (to
    // auto-detect the current release), see PluginListUpdate52Handler.
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
            ->setName('plugin:list-update')
            ->setDescription("Sync a declarative plugin list's version files against moodle.org")
            ->setHelp(
                "Keeps the `version` file of one or more locally-tracked Frankenstyle plugin " .
                "directories in sync with the latest version available from moodle.org that is " .
                "compatible with a given Moodle release. Targets the \"declarative plugin list\" " .
                "layout: one subdirectory per plugin, named after its Frankenstyle component " .
                "(e.g. block_fastnav/), holding a `version` file that pins the version to install " .
                "— see plugin:list-apply, which applies that list to an actual Moodle installation.",
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
        return new PluginListUpdate52Handler();
    }
}
