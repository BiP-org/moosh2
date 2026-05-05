<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Archive;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bundle the Moodle codebase, dataroot and database into a single tar.gz.
 *
 * Equivalent of drush archive:dump. Unlike drush, the destination is
 * a mandatory positional argument, not a --destination option.
 */
class ArchiveDumpCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::Config;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('archive:dump')
            ->setDescription('Bundle the Moodle codebase, dataroot and database into a single tar.gz')
            ->setHelp(<<<'HELP'
                Creates a single .tar.gz archive containing:

                  MANIFEST.yml   metadata about the dump
                  code/          the Moodle codebase
                  files/         the moodledata directory (transient
                                 caches and sessions excluded)
                  database.sql   database dump produced by the native
                                 client (mysqldump or pg_dump)

                With no --code/--files/--db flag, all three are included.
                Pass any combination of those flags to archive a subset.
                HELP);

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
        return new ArchiveDump52Handler();
    }
}
