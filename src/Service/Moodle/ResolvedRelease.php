<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Moodle;

final class ResolvedRelease
{
    public function __construct(
        public readonly string $url,
        public readonly string $label,
    ) {
    }
}
