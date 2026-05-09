<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Apache;

use DateTimeImmutable;

final readonly class TimeElement implements ElementParser
{
    public function __construct(private string $format)
    {
    }

    public function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value, '[]');
        $time = DateTimeImmutable::createFromFormat($this->format, $value);
        return $time !== false ? $time : null;
    }
}
