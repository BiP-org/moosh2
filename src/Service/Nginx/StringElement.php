<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

final class StringElement implements ElementParser
{
    public function parse(string $value): ?string
    {
        return $value === '-' ? null : $value;
    }
}
