<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Enrol;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * enrol:create implementation for Moodle 5.2.
 */
class EnrolCreate52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('courseid', InputArgument::REQUIRED, 'Course ID to add the enrolment method to')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'Enrolment plugin name (meta, cohort, self, manual, guest)')
            ->addOption(
                'field',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Plugin field as KEY=VALUE (repeatable). See Moodle docs for plugin-specific keys.',
            )
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Instance status: enabled or disabled (default: enabled)');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $format = $input->getOption('output');

        $courseId = (int) $input->getArgument('courseid');
        $method = $input->getOption('method');
        $rawFields = $input->getOption('field');
        $statusOpt = $input->getOption('status');

        if ($method === null || $method === '') {
            $output->writeln('<error>--method is required (e.g. --method=meta).</error>');
            return Command::FAILURE;
        }

        require_once $CFG->libdir . '/enrollib.php';

        $course = $DB->get_record('course', ['id' => $courseId]);
        if (!$course) {
            $output->writeln("<error>Course with ID $courseId not found.</error>");
            return Command::FAILURE;
        }

        $plugin = enrol_get_plugin($method);
        if (!$plugin) {
            $output->writeln("<error>Enrolment plugin '$method' not found.</error>");
            return Command::FAILURE;
        }

        if (!enrol_is_enabled($method)) {
            $output->writeln("<error>Enrolment plugin '$method' is not enabled site-wide.</error>");
            return Command::FAILURE;
        }

        if (!$plugin->is_csv_upload_supported()) {
            $output->writeln("<error>Enrolment plugin '$method' does not support generic instance creation (is_csv_upload_supported() = false).</error>");
            $output->writeln('<comment>Supported core plugins: meta, cohort, self, manual, guest.</comment>');
            return Command::FAILURE;
        }

        if (!$plugin->can_add_instance($course->id)) {
            $output->writeln("<error>Plugin '$method' refuses to add another instance to course $courseId (some plugins allow only one instance per course).</error>");
            return Command::FAILURE;
        }

        $status = ENROL_INSTANCE_ENABLED;
        if ($statusOpt !== null) {
            $statusLower = strtolower($statusOpt);
            if ($statusLower === 'disabled' || $statusLower === '0') {
                $status = ENROL_INSTANCE_DISABLED;
            } elseif ($statusLower !== 'enabled' && $statusLower !== '1') {
                $output->writeln("<error>Invalid --status value '$statusOpt'. Use 'enabled' or 'disabled'.</error>");
                return Command::FAILURE;
            }
        }

        $fields = $this->parseFields($rawFields, $output);
        if ($fields === null) {
            return Command::FAILURE;
        }
        $fields['status'] = $status;

        // Run Moodle's plugin-specific validation.
        $errors = $plugin->validate_enrol_plugin_data($fields, $course->id);
        if (!empty($errors)) {
            $output->writeln('<error>Validation failed:</error>');
            foreach ($errors as $key => $msg) {
                $output->writeln("  <error>- $key: " . $msg . '</error>');
            }
            return Command::FAILURE;
        }

        // Translate friendly fields (e.g. metacoursename → customint1).
        $fields = $plugin->fill_enrol_custom_fields($fields, $course->id);

        $contextError = $plugin->validate_plugin_data_context($fields, $course->id);
        if ($contextError !== null) {
            $output->writeln('<error>Context validation failed: ' . $contextError . '</error>');
            return Command::FAILURE;
        }

        if (!$runMode) {
            $output->writeln(sprintf(
                '<info>Dry run — would create %s enrolment instance on course %d (%s) with fields:</info>',
                $method,
                $course->id,
                $course->shortname,
            ));
            foreach ($fields as $key => $value) {
                $printable = is_scalar($value) ? (string) $value : json_encode($value);
                $output->writeln("  $key = $printable");
            }
            $output->writeln('<comment>Use --run to execute.</comment>');
            return Command::SUCCESS;
        }

        $verbose->step("Creating $method enrolment instance on course $courseId");

        // Prefer add_custom_instance() for plugins that override it (meta, cohort);
        // fall back to add_default_instance() for plugins that produce a default
        // instance (self, manual, guest); else use add_instance() directly.
        $instanceId = $plugin->add_custom_instance($course, $fields);
        if ($instanceId === null) {
            $instanceId = $plugin->add_default_instance($course);
        }
        if ($instanceId === null) {
            $instanceId = $plugin->add_instance($course, $fields);
        }

        if (!$instanceId) {
            $output->writeln('<error>Failed to create enrolment instance.</error>');
            return Command::FAILURE;
        }

        $instance = $DB->get_record('enrol', ['id' => $instanceId]);

        // Apply requested status; some plugins ignore the 'status' field on creation.
        if ($instance && (int) $instance->status !== $status && $plugin->can_hide_show_instance($instance)) {
            $plugin->update_status($instance, $status);
            $instance = $DB->get_record('enrol', ['id' => $instanceId]);
        }

        $verbose->done('Enrolment instance created');

        $headers = ['id', 'enrol', 'name', 'status', 'roleid', 'courseid', 'customint1', 'customint2'];
        $rows = [[
            $instance->id,
            $instance->enrol,
            $instance->name ?: '(default)',
            (int) $instance->status === ENROL_INSTANCE_ENABLED ? 'enabled' : 'disabled',
            $instance->roleid,
            $instance->courseid,
            $instance->customint1,
            $instance->customint2,
        ]];

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }

    /**
     * Parse repeated --field KEY=VALUE options into an associative array.
     * Returns null on parse error (the error is already printed).
     *
     * @param string[] $raw
     * @return array<string,mixed>|null
     */
    private function parseFields(array $raw, OutputInterface $output): ?array
    {
        $fields = [];
        foreach ($raw as $entry) {
            $pos = strpos($entry, '=');
            if ($pos === false || $pos === 0) {
                $output->writeln("<error>Invalid --field value '$entry' (expected KEY=VALUE).</error>");
                return null;
            }
            $key = substr($entry, 0, $pos);
            $value = substr($entry, $pos + 1);
            $fields[$key] = $value;
        }
        return $fields;
    }
}
