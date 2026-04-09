<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Section;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SectionCopy52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('from-course-id', InputArgument::REQUIRED, 'Source course ID')
            ->addArgument('from-section-number', InputArgument::REQUIRED, 'Section number to copy (0 = general)')
            ->addArgument('to-course-id', InputArgument::REQUIRED, 'Destination course ID');

        $command->addExampleUsage(
            'Copy section 3 from course 5 to course 10',
            '5 3 10 --run',
        );
        $command->addExampleUsage(
            'Dry run to preview what would be copied',
            '5 3 10',
        );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB, $USER;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $fromCourseId = (int) $input->getArgument('from-course-id');
        $sectionNumber = (int) $input->getArgument('from-section-number');
        $toCourseId = (int) $input->getArgument('to-course-id');

        require_once $CFG->dirroot . '/backup/util/includes/backup_includes.php';
        require_once $CFG->dirroot . '/backup/util/includes/restore_includes.php';

        // Validate source course.
        $fromCourse = $DB->get_record('course', ['id' => $fromCourseId]);
        if (!$fromCourse) {
            $output->writeln("<error>Source course with ID $fromCourseId not found.</error>");
            return Command::FAILURE;
        }

        // Validate source section.
        $section = $DB->get_record('course_sections', [
            'course' => $fromCourseId,
            'section' => $sectionNumber,
        ]);
        if (!$section) {
            $output->writeln("<error>Section $sectionNumber not found in course $fromCourseId.</error>");
            return Command::FAILURE;
        }

        // Validate destination course.
        $toCourse = $DB->get_record('course', ['id' => $toCourseId]);
        if (!$toCourse) {
            $output->writeln("<error>Destination course with ID $toCourseId not found.</error>");
            return Command::FAILURE;
        }

        // Count activities in the source section.
        $modinfo = get_fast_modinfo($fromCourseId);
        $activityCount = 0;
        if (isset($modinfo->sections[$sectionNumber])) {
            $activityCount = count($modinfo->sections[$sectionNumber]);
        }

        // Dry run.
        if (!$runMode) {
            $sectionName = !empty($section->name) ? $section->name : "(unnamed section $sectionNumber)";
            $output->writeln('<info>Dry run — would copy section (use --run to execute):</info>');
            $output->writeln("  Source: course {$fromCourse->shortname} (ID=$fromCourseId), section $sectionNumber");
            $output->writeln("  Section: $sectionName");
            $output->writeln("  Activities: $activityCount");
            $output->writeln("  Destination: course {$toCourse->shortname} (ID=$toCourseId)");
            if ($activityCount === 0) {
                $output->writeln('  Note: Section has no activities — only section metadata will be copied.');
            }
            return Command::SUCCESS;
        }

        // Phase 1: Backup the source section.
        $verbose->step('Creating backup controller for source course');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $fromCourseId,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_YES,
            \backup::MODE_GENERAL,
            $USER->id,
        );

        // Filter backup to include only the target section.
        $verbose->step("Filtering backup plan to section $sectionNumber");
        $tasks = $bc->get_plan()->get_tasks();

        foreach ($tasks as $task) {
            if ($task instanceof \backup_root_task) {
                $task->get_setting('users')->set_value('0');
                $task->get_setting('role_assignments')->set_value('0');
                $task->get_setting('logs')->set_value('0');
                $task->get_setting('grade_histories')->set_value('0');
                $task->get_setting('comments')->set_value('0');
                continue;
            }

            if ($task instanceof \backup_section_task) {
                if ((int) $task->get_sectionid() === (int) $section->id) {
                    $verbose->detail('Including section', "ID={$section->id}, number=$sectionNumber");
                    continue;
                }

                // Exclude this section.
                $settingName = 'section_' . $task->get_sectionid() . '_included';
                try {
                    $task->get_setting($settingName)->set_value(0);
                } catch (\Exception $e) {
                    // Setting dependency may prevent change; safe to ignore.
                }
                continue;
            }

            if ($task instanceof \backup_activity_task) {
                $cm = $DB->get_record('course_modules', ['id' => $task->get_moduleid()]);
                if ($cm && (int) $cm->section === (int) $section->id) {
                    $verbose->detail('Including activity', "cmid={$task->get_moduleid()}");
                    continue;
                }

                // Exclude this activity.
                $settingName = $task->get_modulename() . '_' . $task->get_moduleid() . '_included';
                try {
                    $task->get_setting($settingName)->set_value(0);
                } catch (\Exception $e) {
                    // Setting dependency may prevent change; safe to ignore.
                }
            }
        }

        // Execute backup.
        $verbose->step('Executing backup');
        $bc->set_status(\backup::STATUS_AWAITING);
        $bc->execute_plan();
        $result = $bc->get_results();

        if (!isset($result['backup_destination']) || !$result['backup_destination']) {
            $output->writeln('<error>Backup failed — no backup file produced.</error>');
            $bc->destroy();
            return Command::FAILURE;
        }

        // Extract backup for restore.
        $file = $result['backup_destination'];
        if (empty($CFG->tempdir)) {
            $CFG->tempdir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'temp';
        }

        $backupDir = 'moosh_section_copy_' . uniqid();
        $tempPath = $CFG->tempdir . '/backup/' . $backupDir;

        $fp = get_file_packer('application/vnd.moodle.backup');
        $file->extract_to_pathname($fp, $tempPath);
        $bc->destroy();

        $verbose->done('Backup completed');

        // Phase 2: Restore into the destination course.
        $verbose->step('Creating restore controller for destination course');
        $rc = new \restore_controller(
            $backupDir,
            $toCourseId,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING,
        );

        if ($rc->get_status() == \backup::STATUS_REQUIRE_CONV) {
            $rc->convert();
        }

        $verbose->step('Running restore pre-check');
        if (!$rc->execute_precheck()) {
            $check = $rc->get_precheck_results();
            if (isset($check['errors']) && !empty($check['errors'])) {
                $output->writeln('<error>Restore pre-check failed:</error>');
                foreach ($check['errors'] as $error) {
                    $output->writeln("  $error");
                }
                $rc->destroy();
                \fulldelete($tempPath);
                return Command::FAILURE;
            }
        }

        $verbose->step('Executing restore');
        $rc->execute_plan();
        $rc->destroy();

        // Cleanup.
        \fulldelete($tempPath);

        $verbose->done('Section copied');
        $output->writeln(sprintf(
            'Section %d copied from course %d (%s) to course %d (%s). Activities copied: %d.',
            $sectionNumber,
            $fromCourseId,
            $fromCourse->shortname,
            $toCourseId,
            $toCourse->shortname,
            $activityCount,
        ));

        return Command::SUCCESS;
    }
}
