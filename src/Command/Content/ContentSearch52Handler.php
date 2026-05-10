<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Content;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * content:search implementation for Moodle 5.2.
 */
class ContentSearch52Handler extends BaseHandler
{
    /**
     * Static list of column names that hold user-visible text and may pass
     * through Moodle filters (format_string / format_text). Columns with
     * a *format companion are auto-discovered separately.
     */
    private const array KNOWN_TEXT_COLUMNS = [
        'name',
        'fullname',
        'shortname',
        'title',
        'subject',
        'concept',
        'rawname',
        'firstname',
        'lastname',
        'middlename',
        'alternatename',
        'firstnamephonetic',
        'lastnamephonetic',
        'username',
        'itemname',
        'tagname',
        'roomname',
    ];

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('pattern', InputArgument::REQUIRED, 'Text to search for')
            ->addOption('exact', null, InputOption::VALUE_NONE, 'Match the whole column value, not a substring')
            ->addOption('case-sensitive', null, InputOption::VALUE_NONE, 'Case-sensitive match (default: insensitive)')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of tables to scan (default: all)')
            ->addOption('skip-tables', null, InputOption::VALUE_REQUIRED, 'Comma-separated tables to skip', 'logstore_standard_log')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max matched rows per table', '100')
            ->addOption('snippet-length', null, InputOption::VALUE_REQUIRED, 'Max snippet length per match', '160');

        $command->addExampleUsage('Search dor substring \'Welcome\' across all text (possibly content) columns', '"Welcome"');
        $command->addExampleUsage('Case-sensitive substring search', '"AcmeCorp" --case-sensitive');
        $command->addExampleUsage('Exact match only', '"My course" --exact');
        $command->addExampleUsage('Restrict to specific tables', '"http://" --tables=course,page,book_chapters');
        $command->addExampleUsage('Skip tables, defaults to logstore_standard_log', '"foo" --skip-tables=logstore_standard_log');
        $command->addExampleUsage('Cap matches per table', '"foo" --limit=10');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');
        $pattern = (string) $input->getArgument('pattern');
        $limit = (int) $input->getOption('limit');
        $snippetLen = max(20, (int) $input->getOption('snippet-length'));
        $exact = (bool) $input->getOption('exact');
        $caseSensitive = (bool) $input->getOption('case-sensitive');

        if ($pattern === '') {
            $output->writeln('<error>Pattern cannot be empty.</error>');
            return Command::FAILURE;
        }

        $onlyTables = $this->parseTableList($input->getOption('tables'));
        $skipTables = $this->parseTableList($input->getOption('skip-tables'));

        $verbose->step('Scanning database schema for content text columns');

        $manager = $DB->get_manager();
        $schema = $manager->get_install_xml_schema();
        $tables = $schema->getTables();

        $candidates = [];
        foreach ($tables as $table) {
            $tableName = $table->getName();

            if ($onlyTables !== null && !in_array($tableName, $onlyTables, true)) {
                continue;
            }
            if ($skipTables !== null && in_array($tableName, $skipTables, true)) {
                continue;
            }
            if (!$table->getField('id')) {
                continue;
            }

            $allCols = [];
            foreach ($table->getFields() as $column) {
                $allCols[$column->getName()] = $column;
            }

            $textCols = [];
            foreach ($allCols as $colName => $column) {
                $type = $column->getType();
                if ($type !== XMLDB_TYPE_TEXT && $type !== XMLDB_TYPE_CHAR) {
                    continue;
                }

                // Auto-discovery: any text/char column with a *format companion.
                if (isset($allCols[$colName . 'format'])) {
                    $textCols[] = $colName;
                    continue;
                }

                // Static list of known column names.
                if (in_array($colName, self::KNOWN_TEXT_COLUMNS, true)) {
                    $textCols[] = $colName;
                }
            }

            if ($textCols !== []) {
                $candidates[$tableName] = $textCols;
            }
        }

        $verbose->done('Found ' . count($candidates) . ' table(s) with text columns');
        $verbose->step('Searching for "' . $pattern . '"');

        $headers = ['table', 'id', 'column', 'snippet'];
        $rows = [];
        $totalMatches = 0;

        foreach ($candidates as $tableName => $columns) {
            $whereParts = [];
            $params = [];

            foreach ($columns as $col) {
                if ($exact) {
                    $whereParts[] = $DB->sql_equal($col, '?', $caseSensitive);
                    $params[] = $pattern;
                } else {
                    $whereParts[] = $DB->sql_like($col, '?', $caseSensitive);
                    $params[] = '%' . $DB->sql_like_escape($pattern) . '%';
                }
            }

            $selectCols = 'id, ' . implode(', ', $columns);
            $sql = "SELECT $selectCols FROM {{$tableName}} WHERE " . implode(' OR ', $whereParts);

            try {
                $rs = $DB->get_recordset_sql($sql, $params, 0, $limit);
            } catch (Throwable $e) {
                $verbose->warn("Skipping $tableName: " . $e->getMessage());
                continue;
            }

            $tableMatches = 0;
            foreach ($rs as $record) {
                foreach ($columns as $col) {
                    $val = (string) ($record->$col ?? '');
                    if ($val === '') {
                        continue;
                    }

                    if ($exact) {
                        $match = $caseSensitive
                            ? ($val === $pattern)
                            : (strcasecmp($val, $pattern) === 0);
                    } else {
                        $match = $caseSensitive
                            ? str_contains($val, $pattern)
                            : (stripos($val, $pattern) !== false);
                    }

                    if (!$match) {
                        continue;
                    }

                    $rows[] = [
                        $tableName,
                        $record->id,
                        $col,
                        $this->makeSnippet($val, $pattern, $snippetLen, $caseSensitive),
                    ];
                    $tableMatches++;
                    $totalMatches++;
                }
            }
            $rs->close();

            if ($tableMatches > 0) {
                $verbose->info("$tableName: $tableMatches match(es)");
            }
        }

        $verbose->done("Found $totalMatches match(es) total");

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }

    /**
     * Parse a comma-separated list of table names. Returns null when the input
     * is null/empty so callers can distinguish "no filter" from "empty list".
     *
     * @return string[]|null
     */
    private function parseTableList(?string $input): ?array
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        $parts = array_filter(array_map('trim', explode(',', $input)), static fn($s) => $s !== '');
        return array_values($parts);
    }

    /**
     * Build a short, single-line excerpt around the first match.
     */
    private function makeSnippet(string $val, string $pattern, int $maxLen, bool $caseSensitive): string
    {
        $stripped = trim(preg_replace('/\s+/', ' ', strip_tags($val)) ?? '');
        if ($stripped === '') {
            return '';
        }

        if (strlen($stripped) <= $maxLen) {
            return $stripped;
        }

        $pos = $caseSensitive ? strpos($stripped, $pattern) : stripos($stripped, $pattern);
        if ($pos === false) {
            return substr($stripped, 0, $maxLen) . '…';
        }

        $context = max(20, (int) (($maxLen - strlen($pattern)) / 2));
        $start = max(0, $pos - $context);
        $snippet = substr($stripped, $start, $maxLen);
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($start + $maxLen < strlen($stripped)) {
            $snippet .= '…';
        }
        return $snippet;
    }
}
