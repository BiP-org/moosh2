<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Apache;

use DateTimeImmutable;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\Apache\Format;
use Moosh2\Service\Apache\Parser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Iterates an Apache combined-format access log, picks out 404 responses for
 * /file.php/... URLs (Moodle's legacy file serving endpoint) and prints a
 * "count,path" line per missing file path, sorted by first-seen order.
 */
class ApacheParseMissingFiles52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument(
                'logfile',
                InputArgument::REQUIRED,
                'Path to the Apache combined-format access log',
            )
            ->addOption(
                'after',
                'a',
                InputOption::VALUE_REQUIRED,
                'Only consider entries on or after this date (anything strtotime() understands, e.g. "2024-01-01" or "1 week ago")',
            );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $logfile = $input->getArgument('logfile');
        $after = $input->getOption('after');

        if (!is_file($logfile) || !is_readable($logfile)) {
            $output->writeln('<error>Cannot read log file: ' . $logfile . '</error>');
            return Command::FAILURE;
        }

        $start = null;
        if ($after !== null) {
            $ts = strtotime($after);
            if ($ts === false) {
                $output->writeln('<error>Invalid date for --after: ' . $after . '</error>');
                return Command::FAILURE;
            }
            $start = (new DateTimeImmutable())->setTimestamp($ts);
        }

        $parser = Parser::forFormat(Format::Combined);
        $parser->setFile($logfile);
        $parser->setStart($start);

        $counts = [];
        foreach ($parser->entries() as $entry) {
            if ($entry['status'] !== 404) {
                continue;
            }
            $request = $entry['request_first_line'] ?? '';
            if (!preg_match('|GET /file\.php/(.*) HTTP/|', (string) $request, $m)) {
                continue;
            }
            $path = $m[1];
            $counts[$path] = ($counts[$path] ?? 0) + 1;
        }

        foreach ($counts as $path => $n) {
            $output->writeln($n . ',' . $path);
        }

        return Command::SUCCESS;
    }
}
