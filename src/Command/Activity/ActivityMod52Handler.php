<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Activity;

use core_courseformat\formatactions;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * activity:mod implementation for Moodle 5.2.
 *
 * Uses core_courseformat\formatactions API instead of deprecated moveto_module().
 */
class ActivityMod52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('cmid', InputArgument::REQUIRED, 'Course module ID to modify')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Set activity name')
            ->addOption('visible', null, InputOption::VALUE_REQUIRED, 'Set visibility (1 or 0)')
            ->addOption('idnumber', null, InputOption::VALUE_REQUIRED, 'Set ID number')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Move to section number')
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Move before this course module ID (use with --section)')
            ->addOption('set', 'S', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set module property: key=value (repeatable)');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');
        $runMode = $input->getOption('run');

        $cmid = (int) $input->getArgument('cmid');
        $newName = $input->getOption('name');
        $newVisible = $input->getOption('visible');
        $newIdnumber = $input->getOption('idnumber');
        $newSection = $input->getOption('section');
        $beforeCmid = $input->getOption('before');
        $setOptions = $input->getOption('set');

        $verbose->step('Loading Moodle libraries');
        require_once $CFG->dirroot . '/course/lib.php';

        // Validate course module exists.
        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            $output->writeln("<error>Course module with ID $cmid not found.</error>");
            return Command::FAILURE;
        }

        $module = $DB->get_record('modules', ['id' => $cm->module]);
        $course = $DB->get_record('course', ['id' => $cm->course]);

        // Resolve current section number from section ID.
        $currentSectionRecord = $DB->get_record('course_sections', ['id' => $cm->section]);
        $currentSectionNum = $currentSectionRecord ? (int) $currentSectionRecord->section : 0;

        // Parse --set options.
        $setFields = [];
        foreach ($setOptions as $spec) {
            $parts = explode('=', $spec, 2);
            if (count($parts) !== 2) {
                $output->writeln("<error>Invalid --set format: '$spec'. Expected: key=value</error>");
                return Command::FAILURE;
            }
            [$key, $value] = $parts;
            if (is_numeric($value)) {
                $value = str_contains($value, '.') ? (float) $value : (int) $value;
            }
            $setFields[$key] = $value;
        }

        // Check something was requested.
        if ($newName === null && $newVisible === null && $newIdnumber === null && $newSection === null && $setFields === []) {
            $output->writeln('<error>No modifications specified. Use --name, --visible, --idnumber, --section, or --set.</error>');
            return Command::FAILURE;
        }

        // Build summary of changes.
        $changes = [];
        if ($newName !== null) {
            $changes[] = "name: \"{$cm->name}\" -> \"$newName\"";
        }
        if ($newVisible !== null) {
            $changes[] = "visible: {$cm->visible} -> $newVisible";
        }
        if ($newIdnumber !== null) {
            $changes[] = "idnumber: \"{$cm->idnumber}\" -> \"$newIdnumber\"";
        }
        if ($newSection !== null) {
            $changes[] = "section: {$currentSectionNum} -> $newSection";
            if ($beforeCmid !== null) {
                $changes[] = "before cmid: $beforeCmid";
            }
        }
        foreach ($setFields as $key => $value) {
            $instance = $DB->get_record($module->name, ['id' => $cm->instance]);
            $oldValue = $instance->$key ?? '(unset)';
            $changes[] = "$key: \"$oldValue\" -> \"$value\"";
        }

        if (!$runMode) {
            $output->writeln("<info>Dry run — would modify {$module->name} (cmid=$cmid) in course {$cm->course} (use --run to execute):</info>");
            foreach ($changes as $change) {
                $output->writeln("  $change");
            }
            return Command::SUCCESS;
        }

        $verbose->step("Modifying {$module->name} (cmid=$cmid)");

        // Apply name change.
        if ($newName !== null) {
            $verbose->info("Renaming to: $newName");
            $DB->set_field($module->name, 'name', $newName, ['id' => $cm->instance]);
            // Also update the cached name in course_modules if available.
            rebuild_course_cache($cm->course, true);
        }

        // Apply visibility change.
        if ($newVisible !== null) {
            $verbose->info("Setting visible: $newVisible");
            set_coursemodule_visible($cmid, (int) $newVisible, (int) $newVisible);
        }

        // Apply idnumber change.
        if ($newIdnumber !== null) {
            $verbose->info("Setting idnumber: $newIdnumber");
            $DB->set_field('course_modules', 'idnumber', $newIdnumber, ['id' => $cmid]);
        }

        // Move to different section.
        if ($newSection !== null) {
            $sectionRecord = $DB->get_record('course_sections', [
                'course' => $cm->course,
                'section' => (int) $newSection,
            ]);
            if (!$sectionRecord) {
                $output->writeln("<error>Section $newSection not found in course {$cm->course}.</error>");
                return Command::FAILURE;
            }

            $verbose->info("Moving to section $newSection");
            $beforeMod = $beforeCmid !== null ? (int) $beforeCmid : null;
            $action = formatactions::cm($cm->course);
            if ($beforeMod) {
                $action->move_before($cm->id, $beforeMod);
            } else {
                $action->move_end_section($cm->id, $sectionRecord->id);
            }
        }

        // Apply --set fields to the module instance.
        if ($setFields !== []) {
            $instance = $DB->get_record($module->name, ['id' => $cm->instance], '*', MUST_EXIST);
            foreach ($setFields as $key => $value) {
                $verbose->info("Setting $key: $value");
                $instance->$key = $value;
            }
            $DB->update_record($module->name, $instance);
            rebuild_course_cache($cm->course, true);
        }

        $verbose->done('Modifications applied');

        // Output the updated state.
        $cm = get_coursemodule_from_id('', $cmid);
        $activityName = $DB->get_field($module->name, 'name', ['id' => $cm->instance]);
        $updatedSection = $DB->get_record('course_sections', ['id' => $cm->section]);
        $updatedSectionNum = $updatedSection ? (int) $updatedSection->section : 0;

        $headers = ['cmid', 'module', 'name', 'section', 'visible', 'idnumber'];
        $rows = [[$cm->id, $module->name, $activityName, $updatedSectionNum, $cm->visible, $cm->idnumber]];

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }
}
