<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

/**
 * A parsed moosh make manifest: one Moodle core entry plus zero or more
 * plugin entries.
 */
final readonly class Manifest
{
    public const int CURRENT_API = 1;

    /**
     * @param list<PluginEntry> $plugins
     */
    public function __construct(
        public int $api,
        public CoreEntry $core,
        public array $plugins,
    ) {
    }
}
