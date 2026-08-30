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

class PluginReleaseNotesCommand extends BaseCommand
{
    // Purely a network lookup against Moodle Marketplace - no Moodle
    // installation is needed, same as plugin:download.
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::None;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:releasenotes')
            ->setDescription("Show a plugin version's release notes from Moodle Marketplace")
            ->setHelp(
                "Fetches the release notes for one specific version of a plugin from its " .
                "Moodle Marketplace page (marketplace.moodle.com). There is no documented, " .
                "public read API for this, so the notes are scraped from the plugin's " .
                "\"Versions\" page - this can break if Moodle Marketplace changes its layout.\n\n" .
                "The version must be the exact build number (e.g. 2025041400), same as the " .
                "'version' shown by plugin:list-update or found in a plugin's version.php."
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
        return new PluginReleaseNotes52Handler();
    }
}
