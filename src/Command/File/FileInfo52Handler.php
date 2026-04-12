<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\File;

use Moosh2\Command\BaseHandler;
use Moosh2\Command\StdinIdsTrait;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FileInfo52Handler extends BaseHandler
{
    use StdinIdsTrait;

    private const FIELD_MAP = [
        'id' => 'File ID',
        'contenthash' => 'Content hash',
        'pathnamehash' => 'Pathname hash',
        'path' => 'Physical path',
        'exists' => 'Exists on disk',
        'contextid' => 'Context ID',
        'component' => 'Component',
        'filearea' => 'File area',
        'itemid' => 'Item ID',
        'filepath' => 'File path',
        'filename' => 'Filename',
        'filesize' => 'File size',
        'mimetype' => 'MIME type',
        'author' => 'Author',
        'license' => 'License',
        'timecreated' => 'Created',
        'timemodified' => 'Modified',
    ];

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('fileid', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'File ID(s)')
            ->addOption('hash', null, InputOption::VALUE_REQUIRED, 'Look up by content hash')
            ->addOption('field', 'f', InputOption::VALUE_REQUIRED, 'Output a single field only (e.g. path, filename, contenthash)')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read space-separated file IDs from stdin');

        $command->addExampleUsage('Show info for a single file', '42');
        $command->addExampleUsage('Show info for multiple files', '42 43 44');
        $command->addExampleUsage('Look up by content hash', '--hash=abc123def');
        $command->addExampleUsage('Get physical path only', '42 --field path');
        $command->addExampleUsage('Pipe from file:list to get paths', 'file:list --courseid 2 -i | moosh file:info --stdin --field path');
        $command->addExampleUsage('Archive all course files', 'file:list --courseid 2 -i | moosh file:info --stdin --field path | tar czf course-files.tar.gz -T -');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');

        $argIds = $input->getArgument('fileid');
        $stdinIds = $this->readStdinIds($input);
        $hash = $input->getOption('hash');
        $field = $input->getOption('field');

        if ($field !== null && !isset(self::FIELD_MAP[$field])) {
            $valid = implode(', ', array_keys(self::FIELD_MAP));
            $output->writeln("<error>Unknown field '$field'. Valid fields: $valid</error>");
            return Command::FAILURE;
        }

        $fileIds = array_map('intval', $argIds);
        if ($stdinIds !== null) {
            $fileIds = array_merge($fileIds, $stdinIds);
        }

        if (empty($fileIds) && $hash === null) {
            $output->writeln('<error>Specify file ID(s), --hash, or --stdin.</error>');
            return Command::FAILURE;
        }

        $fs = get_file_storage();

        if ($hash !== null) {
            $files = $DB->get_records_select('files', "contenthash = ? AND filename != '.'", [$hash]);
            if (empty($files)) {
                $output->writeln("<error>No files found with hash '$hash'.</error>");
                return Command::FAILURE;
            }
        } else {
            $files = [];
            foreach ($fileIds as $id) {
                $file = $DB->get_record('files', ['id' => $id]);
                if (!$file) {
                    $output->writeln("<error>File with ID $id not found.</error>");
                    return Command::FAILURE;
                }
                $files[] = $file;
            }
        }

        // Single-field output: one value per line, raw (no table).
        if ($field !== null) {
            foreach ($files as $file) {
                $row = $this->buildFieldMap($file, $fs, $CFG);
                $output->writeln($row[$field]);
            }
            return Command::SUCCESS;
        }

        // Full table output.
        $headers = ['Metric', 'Value'];
        $allRows = [];

        foreach ($files as $file) {
            $row = $this->buildFieldMap($file, $fs, $CFG);
            foreach (self::FIELD_MAP as $key => $label) {
                $value = $row[$key];
                if ($key === 'filesize') {
                    $value = $this->formatSize((int) $value);
                }
                $allRows[] = [$label, $value];
            }

            if (count($files) > 1) {
                $allRows[] = ['---', '---'];
            }
        }

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $allRows);

        return Command::SUCCESS;
    }

    /**
     * Build a map of field name => raw value for a file record.
     */
    private function buildFieldMap(object $file, \file_storage $fs, object $CFG): array
    {
        $l1 = substr($file->contenthash, 0, 2);
        $l2 = substr($file->contenthash, 2, 2);
        $physicalPath = $CFG->dataroot . "/filedir/$l1/$l2/{$file->contenthash}";
        $exists = file_exists($physicalPath) ? 'yes' : 'NO (MISSING)';

        return [
            'id' => $file->id,
            'contenthash' => $file->contenthash,
            'pathnamehash' => $file->pathnamehash,
            'path' => $physicalPath,
            'exists' => $exists,
            'contextid' => $file->contextid,
            'component' => $file->component,
            'filearea' => $file->filearea,
            'itemid' => $file->itemid,
            'filepath' => $file->filepath,
            'filename' => $file->filename,
            'filesize' => $file->filesize,
            'mimetype' => $file->mimetype ?? '(none)',
            'author' => $file->author ?? '(none)',
            'license' => $file->license ?? '(none)',
            'timecreated' => date('Y-m-d H:i:s', $file->timecreated),
            'timemodified' => date('Y-m-d H:i:s', $file->timemodified),
        ];
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
