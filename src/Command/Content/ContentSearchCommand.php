<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Content;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Search for text in user-visible content columns across the database.
 *
 * Canonical name: content:search
 */
class ContentSearchCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::FullNoAdminCheck;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('content:search')
            ->setDescription('Search text in user-visible content columns across the database')
            ->setHelp(
                "Scans CHAR and TEXT columns across all Moodle tables for a pattern.\n" .
                "Targets columns that Moodle filters may be applied to: a static list of\n" .
                "well-known names (name, fullname, shortname, title, subject, concept, ...)\n" .
                "plus any column that has a sibling *format companion (summary/summaryformat,\n" .
                "intro/introformat, content/contentformat, etc.)."
            );

        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler
    {
        return $this->handler;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);
        $verbose->step('Delegating to handler: ' . get_class($this->handler));
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $moodleVersion): BaseHandler
    {
        return new ContentSearch52Handler();
    }
}
