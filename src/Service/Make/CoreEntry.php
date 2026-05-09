<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

/**
 * The [core] entry from a moosh make manifest.
 */
final readonly class CoreEntry
{
    public function __construct(
        public string $version,
        public string $git,
        public string $branch,
    ) {
    }
}
