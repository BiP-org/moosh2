<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Instance;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstanceInfo52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('instanceid', InputArgument::REQUIRED, 'Activity instance ID')
            ->addArgument('modulename', InputArgument::OPTIONAL, 'Module type (e.g. quiz, forum, assign). If omitted, all module types are searched.');

        $command->addExampleUsage('Look up a quiz instance', '42 quiz');
        $command->addExampleUsage('Look up a forum instance', '7 forum');
        $command->addExampleUsage('Search all module types for instance ID 42', '42');
        $command->addExampleUsage('Pipe-friendly CSV output', '42 -o csv');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $format = $input->getOption('output');

        $instanceId = (int) $input->getArgument('instanceid');
        $modulename = $input->getArgument('modulename');

        if ($instanceId <= 0) {
            $output->writeln("<error>Invalid instance ID: $instanceId</error>");
            return Command::FAILURE;
        }

        $rows = [];

        if ($modulename !== null) {
            if (!\core_component::is_valid_plugin_name('mod', $modulename)) {
                $output->writeln("<error>Invalid module name: $modulename</error>");
                return Command::FAILURE;
            }

            $row = $this->lookup($modulename, $instanceId);
            if ($row !== null) {
                $rows[] = $row;
            }
        } else {
            $modules = $DB->get_records_menu('modules', null, 'name ASC', 'id, name');
            foreach ($modules as $name) {
                $row = $this->lookup($name, $instanceId);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows === []) {
            $scope = $modulename !== null ? " in module '$modulename'" : '';
            $output->writeln("<comment>No instance with ID $instanceId found$scope.</comment>");
            return Command::FAILURE;
        }

        $headers = ['modulename', 'instanceid', 'cmid', 'course', 'name'];
        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }

    private function lookup(string $modulename, int $instanceId): ?array
    {
        $cm = get_coursemodule_from_instance($modulename, $instanceId, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }

        return [
            $cm->modname ?? $modulename,
            $instanceId,
            (int) $cm->id,
            (int) $cm->course,
            $cm->name ?? '',
        ];
    }
}
