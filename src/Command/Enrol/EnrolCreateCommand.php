<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Enrol;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create an enrolment method instance on a course.
 *
 * Generic over enrolment plugins that opt in to the CSV-upload contract
 * (meta, cohort, self, manual, guest, ...).
 */
class EnrolCreateCommand extends BaseCommand
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
            ->setName('enrol:create')
            ->setDescription('Add an enrolment method instance to a course')
            ->setHelp(<<<'HELP'
                Creates a new enrolment method instance on a course using the Moodle
                CSV-upload contract (validate_enrol_plugin_data → fill_enrol_custom_fields
                → add_custom_instance). Plugin must opt in via is_csv_upload_supported();
                in core that includes meta, cohort, self, manual and guest.

                Plugin-specific fields are passed via repeated --field KEY=VALUE.

                Examples:
                  enrol:create 42 --method=meta --field metacoursename=CHILD-101 --run
                  enrol:create 42 --method=cohort --field cohortidnumber=staff --field roleid=5 --run
                  enrol:create 42 --method=self --field password=secret --run
                  enrol:create 42 --method=meta --field metacoursename=CHILD-101 --field addtogroup=1 --status=disabled --run
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
        return new EnrolCreate52Handler();
    }
}
