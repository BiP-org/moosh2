<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Context;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ContextSearch52Handler extends BaseHandler
{
    use ContextLevelTrait;

    public function configureCommand(Command $command): void
    {
        $command
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Context level: system, user, coursecat, course, module, block (or numeric)')
            ->addOption('instanceid', 'i', InputOption::VALUE_REQUIRED, 'Filter by instance ID (cmid for module, courseid for course, etc.)')
            ->addOption('path-contains', null, InputOption::VALUE_REQUIRED, 'Filter by path prefix (e.g. /1/3/ returns all descendants of context 3)');

        $command->addExampleUsage(
            'Find context ID for course module (cmid) 42',
            '--level=module --instanceid=42',
        );
        $command->addExampleUsage(
            'Find context for course ID 5',
            '--level=course --instanceid=5 -o json',
        );
        $command->addExampleUsage(
            'List all module contexts under a course context (path prefix)',
            '--level=module --path-contains=/1/3/15/',
        );
        $command->addExampleUsage(
            'List all contexts (no filter)',
            '-o csv',
        );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $format = $input->getOption('output');
        $level = $input->getOption('level');
        $instanceId = $input->getOption('instanceid');
        $pathContains = $input->getOption('path-contains');

        $where = [];
        $params = [];

        if ($level !== null) {
            try {
                $levelInt = is_numeric($level) ? (int) $level : $this->getLevelConstant($level);
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            $where[] = 'contextlevel = ?';
            $params[] = $levelInt;
        }

        if ($instanceId !== null) {
            $where[] = 'instanceid = ?';
            $params[] = (int) $instanceId;
        }

        if ($pathContains !== null) {
            $where[] = $DB->sql_like('path', '?');
            $params[] = $DB->sql_like_escape($pathContains) . '%';
        }

        $sql = 'SELECT id, contextlevel, instanceid, depth, path FROM {context}';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY depth, id';

        $records = $DB->get_records_sql($sql, $params);

        $headers = ['id', 'contextlevel', 'instanceid', 'depth', 'path'];
        $rows = [];
        foreach ($records as $r) {
            $rows[] = [(int) $r->id, (int) $r->contextlevel, (int) $r->instanceid, (int) $r->depth, $r->path];
        }

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }
}
