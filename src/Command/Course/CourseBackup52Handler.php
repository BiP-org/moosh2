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

class CourseBackup52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('courseid', InputArgument::REQUIRED, 'Course ID to backup')
            ->addOption('filename', 'f', InputOption::VALUE_REQUIRED, 'Output filename (default: auto-generated)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Output directory (default: current directory)')
            ->addOption('template', null, InputOption::VALUE_NONE, 'Template backup: no users, anonymized, no role assignments, no logs')
            ->addOption('fullbackup', null, InputOption::VALUE_NONE, 'Full backup: include logs and grade histories')
            ->addOption('exclude-cmids', null, InputOption::VALUE_REQUIRED,
                'Comma-separated course module IDs (cmids) to exclude from the backup');

        $command->addExampleUsage('Backup course 2 to current directory', '2 --run');
        $command->addExampleUsage('Backup course 2 into a specific directory', '2 --path=/tmp --run');
        $command->addExampleUsage('Backup course 2 with a custom filename', '2 --filename=mybackup.mbz --run');
        $command->addExampleUsage('Template backup (no users, anonymized)', '2 --template --run');
        $command->addExampleUsage('Backup course 2 excluding two activities', '2 --exclude-cmids=15,18 --run');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB, $USER;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $courseId = (int) $input->getArgument('courseid');
        $filename = $input->getOption('filename');
        $pathOpt = $input->getOption('path');
        $template = $input->getOption('template');
        $fullBackup = $input->getOption('fullbackup');
        $excludeCmidsRaw = $input->getOption('exclude-cmids');

        require_once $CFG->dirroot . '/backup/util/includes/backup_includes.php';

        $course = $DB->get_record('course', ['id' => $courseId]);
        if (!$course) {
            $output->writeln("<error>Course with ID $courseId not found.</error>");
            return Command::FAILURE;
        }

        $excludeCmids = [];
        if ($excludeCmidsRaw !== null) {
            foreach (explode(',', $excludeCmidsRaw) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                if (!ctype_digit($token) || (int) $token <= 0) {
                    $output->writeln("<error>Invalid --exclude-cmids value '$token' (expected positive integer cmids).</error>");
                    return Command::FAILURE;
                }
                $excludeCmids[(int) $token] = true;
            }
            foreach (array_keys($excludeCmids) as $cmid) {
                $cm = $DB->get_record('course_modules', ['id' => $cmid]);
                if (!$cm) {
                    $output->writeln("<error>Course module with cmid $cmid not found.</error>");
                    return Command::FAILURE;
                }
                if ((int) $cm->course !== $courseId) {
                    $output->writeln("<error>cmid $cmid does not belong to course $courseId (belongs to course {$cm->course}).</error>");
                    return Command::FAILURE;
                }
            }
        }

        $shortname = str_replace(['/', ' '], '_', $course->shortname);
        $outDir = $pathOpt ?? getcwd();

        if (!$filename) {
            $filename = $outDir . '/backup_' . $courseId . '_' . $shortname . '_' . date('Y.m.d') . '.mbz';
        } elseif ($filename[0] !== '/') {
            $filename = $outDir . '/' . $filename;
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — would create backup (use --run to execute):</info>');
            $output->writeln("  Course: {$course->shortname} (ID={$course->id})");
            $output->writeln("  Output: $filename");
            if ($template) {
                $output->writeln('  Mode: template (no users, anonymized)');
            } elseif ($fullBackup) {
                $output->writeln('  Mode: full (with logs and grade histories)');
            } else {
                $output->writeln('  Mode: general');
            }
            if ($excludeCmids) {
                $output->writeln('  Excluding cmids: ' . implode(', ', array_keys($excludeCmids)));
            }
            return Command::SUCCESS;
        }

        if (file_exists($filename)) {
            $output->writeln("<error>File '$filename' already exists.</error>");
            return Command::FAILURE;
        }

        $verbose->step('Creating backup controller');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseId,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_YES,
            \backup::MODE_GENERAL,
            $USER->id,
        );

        // Apply template/full settings and per-activity exclusions
        $tasks = $bc->get_plan()->get_tasks();
        foreach ($tasks as $task) {
            if ($task instanceof \backup_root_task) {
                if ($template) {
                    $task->get_setting('users')->set_value('0');
                    $task->get_setting('anonymize')->set_value('1');
                    $task->get_setting('role_assignments')->set_value('0');
                    $task->get_setting('filters')->set_value('0');
                    $task->get_setting('comments')->set_value('0');
                    $task->get_setting('logs')->set_value('0');
                    $task->get_setting('grade_histories')->set_value('0');
                }
                if ($fullBackup) {
                    $task->get_setting('logs')->set_value('1');
                    $task->get_setting('grade_histories')->set_value('1');
                }
                continue;
            }

            if ($excludeCmids && $task instanceof \backup_activity_task) {
                $cmid = (int) $task->get_moduleid();
                if (!isset($excludeCmids[$cmid])) {
                    continue;
                }
                $settingName = $task->get_modulename() . '_' . $cmid . '_included';
                try {
                    $task->get_setting($settingName)->set_value(0);
                    $verbose->detail('Excluding activity', "cmid=$cmid");
                } catch (\Exception $e) {
                    $output->writeln("<comment>Warning: could not exclude cmid $cmid: {$e->getMessage()}</comment>");
                }
            }
        }

        $verbose->step('Executing backup');
        $bc->set_status(\backup::STATUS_AWAITING);
        $bc->execute_plan();
        $result = $bc->get_results();

        if (isset($result['backup_destination']) && $result['backup_destination']) {
            $file = $result['backup_destination'];
            if (!$file->copy_content_to($filename)) {
                $output->writeln("<error>Failed to copy backup to '$filename'.</error>");
                return Command::FAILURE;
            }
            $verbose->done('Backup created');
            $output->writeln($filename);
        } else {
            $output->writeln($bc->get_backupid());
        }

        $bc->destroy();

        return Command::SUCCESS;
    }
}
