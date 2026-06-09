<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\File;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FileStats52Handler extends BaseHandler
{
    /** Number of rows shown in the breakdown / per-course sections. */
    private const BREAKDOWN_LIMIT = 15;
    private const COURSE_LIMIT = 10;

    public function configureCommand(Command $command): void
    {
        $command
            ->addOption('by-component', null, InputOption::VALUE_NONE, 'Break down storage by component')
            ->addOption('by-filearea', null, InputOption::VALUE_NONE, 'Break down storage by file area')
            ->addOption('by-area-component', null, InputOption::VALUE_NONE, 'Break down storage by file area and component')
            ->addOption('by-course', null, InputOption::VALUE_NONE, 'Show the courses using the most storage')
            ->addOption('backups', null, InputOption::VALUE_NONE, 'Show backup storage grouped by user')
            ->addOption('disk-usage', null, InputOption::VALUE_NONE, 'Show on-disk size of dataroot and filedir (runs du)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show every breakdown section')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Show top N largest files', '0');

        $command->addExampleUsage('Overall file storage statistics', '');
        $command->addExampleUsage('Break down storage by component', '--by-component');
        $command->addExampleUsage('Break down storage by file area', '--by-filearea');
        $command->addExampleUsage('Show the courses using the most storage', '--by-course');
        $command->addExampleUsage('Show backup storage per user', '--backups');
        $command->addExampleUsage('Show on-disk usage of dataroot/filedir', '--disk-usage');
        $command->addExampleUsage('Show everything', '--all');
        $command->addExampleUsage('Show top 20 largest files', '--top=20');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $DB, $CFG;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');
        $all = (bool) $input->getOption('all');
        $topN = (int) $input->getOption('top');

        $formatter = new ResultFormatter($output, $format);

        $verbose->step('Gathering file statistics');

        // ── Overall stats ──────────────────────────────────────────
        // Two scans of {files}: one for the raw totals, one for the
        // deduplicated (per content hash) totals. Combining the count and
        // sum into single queries keeps this at two passes instead of four,
        // which matters when {files} has millions of rows.
        $totals = $DB->get_record_sql(
            "SELECT COUNT(*) AS cnt, SUM(filesize) AS sz FROM {files} WHERE filename != '.'"
        );
        $totalFiles = (int) ($totals->cnt ?? 0);
        $totalSize = (int) ($totals->sz ?? 0);

        // GROUP BY contenthash collapses duplicates; contenthash determines
        // filesize, so COUNT(*) of the groups is the unique-hash count and
        // SUM(sz) is the logical unique size.
        $unique = $DB->get_record_sql(
            "SELECT COUNT(*) AS cnt, SUM(sz) AS total FROM (
                SELECT MIN(filesize) AS sz
                  FROM {files}
                 WHERE filename != '.' AND filesize > 0
              GROUP BY contenthash
             ) s"
        );
        $uniqueHashes = (int) ($unique->cnt ?? 0);
        $uniqueSize = (int) ($unique->total ?? 0);
        $duplicateWaste = $totalSize - $uniqueSize;

        $output->writeln('<info>=== File Storage Statistics ===</info>');
        $rows = [
            ['Total file records', number_format($totalFiles)],
            ['Total size (all records)', $this->formatSize($totalSize ?? 0)],
            ['Unique content hashes', number_format($uniqueHashes)],
            ['Unique content size', $this->formatSize($uniqueSize ?? 0)],
            ['Duplicate space (logical)', $this->formatSize($duplicateWaste)],
        ];
        $formatter->display(['Metric', 'Value'], $rows);

        // ── Disk usage (dataroot / filedir) ────────────────────────
        if ($all || $input->getOption('disk-usage')) {
            $verbose->step('Measuring on-disk usage');
            $output->writeln('');
            $output->writeln('<info>=== Disk Usage ===</info>');

            $rows = [];
            $datarootSize = $this->getDirectorySize($CFG->dataroot);
            $rows[] = ['dataroot', $datarootSize === null ? 'n/a' : $this->formatSize($datarootSize)];

            $filedir = $CFG->dataroot . '/filedir';
            $filedirSize = is_dir($filedir) ? $this->getDirectorySize($filedir) : null;
            $rows[] = ['filedir', $filedirSize === null ? 'n/a' : $this->formatSize($filedirSize)];

            $formatter->display(['Location', 'Size'], $rows);
        }

        // ── By component ───────────────────────────────────────────
        if ($all || $input->getOption('by-component')) {
            $verbose->step('Breaking down by component');
            $output->writeln('');
            $output->writeln('<info>=== By Component ===</info>');

            $sql = "SELECT component, COUNT(*) AS file_count, SUM(filesize) AS total_size
                      FROM {files}
                     WHERE filename != '.'
                     GROUP BY component
                     ORDER BY total_size DESC";
            $rows = [];
            foreach ($DB->get_records_sql($sql) as $c) {
                $rows[] = [$c->component, number_format($c->file_count), $this->formatSize($c->total_size)];
            }
            $formatter->display(['component', 'files', 'total_size'], $rows);
        }

        // ── By file area ───────────────────────────────────────────
        if ($all || $input->getOption('by-filearea')) {
            $verbose->step('Breaking down by file area');
            $output->writeln('');
            $output->writeln('<info>=== By File Area ===</info>');

            $sql = "SELECT filearea, COUNT(*) AS file_count, SUM(filesize) AS total_size
                      FROM {files}
                     WHERE filename != '.'
                     GROUP BY filearea
                     ORDER BY total_size DESC";
            $rows = [];
            foreach ($DB->get_records_sql($sql) as $a) {
                $rows[] = [$a->filearea, number_format($a->file_count), $this->formatSize($a->total_size)];
            }
            $formatter->display(['filearea', 'files', 'total_size'], $rows);
        }

        // ── By file area + component ───────────────────────────────
        if ($all || $input->getOption('by-area-component')) {
            $verbose->step('Breaking down by file area and component');
            $output->writeln('');
            $output->writeln('<info>=== By File Area and Component ===</info>');

            // Deduplicate by contenthash so shared files are only counted once.
            $sql = "SELECT filearea, component, SUM(filesize) AS total_size, COUNT(*) AS file_count
                      FROM (
                            SELECT DISTINCT contenthash, component, filearea, filesize
                              FROM {files}
                             WHERE filename != '.' AND filesize > 0
                           ) files
                     GROUP BY filearea, component
                     ORDER BY total_size DESC";
            $rows = [];
            foreach ($this->limitRecords($sql, self::BREAKDOWN_LIMIT) as $r) {
                $rows[] = [$r->filearea, $r->component, number_format($r->file_count), $this->formatSize($r->total_size)];
            }
            $formatter->display(['filearea', 'component', 'unique_files', 'size'], $rows);
        }

        // ── By course ──────────────────────────────────────────────
        if ($all || $input->getOption('by-course')) {
            $verbose->step('Breaking down by course');
            $output->writeln('');
            $output->writeln("<info>=== Top " . self::COURSE_LIMIT . " Courses by Storage ===</info>");

            $rows = [];
            foreach ($this->getCourseUsage($verbose) as $c) {
                $rows[] = [
                    $c['id'],
                    $this->truncate($c['name'], 40),
                    $this->formatSize($c['all']),
                    $this->formatSize($c['distinct']),
                    $this->formatSize($c['unique']),
                ];
            }
            $formatter->display(['id', 'course', 'all', 'distinct', 'unique (freed if deleted)'], $rows);
        }

        // ── Backups by user ────────────────────────────────────────
        if ($all || $input->getOption('backups')) {
            $verbose->step('Collecting backup storage');
            $output->writeln('');
            $output->writeln('<info>=== Backup Storage by User ===</info>');

            $sql = "SELECT u.id AS userid, u.username, COUNT(*) AS file_count, SUM(f.filesize) AS total_size
                      FROM {files} f
                 LEFT JOIN {user} u ON u.id = f.userid
                     WHERE (f.filearea = :filearea OR f.component = :component)
                       AND f.filename != '.'
                  GROUP BY u.id, u.username
                  ORDER BY total_size DESC";
            $records = $DB->get_records_sql($sql, ['filearea' => 'backup', 'component' => 'backup']);
            $rows = [];
            foreach ($records as $r) {
                $rows[] = [
                    $r->userid ?? '-',
                    $r->username ?? '(unknown)',
                    number_format($r->file_count),
                    $this->formatSize($r->total_size),
                ];
            }
            $formatter->display(['userid', 'username', 'files', 'total_size'], $rows);
        }

        // ── Top N largest files ────────────────────────────────────
        if ($topN > 0) {
            $verbose->step("Finding top $topN largest files");
            $output->writeln('');
            $output->writeln("<info>=== Top $topN Largest Files ===</info>");

            $sql = "SELECT id, filename, component, filearea, filesize, contenthash
                      FROM {files}
                     WHERE filename != '.'
                     ORDER BY filesize DESC";
            $rows = [];
            foreach ($this->limitRecords($sql, $topN) as $f) {
                $rows[] = [$f->id, $f->filename, $f->component, $f->filearea, $this->formatSize($f->filesize)];
            }
            $formatter->display(['id', 'filename', 'component', 'filearea', 'size'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * Compute per-course storage usage for the courses using the most space.
     *
     * Returns rows with three sizes:
     *  - all:      total of every file record in the course subtree
     *  - distinct: deduplicated by content hash within the course subtree
     *  - unique:   distinct files whose content exists nowhere outside this
     *              course (excluding the 'user' component) — the space that
     *              would be freed if the course were deleted.
     *
     * @return list<array{id: int, name: string, all: int, distinct: int, unique: int}>
     */
    private function getCourseUsage(VerboseLogger $verbose): array
    {
        global $DB;

        // Course contexts (skip the site course, id 1). This is the only data
        // we hold in memory — bounded by the number of courses, not files.
        $courses = $DB->get_records_sql(
            "SELECT ctx.id AS ctxid, ctx.path, c.id AS courseid, c.fullname
               FROM {course} c
               JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
              WHERE c.id <> 1",
            ['level' => CONTEXT_COURSE],
        );

        // Map course-context id -> course, and course id -> course.
        $ctxToCourse = [];
        $byCourseId = [];
        foreach ($courses as $course) {
            $ctxToCourse[(int) $course->ctxid] = $course;
            $byCourseId[(int) $course->courseid] = $course;
        }

        // Single scan: total size grouped by the file's context path. The
        // number of rows is bounded by the number of file-bearing contexts,
        // not the row count of {files}.
        $verbose->info('Aggregating file sizes by context');
        $byPath = $DB->get_records_sql(
            "SELECT fc.path AS path, SUM(f.filesize) AS sz
               FROM {files} f
               JOIN {context} fc ON fc.id = f.contextid
              WHERE f.filesize > 0 AND f.filename != '.'
           GROUP BY fc.path",
        );

        // Attribute each file-bearing context to its course ancestor by
        // walking the path segments (e.g. /1/3/57/890 -> course ctx 57).
        $totals = [];
        foreach ($byPath as $row) {
            foreach (explode('/', trim($row->path, '/')) as $segment) {
                $ctxid = (int) $segment;
                if (isset($ctxToCourse[$ctxid])) {
                    $courseid = (int) $ctxToCourse[$ctxid]->courseid;
                    $totals[$courseid] = ($totals[$courseid] ?? 0) + (int) $row->sz;
                    break;
                }
            }
        }
        arsort($totals);
        $top = array_slice($totals, 0, self::COURSE_LIMIT, true);

        // Expensive pass: distinct + unique only for the top courses, filtered
        // by an explicit list of context ids so the indexed {files}.contextid
        // is used instead of a path-LIKE join over the whole table.
        $result = [];
        foreach ($top as $courseid => $allsize) {
            $course = $byCourseId[$courseid];
            $verbose->info("Analysing course $courseid");

            $ctxids = $this->courseContextIds((int) $course->ctxid, $course->path);
            if (empty($ctxids)) {
                $result[] = ['id' => $courseid, 'name' => $course->fullname, 'all' => $allsize, 'distinct' => 0, 'unique' => 0];
                continue;
            }

            [$insql, $inparams] = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'ictx');
            $distinct = $DB->get_field_sql(
                "SELECT SUM(sz) FROM (
                    SELECT MIN(filesize) AS sz
                      FROM {files}
                     WHERE filesize > 0 AND filename != '.' AND contextid $insql
                  GROUP BY contenthash
                 ) d",
                $inparams,
            );

            // Distinct content in the course not present in any context outside
            // it (ignoring the 'user' component, e.g. user private files).
            [$insql2, $inparams2] = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'uctx');
            [$notinsql, $notinparams] = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'xctx', false);
            $unique = $DB->get_field_sql(
                "SELECT SUM(sz) FROM (
                    SELECT MIN(f.filesize) AS sz
                      FROM {files} f
                     WHERE f.filesize > 0 AND f.filename != '.' AND f.contextid $insql2
                       AND NOT EXISTS (
                            SELECT 1
                              FROM {files} f2
                             WHERE f2.contenthash = f.contenthash
                               AND f2.filesize > 0
                               AND f2.component <> 'user'
                               AND f2.contextid $notinsql
                       )
                  GROUP BY f.contenthash
                 ) u",
                array_merge($inparams2, $notinparams),
            );

            $result[] = [
                'id' => $courseid,
                'name' => $course->fullname,
                'all' => $allsize,
                'distinct' => (int) ($distinct ?? 0),
                'unique' => (int) ($unique ?? 0),
            ];
        }

        return $result;
    }

    /**
     * All context ids in a course subtree (the course context plus descendants).
     *
     * @return int[]
     */
    private function courseContextIds(int $coursectxid, string $coursepath): array
    {
        global $DB;

        $like = $DB->sql_like('path', ':descendants');
        $records = $DB->get_records_sql(
            "SELECT id FROM {context} WHERE id = :self OR $like",
            ['self' => $coursectxid, 'descendants' => $coursepath . '/%'],
        );

        return array_map('intval', array_keys($records));
    }

    /**
     * Run a query limited to $limit rows in a portable way.
     *
     * @return array<int, \stdClass>
     */
    private function limitRecords(string $sql, int $limit): array
    {
        global $DB;
        return $DB->get_records_sql($sql, [], 0, $limit);
    }

    private function getDirectorySize(string $path): ?int
    {
        if (!is_dir($path)) {
            return null;
        }

        $result = @exec("du -s -B 1 " . escapeshellarg($path) . " 2>/dev/null", $outputLines, $exitCode);
        if ($exitCode === 0 && !empty($outputLines)) {
            $parts = preg_split('/\s+/', trim($outputLines[0]), 2);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return (int) $parts[0];
            }
        }

        return null;
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 1) . '…';
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
