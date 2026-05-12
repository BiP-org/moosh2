<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Sql;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drop every table in the Moodle database one by one.
 *
 * Canonical name: sql:drop  |  Alias: sql-drop
 */
class SqlDropCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::DbOnly;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('sql:drop')
            ->setDescription('Drop every table in the Moodle database one by one')
            ->setHelp(<<<'HELP'
                Drops all tables from the database the Moodle config points at.
                The operation leaves the database itself in place — only its
                tables (and the data they hold) are removed. This is intended
                as a stand-in for DROP DATABASE when that command is not
                available, for example when the database account lacks the
                privilege to drop and recreate databases.

                Supports both MySQL/MariaDB and PostgreSQL.

                Without --run, the command lists the tables it would drop and
                exits without touching the database (dry-run).

                Use --exclude=t1,t2 to keep one or more tables. Table names
                are matched against the full, prefixed name as it appears in
                the database (e.g. mdl_user, not user).

                Examples:
                  sql:drop
                  sql:drop --run
                  sql:drop --exclude=mdl_config --run
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
        return new SqlDrop52Handler();
    }
}
