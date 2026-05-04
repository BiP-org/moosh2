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
 * Restore a Moodle install (codebase, dataroot, database) from a tar.gz
 * produced by archive:dump.
 *
 * Equivalent of drush archive:restore.
 */
class ArchiveRestoreCommand extends BaseCommand
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
            ->setName('archive:restore')
            ->setDescription('Restore Moodle codebase, dataroot and database from an archive:dump tarball')
            ->setHelp(<<<'HELP'
                Restores Moodle from a .tar.gz produced by archive:dump.

                With no --code/--files/--db flag, every component present
                in the archive is restored. Pass any combination of those
                flags to restore a subset.

                Restore is destructive — without --run the command shows a
                dry-run plan only. Pass --run to actually overwrite the
                target codebase, dataroot and database.

                Examples:
                  archive:restore backup.tar.gz                   # dry-run
                  archive:restore --db --run backup.tar.gz        # only DB
                  archive:restore --code-destination=/tmp/restored --code --run backup.tar.gz
                HELP);

        $this->addExampleUsage('Dry-run a full restore', 'backup.tar.gz');
        $this->addExampleUsage('Restore only the database', '--db --run backup.tar.gz');
        $this->addExampleUsage('Restore code into a different path', '--code --code-destination=/tmp/m --run backup.tar.gz');

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
        return new ArchiveRestore52Handler();
    }
}
