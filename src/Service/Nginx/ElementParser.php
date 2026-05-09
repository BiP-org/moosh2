<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

/**
 * Parses a single captured field from an Nginx access-log line.
 */
interface ElementParser
{
    public function parse(string $value): mixed;
}
