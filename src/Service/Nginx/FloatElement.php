<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

/**
 * Parser for Nginx fractional-second fields like $request_time and $msec
 * (e.g. "0.123", "12.456").
 */
final class FloatElement implements ElementParser
{
    public function parse(string $value): ?float
    {
        if ($value === '' || $value === '-') {
            return null;
        }
        return (float) $value;
    }
}
