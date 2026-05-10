<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Course;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CourseRestoreCommand extends BaseCommand
{
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
            ->setName('course:restore')
            ->setDescription('Restore a course from a backup file')
            ->setHelp(<<<'HELP'
                Restores a course from a .mbz backup file into a category (creating a new
                course) or into an existing course (adding to it, or overwriting it with
                --overwrite).

                Use --course-startdate to set a new start date when restoring. Provide an
                ISO-8601 date or date-time (e.g. 2026-09-01 or 2026-09-01T00:00:00Z).
                Moodle shifts every date in the course (assignments, due dates, calendar
                events, quiz availabilities, etc.) by the difference between the original
                start date and the value supplied — the same behaviour as the
                "Start date" field on the restore wizard's schema step.

                Requires --run to execute.
                HELP);
        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler { return $this->handler; }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $v): BaseHandler
    {
        return new CourseRestore52Handler();
    }
}
