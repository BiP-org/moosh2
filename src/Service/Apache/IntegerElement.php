<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Apache;

final class IntegerElement implements ElementParser
{
    public function parse(string $value): ?int
    {
        if ($value === '' || $value === '-') {
            return null;
        }
        return (int) $value;
    }
}
