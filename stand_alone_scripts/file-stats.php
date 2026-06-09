#!/usr/bin/env php
<?php
/**
 * Standalone file storage statistics script for Moodle.
 *
 * This script replicates the functionality of the moosh2 file:stats command
 * (src/Command/File/FileStats52Handler.php) as a single self-contained file
 * with no external dependencies. It parses Moodle's config.php to extract the
 * database connection settings and connects directly via PDO.
 *
 * Targets Moodle 4.5 and PHP 8.1. Works with MySQL/MariaDB and PostgreSQL.
 *
 * Derived from moosh2 — Moodle Shell (https://github.com/tmuras/moosh)
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const COURSE_LIMIT = 10;     // Courses shown in the --by-course section.
const BREAKDOWN_LIMIT = 15;  // Rows shown in the --by-area-component section.
const CONTEXT_COURSE = 50;   // Moodle's CONTEXT_COURSE constant.

// ── Usage ────────────────────────────────────────────────────────

function usage(): void {
    $script = basename(__FILE__);
    fwrite(STDERR, <<<USAGE
Usage: php $script <moodle-path> [options]

Show file storage statistics for a Moodle installation. Connects directly to
the database (MySQL/MariaDB or PostgreSQL) using credentials from config.php.

By default only the overall totals are shown. Add flags to include extra
breakdowns, or --all to show every section.

Arguments:
  moodle-path           Path to the Moodle installation directory

Options:
  --by-component        Break down storage by component
  --by-filearea         Break down storage by file area
  --by-area-component   Break down storage by file area and component
  --by-course           Show the courses using the most storage
  --backups             Show backup storage grouped by user
  --disk-usage          Show on-disk size of dataroot and filedir (runs du)
  --all                 Show every breakdown section
  --top=N               Show top N largest files
  --json                Output everything as a single JSON document
  -h, --help            Show this help

Note: on installations with a very large files table some sections are
inherently heavy: --top sorts on the unindexed filesize column,
--by-area-component deduplicates the whole table, and --disk-usage walks the
entire filedir with du. Enable them individually rather than via --all there.

Examples:
  php $script /var/www/html/moodle
  php $script /var/www/html/moodle --by-component --by-filearea
  php $script /var/www/html/moodle --by-course
  php $script /var/www/html/moodle --all
  php $script /var/www/html/moodle --top=20 --json

USAGE);
    exit(1);
}

// ── Helpers ──────────────────────────────────────────────────────

function fatal(string $msg): void {
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}

function info(string $msg): void {
    fwrite(STDERR, "$msg\n");
}

function formatSize(int $bytes): string {
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

function truncate(string $text, int $length): string {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length - 1) . '…';
}

/**
 * Measure the on-disk size of a directory using du. Returns bytes or null.
 */
function getDirectorySize(string $path): ?int {
    if (!is_dir($path)) {
        return null;
    }
    $outputLines = [];
    $exitCode = 1;
    @exec('du -s -B 1 ' . escapeshellarg($path) . ' 2>/dev/null', $outputLines, $exitCode);
    if ($exitCode === 0 && !empty($outputLines)) {
        $parts = preg_split('/\s+/', trim($outputLines[0]), 2);
        if (isset($parts[0]) && is_numeric($parts[0])) {
            return (int) $parts[0];
        }
    }
    return null;
}

/**
 * Parse Moodle config.php to extract DB connection settings.
 *
 * Reads the file as text and extracts $CFG->dbtype, dbhost, dbname, dbuser,
 * dbpass, prefix and dataroot using regex — it does not execute the file.
 */
function parseMoodleConfig(string $moodlePath): array {
    $configPath = $moodlePath . '/config.php';
    if (!file_exists($configPath)) {
        fatal("config.php not found at $configPath");
    }

    $content = file_get_contents($configPath);

    // Moodle 5.x public/ layout: config.php may redirect to ../config.php.
    if (preg_match('/\$configfile\s*=\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
        $resolvedPath = realpath($moodlePath . '/' . $m[1]);
        if ($resolvedPath && file_exists($resolvedPath)) {
            $content = file_get_contents($resolvedPath);
        }
    }

    $cfg = [];
    $fields = ['dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'dataroot'];
    foreach ($fields as $field) {
        if (preg_match('/\$CFG->' . $field . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) {
            $cfg[$field] = $m[1];
        }
    }

    $cfg['dbport'] = null;
    if (preg_match("/['\"]dbport['\"]\s*=>\s*(\d+)/", $content, $m)) {
        $cfg['dbport'] = (int) $m[1];
    }

    foreach (['dbtype', 'dbhost', 'dbname', 'dbuser', 'prefix'] as $required) {
        if (!isset($cfg[$required])) {
            fatal("Could not extract \$CFG->$required from config.php");
        }
    }
    if (!isset($cfg['dbpass'])) {
        $cfg['dbpass'] = '';
    }

    return $cfg;
}

/**
 * Connect to the Moodle database via PDO. Supports MySQL/MariaDB and PostgreSQL.
 */
function connectDb(array $cfg): PDO {
    $type = $cfg['dbtype'];
    $host = $cfg['dbhost'];
    $port = $cfg['dbport'];
    $socket = null;

    // Socket connections (host is a filesystem path).
    if (strpos($host, '/') !== false) {
        $socket = $host;
        $host = 'localhost';
    } elseif (strpos($host, ':') !== false) {
        // host:port form.
        [$host, $portStr] = explode(':', $host, 2);
        $port = (int) $portStr;
    }

    if (in_array($type, ['mariadb', 'mysqli', 'auroramysql'], true)) {
        $port = $port ?: 3306;
        if ($socket !== null) {
            $dsn = "mysql:unix_socket=$socket;dbname={$cfg['dbname']};charset=utf8mb4";
        } else {
            $dsn = "mysql:host=$host;port=$port;dbname={$cfg['dbname']};charset=utf8mb4";
        }
    } elseif (in_array($type, ['pgsql', 'aurorapostgres'], true)) {
        $port = $port ?: 5432;
        // For PostgreSQL a socket is selected by passing the directory as host.
        $pghost = $socket !== null ? $socket : $host;
        $dsn = "pgsql:host=$pghost;port=$port;dbname={$cfg['dbname']}";
    } else {
        fatal("Unsupported database type: $type. This script supports MySQL/MariaDB and PostgreSQL.");
    }

    try {
        $pdo = new PDO($dsn, $cfg['dbuser'], $cfg['dbpass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        fatal('Database connection failed: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * Run a query and return all rows.
 */
function query(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Run a query and return the first column of the first row (or null).
 */
function queryField(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

// ── Per-course usage ─────────────────────────────────────────────

/**
 * Compute per-course storage for the courses using the most space.
 *
 * Strategy (kept cheap even when {files} is huge):
 *  1. One scan grouping total size by the file's context path. The result is
 *     bounded by the number of file-bearing contexts, not the file count.
 *  2. Each path is mapped to its course ancestor in PHP by walking the path
 *     segments against the (small) set of course-context ids.
 *  3. Only the top COURSE_LIMIT courses get the expensive distinct/unique
 *     queries, filtered by an explicit list of context ids so the indexed
 *     files.contextid is used instead of a path-LIKE join over the table.
 *
 * @return array<int, array{id:int,name:string,all:int,distinct:int,unique:int}>
 */
function getCourseUsage(PDO $pdo, string $files, string $context, string $course): array {
    // Course contexts (skip the site course, id 1).
    $courses = query($pdo,
        "SELECT ctx.id AS ctxid, ctx.path AS path, c.id AS courseid, c.fullname AS fullname
           FROM {$course} c
           JOIN {$context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = ?
          WHERE c.id <> 1",
        [CONTEXT_COURSE]);

    $ctxToCourse = [];
    $byCourseId = [];
    foreach ($courses as $c) {
        $ctxToCourse[(int) $c['ctxid']] = $c;
        $byCourseId[(int) $c['courseid']] = $c;
    }

    // One scan: total size grouped by the file's context path.
    $byPath = query($pdo,
        "SELECT fc.path AS path, SUM(f.filesize) AS sz
           FROM {$files} f
           JOIN {$context} fc ON fc.id = f.contextid
          WHERE f.filesize > 0 AND f.filename <> '.'
       GROUP BY fc.path");

    // Attribute each file-bearing context to its course ancestor.
    $totals = [];
    foreach ($byPath as $row) {
        foreach (explode('/', trim((string) $row['path'], '/')) as $segment) {
            $ctxid = (int) $segment;
            if (isset($ctxToCourse[$ctxid])) {
                $cid = (int) $ctxToCourse[$ctxid]['courseid'];
                $totals[$cid] = ($totals[$cid] ?? 0) + (int) $row['sz'];
                break;
            }
        }
    }
    arsort($totals);
    $top = array_slice($totals, 0, COURSE_LIMIT, true);

    $result = [];
    foreach ($top as $courseid => $allsize) {
        $c = $byCourseId[$courseid];

        // All context ids in this course subtree.
        $ctxRows = query($pdo,
            "SELECT id FROM {$context} WHERE id = ? OR path LIKE ?",
            [(int) $c['ctxid'], $c['path'] . '/%']);
        $ctxids = array_map(fn($r) => (int) $r['id'], $ctxRows);

        if (empty($ctxids)) {
            $result[] = ['id' => $courseid, 'name' => $c['fullname'], 'all' => $allsize, 'distinct' => 0, 'unique' => 0];
            continue;
        }

        $ph = implode(',', array_fill(0, count($ctxids), '?'));

        $distinct = (int) queryField($pdo,
            "SELECT SUM(sz) FROM (
                SELECT MIN(filesize) AS sz
                  FROM {$files}
                 WHERE filesize > 0 AND filename <> '.' AND contextid IN ($ph)
              GROUP BY contenthash
             ) d",
            $ctxids);

        // Distinct content in the course not present in any context outside it
        // (ignoring the 'user' component, e.g. user private files).
        $unique = (int) queryField($pdo,
            "SELECT SUM(sz) FROM (
                SELECT MIN(f.filesize) AS sz
                  FROM {$files} f
                 WHERE f.filesize > 0 AND f.filename <> '.' AND f.contextid IN ($ph)
                   AND NOT EXISTS (
                        SELECT 1 FROM {$files} f2
                         WHERE f2.contenthash = f.contenthash
                           AND f2.filesize > 0
                           AND f2.component <> 'user'
                           AND f2.contextid NOT IN ($ph)
                   )
              GROUP BY f.contenthash
             ) u",
            array_merge($ctxids, $ctxids));

        $result[] = [
            'id' => $courseid,
            'name' => $c['fullname'],
            'all' => $allsize,
            'distinct' => $distinct,
            'unique' => $unique,
        ];
    }

    return $result;
}

// ── Output ───────────────────────────────────────────────────────

function printTable(string $title, array $headers, array $rows): void {
    echo "\n=== $title ===\n";
    if (empty($rows)) {
        echo "(no data)\n";
        return;
    }
    $widths = [];
    foreach ($headers as $i => $h) {
        $widths[$i] = strlen((string) $h);
    }
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
        }
    }
    $render = function (array $cells) use ($widths): string {
        $out = [];
        foreach (array_values($cells) as $i => $cell) {
            $out[] = str_pad((string) $cell, $widths[$i]);
        }
        return '  ' . implode('  ', $out);
    };
    echo $render($headers) . "\n";
    $sep = [];
    foreach ($widths as $w) {
        $sep[] = str_repeat('-', $w);
    }
    echo '  ' . implode('  ', $sep) . "\n";
    foreach ($rows as $row) {
        echo $render($row) . "\n";
    }
}

// ── Argument parsing ─────────────────────────────────────────────

$args = $argv;
array_shift($args);

$moodlePath = null;
$opts = [
    'by-component' => false,
    'by-filearea' => false,
    'by-area-component' => false,
    'by-course' => false,
    'backups' => false,
    'disk-usage' => false,
    'all' => false,
    'json' => false,
];
$topN = 0;

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        usage();
    } elseif (substr($arg, 0, 6) === '--top=') {
        $topN = max(0, (int) substr($arg, 6));
    } elseif (substr($arg, 0, 2) === '--' && array_key_exists(substr($arg, 2), $opts)) {
        $opts[substr($arg, 2)] = true;
    } elseif (substr($arg, 0, 1) !== '-') {
        if ($moodlePath === null) {
            $moodlePath = rtrim($arg, '/');
        } else {
            fatal("Unexpected argument: $arg");
        }
    } else {
        fatal("Unknown option: $arg");
    }
}

if ($moodlePath === null) {
    usage();
}
if (!is_dir($moodlePath)) {
    fatal("Moodle directory not found: $moodlePath");
}

$all = $opts['all'];

// ── Connect ──────────────────────────────────────────────────────

$cfg = parseMoodleConfig($moodlePath);
$prefix = $cfg['prefix'];
$tFiles = $prefix . 'files';
$tContext = $prefix . 'context';
$tCourse = $prefix . 'course';
$tUser = $prefix . 'user';

if (!$opts['json']) {
    info("Database '{$cfg['dbname']}' ({$cfg['dbtype']}) on {$cfg['dbhost']}");
}
$pdo = connectDb($cfg);

$result = [];

// ── Overall stats ────────────────────────────────────────────────

$totals = query($pdo, "SELECT COUNT(*) AS cnt, SUM(filesize) AS sz FROM {$tFiles} WHERE filename <> '.'")[0];
$totalFiles = (int) $totals['cnt'];
$totalSize = (int) $totals['sz'];

$uniqueRow = query($pdo,
    "SELECT COUNT(*) AS cnt, SUM(sz) AS total FROM (
        SELECT MIN(filesize) AS sz
          FROM {$tFiles}
         WHERE filename <> '.' AND filesize > 0
      GROUP BY contenthash
     ) s")[0];
$uniqueHashes = (int) $uniqueRow['cnt'];
$uniqueSize = (int) $uniqueRow['total'];
$duplicateWaste = $totalSize - $uniqueSize;

$result['overall'] = [
    'total_file_records' => $totalFiles,
    'total_size_bytes' => $totalSize,
    'unique_content_hashes' => $uniqueHashes,
    'unique_content_size_bytes' => $uniqueSize,
    'duplicate_space_bytes' => $duplicateWaste,
];

if (!$opts['json']) {
    printTable('File Storage Statistics', ['Metric', 'Value'], [
        ['Total file records', number_format($totalFiles)],
        ['Total size (all records)', formatSize($totalSize)],
        ['Unique content hashes', number_format($uniqueHashes)],
        ['Unique content size', formatSize($uniqueSize)],
        ['Duplicate space (logical)', formatSize($duplicateWaste)],
    ]);
}

// ── Disk usage ───────────────────────────────────────────────────

if ($all || $opts['disk-usage']) {
    $dataroot = $cfg['dataroot'] ?? null;
    $datarootSize = $dataroot ? getDirectorySize($dataroot) : null;
    $filedir = $dataroot ? $dataroot . '/filedir' : null;
    $filedirSize = ($filedir && is_dir($filedir)) ? getDirectorySize($filedir) : null;

    $result['disk_usage'] = [
        'dataroot_bytes' => $datarootSize,
        'filedir_bytes' => $filedirSize,
    ];

    if (!$opts['json']) {
        printTable('Disk Usage', ['Location', 'Size'], [
            ['dataroot', $datarootSize === null ? 'n/a' : formatSize($datarootSize)],
            ['filedir', $filedirSize === null ? 'n/a' : formatSize($filedirSize)],
        ]);
    }
}

// ── By component ─────────────────────────────────────────────────

if ($all || $opts['by-component']) {
    $records = query($pdo,
        "SELECT component, COUNT(*) AS file_count, SUM(filesize) AS total_size
           FROM {$tFiles}
          WHERE filename <> '.'
       GROUP BY component
       ORDER BY total_size DESC");
    $result['by_component'] = $records;
    if (!$opts['json']) {
        $rows = array_map(fn($r) => [$r['component'], number_format((int) $r['file_count']), formatSize((int) $r['total_size'])], $records);
        printTable('By Component', ['component', 'files', 'total_size'], $rows);
    }
}

// ── By file area ─────────────────────────────────────────────────

if ($all || $opts['by-filearea']) {
    $records = query($pdo,
        "SELECT filearea, COUNT(*) AS file_count, SUM(filesize) AS total_size
           FROM {$tFiles}
          WHERE filename <> '.'
       GROUP BY filearea
       ORDER BY total_size DESC");
    $result['by_filearea'] = $records;
    if (!$opts['json']) {
        $rows = array_map(fn($r) => [$r['filearea'], number_format((int) $r['file_count']), formatSize((int) $r['total_size'])], $records);
        printTable('By File Area', ['filearea', 'files', 'total_size'], $rows);
    }
}

// ── By file area + component ─────────────────────────────────────

if ($all || $opts['by-area-component']) {
    $limit = (int) BREAKDOWN_LIMIT;
    $records = query($pdo,
        "SELECT filearea, component, SUM(filesize) AS total_size, COUNT(*) AS file_count
           FROM (
                 SELECT DISTINCT contenthash, component, filearea, filesize
                   FROM {$tFiles}
                  WHERE filename <> '.' AND filesize > 0
                ) files
       GROUP BY filearea, component
       ORDER BY total_size DESC
          LIMIT $limit");
    $result['by_area_component'] = $records;
    if (!$opts['json']) {
        $rows = array_map(fn($r) => [$r['filearea'], $r['component'], number_format((int) $r['file_count']), formatSize((int) $r['total_size'])], $records);
        printTable('By File Area and Component', ['filearea', 'component', 'unique_files', 'size'], $rows);
    }
}

// ── By course ────────────────────────────────────────────────────

if ($all || $opts['by-course']) {
    $courses = getCourseUsage($pdo, $tFiles, $tContext, $tCourse);
    $result['by_course'] = $courses;
    if (!$opts['json']) {
        $rows = array_map(fn($c) => [
            $c['id'],
            truncate((string) $c['name'], 40),
            formatSize($c['all']),
            formatSize($c['distinct']),
            formatSize($c['unique']),
        ], $courses);
        printTable('Top ' . COURSE_LIMIT . ' Courses by Storage',
            ['id', 'course', 'all', 'distinct', 'unique (freed if deleted)'], $rows);
    }
}

// ── Backups by user ──────────────────────────────────────────────

if ($all || $opts['backups']) {
    $records = query($pdo,
        "SELECT u.id AS userid, u.username AS username, COUNT(*) AS file_count, SUM(f.filesize) AS total_size
           FROM {$tFiles} f
      LEFT JOIN {$tUser} u ON u.id = f.userid
          WHERE (f.filearea = 'backup' OR f.component = 'backup') AND f.filename <> '.'
       GROUP BY u.id, u.username
       ORDER BY total_size DESC");
    $result['backups'] = $records;
    if (!$opts['json']) {
        $rows = array_map(fn($r) => [
            $r['userid'] ?? '-',
            $r['username'] ?? '(unknown)',
            number_format((int) $r['file_count']),
            formatSize((int) $r['total_size']),
        ], $records);
        printTable('Backup Storage by User', ['userid', 'username', 'files', 'total_size'], $rows);
    }
}

// ── Top N largest files ──────────────────────────────────────────

if ($topN > 0) {
    $records = query($pdo,
        "SELECT id, filename, component, filearea, filesize
           FROM {$tFiles}
          WHERE filename <> '.'
       ORDER BY filesize DESC
          LIMIT $topN");
    $result['top_files'] = $records;
    if (!$opts['json']) {
        $rows = array_map(fn($f) => [$f['id'], $f['filename'], $f['component'], $f['filearea'], formatSize((int) $f['filesize'])], $records);
        printTable("Top $topN Largest Files", ['id', 'filename', 'component', 'filearea', 'size'], $rows);
    }
}

// ── JSON output ──────────────────────────────────────────────────

if ($opts['json']) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
