<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

/**
 * One plugin entry parsed from a moosh make manifest.
 *
 * Source resolution priority (first non-null wins):
 *   1. $git          — git clone URL (uses $branch if set, else default branch)
 *   2. $zip          — direct zip download URL
 *   3. (default)     — fetch from the moodle.org plugin directory by component
 */
final readonly class PluginEntry
{
    public function __construct(
        public string $component,
        public ?string $version = null,
        public ?string $git = null,
        public ?string $branch = null,
        public ?string $zip = null,
    ) {
    }

    /**
     * Indicates which source the plugin will be fetched from.
     *
     * @return 'git'|'zip'|'directory'
     */
    public function source(): string
    {
        if ($this->git !== null) {
            return 'git';
        }
        if ($this->zip !== null) {
            return 'zip';
        }
        return 'directory';
    }
}
