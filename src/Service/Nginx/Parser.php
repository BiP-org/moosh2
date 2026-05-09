<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Streams an Nginx access-log file and yields one parsed entry at a time.
 *
 * Use {@see Parser::forFormat()} to get a parser pre-configured for one of the
 * standard presets in {@see Format}, or pass a raw Nginx `log_format` directive
 * value to the constructor for a custom format.
 *
 * Example:
 * ```
 * // Default Nginx access log:
 * $p = Parser::forFormat(Format::Combined);
 *
 * // Custom format copied from nginx.conf:
 * $p = new Parser('$remote_addr [$time_local] "$request" $status $request_time');
 *
 * $p->setFile('/var/log/nginx/access.log');
 * foreach ($p->entries() as $entry) {
 *     echo $entry['status'], "\n";
 * }
 * ```
 */
final class Parser
{
    public const int MAX_LINE_LENGTH = 8192;

    private LineParser $lineParser;

    /** @var resource|null */
    private $fhandle = null;

    private ?DateTimeImmutable $start = null;
    private ?DateTimeImmutable $stop = null;

    private int $skippedLines = 0;

    /**
     * @param string $logFormat raw Nginx `log_format` value (with $variables)
     */
    public function __construct(string $logFormat)
    {
        $this->lineParser = new LineParser($logFormat);
    }

    /**
     * Build a parser for one of the standard Nginx log presets.
     */
    public static function forFormat(Format $format): self
    {
        return new self($format->logFormat());
    }

    /**
     * Parse a single log line directly. Returns null if the line does not match.
     *
     * @return array<string, mixed>|null
     */
    public function parseLine(string $line): ?array
    {
        return $this->lineParser->parse($line);
    }

    /**
     * Returns the regular expression used to parse each line, including delimiters.
     */
    public function getRE(): string
    {
        return $this->lineParser->getRE();
    }

    public function setFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException("Can't read file: $filePath");
        }
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open file: $filePath");
        }
        $this->fhandle = $handle;
    }

    /**
     * Skip log entries earlier than this date. Pass null to disable.
     *
     * Requires the configured log_format to include a `$time_local` or
     * `$time_iso8601` variable (or the entry will not have a `time_local` /
     * `time_iso8601` key to filter on).
     */
    public function setStart(?DateTimeImmutable $start): void
    {
        $this->start = $start;
    }

    /**
     * Stop reading once an entry past this date is seen. Logs are assumed to
     * be in chronological order. Pass null to disable.
     */
    public function setStop(?DateTimeImmutable $stop): void
    {
        $this->stop = $stop;
    }

    /**
     * Read the next parseable log entry within the configured [start, stop]
     * window. Returns null when the file is exhausted or the next entry would
     * be past the configured stop time. Lines that fail to parse are skipped
     * silently; the count is available via {@see skippedLines()}.
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        if ($this->fhandle === null) {
            throw new RuntimeException('No file to parse — call setFile() first');
        }

        while (($line = fgets($this->fhandle, self::MAX_LINE_LENGTH)) !== false) {
            $parsed = $this->lineParser->parse($line);
            if ($parsed === null) {
                $this->skippedLines++;
                continue;
            }
            $time = $this->extractTime($parsed);
            if ($this->stop !== null && $time !== null && $time > $this->stop) {
                return null;
            }
            if ($this->start !== null && $time !== null && $time < $this->start) {
                continue;
            }
            return $parsed;
        }
        return null;
    }

    /**
     * Iterate parseable log entries until the file ends or the stop time is reached.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function entries(): Generator
    {
        while (($entry = $this->next()) !== null) {
            yield $entry;
        }
    }

    /**
     * Number of lines skipped because they did not match the configured format.
     */
    public function skippedLines(): int
    {
        return $this->skippedLines;
    }

    public function __destruct()
    {
        if (is_resource($this->fhandle)) {
            fclose($this->fhandle);
        }
    }

    /**
     * Pull the timestamp out of a parsed entry, preferring $time_local over
     * $time_iso8601. Returns null when the configured format has no time field.
     *
     * @param array<string, mixed> $entry
     */
    private function extractTime(array $entry): ?DateTimeImmutable
    {
        foreach (['time_local', 'time_iso8601'] as $key) {
            if (isset($entry[$key]) && $entry[$key] instanceof DateTimeImmutable) {
                return $entry[$key];
            }
        }
        return null;
    }
}
