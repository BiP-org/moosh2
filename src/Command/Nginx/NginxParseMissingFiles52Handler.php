<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Nginx;

use DateTimeImmutable;
use InvalidArgumentException;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\Nginx\Format;
use Moosh2\Service\Nginx\Parser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Iterates an Nginx access log (combined format by default), picks out 404
 * responses for /file.php/... URLs (Moodle's legacy file serving endpoint)
 * and prints a "count,path" line per missing file path, in first-seen order.
 *
 * Supports custom Nginx log formats via --log-format (the literal log_format
 * string from nginx.conf, e.g. '$remote_addr [$time_local] "$request" $status').
 */
class NginxParseMissingFiles52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument(
                'logfile',
                InputArgument::REQUIRED,
                'Path to the Nginx access log',
            )
            ->addOption(
                'after',
                'a',
                InputOption::VALUE_REQUIRED,
                'Only consider entries on or after this date (anything strtotime() understands, e.g. "2024-01-01" or "1 week ago")',
            )
            ->addOption(
                'log-format',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom Nginx log_format string (the literal value from nginx.conf). Defaults to the standard "combined" format.',
            );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $logfile = $input->getArgument('logfile');
        $after = $input->getOption('after');
        $logFormat = $input->getOption('log-format');

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

        try {
            $parser = $logFormat !== null
                ? new Parser($logFormat)
                : Parser::forFormat(Format::Combined);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>Invalid --log-format: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $parser->setFile($logfile);
        $parser->setStart($start);

        $counts = [];
        foreach ($parser->entries() as $entry) {
            if (($entry['status'] ?? null) !== 404) {
                continue;
            }
            $request = $entry['request'] ?? '';
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
