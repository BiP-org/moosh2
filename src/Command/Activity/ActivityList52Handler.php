<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Activity;

use Moosh2\Command\BaseHandler;
use Moosh2\Command\StdinIdsTrait;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityList52Handler extends BaseHandler
{
    use StdinIdsTrait;

    public function configureCommand(Command $command): void
    {
        $command
            ->addOption('course', 'c', InputOption::VALUE_REQUIRED, 'Limit to activities in this course ID')
            ->addOption('section', 's', InputOption::VALUE_REQUIRED, 'Limit to activities in this section number')
            ->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Limit to a module type (e.g. forum, quiz, assign)')
            ->addOption('id-only', 'i', InputOption::VALUE_NONE, 'Display only course module IDs (one line, space-separated)')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read space-separated cmids from stdin to filter results');

        $command->addExampleUsage('List all activities in course 41', '--course=41');
        $command->addExampleUsage('List all forums in course 41', '--course=41 --module=forum');
        $command->addExampleUsage('Pipe cmids into activity:mod to hide all activities in course 41', '--course=41 -i | xargs -n1 | moosh activity:mod --stdin --visible 0 --run');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $verbose = new VerboseLogger($output);

        $courseId = $input->getOption('course');
        $sectionNum = $input->getOption('section');
        $module = $input->getOption('module');
        $idOnly = $input->getOption('id-only');
        $format = $idOnly ? 'oneline' : $input->getOption('output');

        $verbose->step('Building query');

        $where = ['1=1'];
        $params = [];

        if ($courseId !== null) {
            $where[] = 'cm.course = ?';
            $params[] = (int) $courseId;
        }
        if ($sectionNum !== null) {
            $where[] = 'cs.section = ?';
            $params[] = (int) $sectionNum;
        }
        if ($module !== null) {
            $where[] = 'm.name = ?';
            $params[] = $module;
        }

        $sql = 'SELECT cm.id, cm.course, cs.section AS section_num, m.name AS modtype,
                       cm.instance, cm.visible, cm.idnumber
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY cm.course, cs.section, cm.id';

        $verbose->step('Executing query');
        $rows = $DB->get_records_sql($sql, $params);
        $verbose->done('Query returned ' . count($rows) . ' activity(ies)');

        $stdinIds = $this->readStdinIds($input);
        if ($stdinIds !== null) {
            $verbose->step('Filtering by stdin IDs: ' . implode(', ', $stdinIds));
            $allowed = array_flip($stdinIds);
            $rows = array_filter($rows, fn(object $row) => isset($allowed[(int) $row->id]));
        }

        $verbose->step('Resolving activity names');
        $output_rows = [];
        foreach ($rows as $row) {
            $name = $DB->get_field($row->modtype, 'name', ['id' => $row->instance]);
            $output_rows[] = [
                'cmid' => (int) $row->id,
                'course' => (int) $row->course,
                'section' => (int) $row->section_num,
                'module' => $row->modtype,
                'name' => $name !== false ? $name : '(unknown)',
                'visible' => (int) $row->visible,
                'idnumber' => $row->idnumber,
            ];
        }

        if ($idOnly) {
            $output_rows = array_map(fn(array $r) => ['cmid' => $r['cmid']], $output_rows);
        }

        $headers = $output_rows ? array_keys($output_rows[0]) : ['cmid', 'course', 'section', 'module', 'name', 'visible', 'idnumber'];
        $values = array_map('array_values', $output_rows);

        $verbose->step('Rendering ' . count($output_rows) . ' row(s) in "' . $format . '" format');
        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $values);

        return Command::SUCCESS;
    }
}
