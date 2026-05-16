<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Course;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CourseDelete52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command->addArgument(
            'courseid',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'Course ID(s) to delete',
        );

        $command->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Allow deletion even when the course recycle bin is enabled (a backup will still be created by Moodle)',
        );

        $command->addExampleUsage('Dry run — show what would be deleted', '5');
        $command->addExampleUsage('Delete multiple courses', '5 6 7 --run');
        $command->addExampleUsage('Delete when recycle bin is enabled', '5 --run --force');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $forceMode = $input->getOption('force');
        $courseIds = $input->getArgument('courseid');

        require_once $CFG->dirroot . '/course/lib.php';

        $recycleBinEnabled = (bool) get_config('tool_recyclebin', 'categorybinenable');

        // Validate all IDs first
        $verbose->step('Validating courses');
        $courses = [];
        foreach ($courseIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                $output->writeln("<error>Invalid course ID: $id</error>");
                return Command::FAILURE;
            }
            if ($id === 1) {
                $output->writeln('<error>Cannot delete the site course (ID=1).</error>');
                return Command::FAILURE;
            }
            $record = $DB->get_record('course', ['id' => $id]);
            if (!$record) {
                $output->writeln("<error>Course with ID $id not found.</error>");
                return Command::FAILURE;
            }
            $courses[] = $record;
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — the following courses would be deleted (use --run to execute):</info>');
            foreach ($courses as $course) {
                $output->writeln("  ID={$course->id}, shortname=\"{$course->shortname}\", fullname=\"{$course->fullname}\"");
            }
            if ($recycleBinEnabled) {
                $output->writeln('<comment>Note: Course recycle bin is enabled — Moodle will create a backup of each course before deletion.</comment>');
                $output->writeln('<comment>Use --force to allow deletion with backup creation, or disable the recycle bin first.</comment>');
            } else {
                $output->writeln('<info>Course recycle bin is disabled — courses will be permanently deleted without backup.</info>');
            }
            return Command::SUCCESS;
        }

        if ($recycleBinEnabled && !$forceMode) {
            $output->writeln('<error>Course recycle bin is enabled. Moodle would create a backup of each course before deletion.</error>');
            $output->writeln('<error>Use --force to allow deletion with backup creation, or disable the recycle bin first.</error>');
            return Command::FAILURE;
        }

        $verbose->step('Deleting ' . count($courses) . ' course(s)');

        foreach ($courses as $course) {
            $verbose->info("Deleting course \"{$course->shortname}\" (ID={$course->id})");
            $result = delete_course($course);
            if ($result) {
                $output->writeln("Deleted course \"{$course->shortname}\" (ID={$course->id}).");
            } else {
                $output->writeln("<error>Failed to delete course \"{$course->shortname}\" (ID={$course->id}).</error>");
            }
        }

        fix_course_sortorder();

        return Command::SUCCESS;
    }
}
