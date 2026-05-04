<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Archive;

/**
 * Shared helpers for archive:dump and archive:restore.
 *
 * Determines what counts as the "codebase" (depending on whether the
 * Moodle install is the modern public/-split layout or the legacy
 * single-directory layout) and lists the dataroot subdirectories
 * that should be excluded from a files archive.
 */
trait ArchivePathsTrait
{
    /**
     * Subdirectories of dataroot that contain transient state and should
     * not be archived. Matches the directories Moodle creates and treats
     * as caches/temp/sessions — restoring them is pointless and they bloat
     * the archive.
     */
    private const DATAROOT_EXCLUDES = [
        'cache',
        'localcache',
        'temp',
        'trashdir',
        'sessions',
        'muc',
    ];

    /**
     * Code paths excluded by default from the codebase archive.
     */
    private const CODE_DEFAULT_EXCLUDES = [
        '.git',
    ];

    /**
     * Resolve the directory that should be archived as "the codebase".
     *
     * Modern Moodle (5.0+) uses a split layout where $CFG->dirroot is the
     * `public/` web directory and the project root (containing composer.json,
     * vendor/, config.php) is its parent. For those installs we archive the
     * parent so the result is a self-contained Moodle install.
     *
     * Legacy installs put everything in a single directory — archive that.
     */
    private function resolveCodeSourceDir(): string
    {
        global $CFG;

        $dirroot = rtrim($CFG->dirroot, '/');
        $parent = dirname($dirroot);

        if (basename($dirroot) === 'public' && file_exists($parent . '/composer.json')) {
            return $parent;
        }

        return $dirroot;
    }

    /**
     * Return the dataroot directory.
     */
    private function resolveDataSourceDir(): string
    {
        global $CFG;

        return rtrim($CFG->dataroot, '/');
    }
}
