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
use Moosh2\Command\StdinIdsTrait;
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
    use StdinIdsTrait;

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('cmid', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Course module ID(s) to modify')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read space-separated cmids from stdin instead of positional arguments')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Set activity name')
            ->addOption('visible', null, InputOption::VALUE_REQUIRED, 'Set visibility (1 or 0)')
            ->addOption('idnumber', null, InputOption::VALUE_REQUIRED, 'Set ID number')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Move to section number')
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Move before this course module ID (use with --section; only valid with a single cmid)')
            ->addOption('set', 'S', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set module property: key=value (repeatable)');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');
        $runMode = $input->getOption('run');

        $newName = $input->getOption('name');
        $newVisible = $input->getOption('visible');
        $newIdnumber = $input->getOption('idnumber');
        $newSection = $input->getOption('section');
        $beforeCmid = $input->getOption('before');
        $setOptions = $input->getOption('set');

        // Resolve cmids: stdin takes precedence over positional args.
        $stdinIds = $this->readStdinIds($input);
        if ($stdinIds !== null) {
            $cmids = $stdinIds;
        } else {
            $cmids = array_map('intval', $input->getArgument('cmid'));
        }

        if (empty($cmids)) {
            $output->writeln('<error>No cmid provided. Pass cmid(s) as arguments or use --stdin.</error>');
            return Command::FAILURE;
        }

        if ($beforeCmid !== null && count($cmids) !== 1) {
            $output->writeln('<error>--before is only valid with a single cmid.</error>');
            return Command::FAILURE;
        }

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

        if ($newName === null && $newVisible === null && $newIdnumber === null && $newSection === null && $setFields === []) {
            $output->writeln('<error>No modifications specified. Use --name, --visible, --idnumber, --section, or --set.</error>');
            return Command::FAILURE;
        }

        $verbose->step('Loading Moodle libraries');
        require_once $CFG->dirroot . '/course/lib.php';

        // Validate all cmids up front.
        $cms = [];
        foreach ($cmids as $cmid) {
            $cmid = (int) $cmid;
            $cm = get_coursemodule_from_id('', $cmid);
            if (!$cm) {
                $output->writeln("<error>Course module with ID $cmid not found.</error>");
                return Command::FAILURE;
            }
            $cms[$cmid] = $cm;
        }

        // Dry-run preview.
        if (!$runMode) {
            $output->writeln('<info>Dry run — would modify the following activity(ies) (use --run to execute):</info>');
            foreach ($cms as $cmid => $cm) {
                $module = $DB->get_record('modules', ['id' => $cm->module]);
                $currentSectionRecord = $DB->get_record('course_sections', ['id' => $cm->section]);
                $currentSectionNum = $currentSectionRecord ? (int) $currentSectionRecord->section : 0;

                $output->writeln("  {$module->name} (cmid=$cmid, course={$cm->course}):");
                if ($newName !== null) {
                    $output->writeln("    name: \"{$cm->name}\" -> \"$newName\"");
                }
                if ($newVisible !== null) {
                    $output->writeln("    visible: {$cm->visible} -> $newVisible");
                }
                if ($newIdnumber !== null) {
                    $output->writeln("    idnumber: \"{$cm->idnumber}\" -> \"$newIdnumber\"");
                }
                if ($newSection !== null) {
                    $output->writeln("    section: {$currentSectionNum} -> $newSection");
                    if ($beforeCmid !== null) {
                        $output->writeln("    before cmid: $beforeCmid");
                    }
                }
                foreach ($setFields as $key => $value) {
                    $instance = $DB->get_record($module->name, ['id' => $cm->instance]);
                    $oldValue = $instance->$key ?? '(unset)';
                    $output->writeln("    $key: \"$oldValue\" -> \"$value\"");
                }
            }
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($cms as $cmid => $cm) {
            $module = $DB->get_record('modules', ['id' => $cm->module]);

            $verbose->step("Modifying {$module->name} (cmid=$cmid)");

            if ($newName !== null) {
                $verbose->info("Renaming to: $newName");
                $DB->set_field($module->name, 'name', $newName, ['id' => $cm->instance]);
                rebuild_course_cache($cm->course, true);
            }

            if ($newVisible !== null) {
                $verbose->info("Setting visible: $newVisible");
                set_coursemodule_visible($cmid, (int) $newVisible, (int) $newVisible);
            }

            if ($newIdnumber !== null) {
                $verbose->info("Setting idnumber: $newIdnumber");
                $DB->set_field('course_modules', 'idnumber', $newIdnumber, ['id' => $cmid]);
            }

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

            if ($setFields !== []) {
                $instance = $DB->get_record($module->name, ['id' => $cm->instance], '*', MUST_EXIST);
                foreach ($setFields as $key => $value) {
                    $verbose->info("Setting $key: $value");
                    $instance->$key = $value;
                }
                $DB->update_record($module->name, $instance);
                rebuild_course_cache($cm->course, true);
            }

            // Collect output row using updated state.
            $cm = get_coursemodule_from_id('', $cmid);
            $activityName = $DB->get_field($module->name, 'name', ['id' => $cm->instance]);
            $updatedSection = $DB->get_record('course_sections', ['id' => $cm->section]);
            $updatedSectionNum = $updatedSection ? (int) $updatedSection->section : 0;
            $rows[] = [$cm->id, $module->name, $activityName, $updatedSectionNum, $cm->visible, $cm->idnumber];
        }

        $verbose->done('Modifications applied to ' . count($cms) . ' activity(ies)');

        $headers = ['cmid', 'module', 'name', 'section', 'visible', 'idnumber'];
        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }
}
