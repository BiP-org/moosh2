<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Apache;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Streams an Apache access-log file and yields one parsed entry at a time.
 *
 * Use {@see Parser::forFormat()} to construct a parser pre-configured for one
 * of the standard Apache log formats; use the constructor directly to pass a
 * custom format.
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
     * @param string $template printf-style template (one %s per element)
     * @param list<string|array{element: string, name?: string}> $elements
     */
    public function __construct(string $template, array $elements)
    {
        $this->lineParser = new LineParser($template, $elements);
    }

    /**
     * Build a parser configured for one of the standard Apache log formats.
     */
    public static function forFormat(Format $format): self
    {
        [$template, $elements] = $format->template();
        return new self($template, $elements);
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
            if ($this->stop !== null && $parsed['time'] > $this->stop) {
                return null;
            }
            if ($this->start !== null && $parsed['time'] < $this->start) {
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
}
