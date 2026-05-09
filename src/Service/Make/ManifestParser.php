<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

use InvalidArgumentException;
use RuntimeException;

/**
 * Parses a moosh make manifest (INI-formatted) into a {@see Manifest}.
 *
 * Manifest grammar (INI):
 *
 *   ; top-level keys
 *   api = 1
 *
 *   [core]
 *   version = 5.2
 *   ; optional overrides:
 *   ;   git    = https://git.in.moodle.com/moodle.git    (default)
 *   ;   branch = MOODLE_502_STABLE                       (derived from version)
 *
 *   [<frankenstyle component>]
 *   ; default: latest version compatible with core, fetched from
 *   ; the moodle.org plugin directory.
 *
 *   [<frankenstyle component>]
 *   version = <plugin version number>
 *
 *   [<frankenstyle component>]
 *   git    = <git clone URL>
 *   branch = <branch or tag>          ; defaults to HEAD
 *
 *   [<frankenstyle component>]
 *   zip    = <download URL>
 */
final class ManifestParser
{
    private const string DEFAULT_CORE_GIT = 'https://git.in.moodle.com/moodle.git';

    private const array RECOGNISED_PLUGIN_KEYS = ['version', 'git', 'branch', 'zip'];

    public function parseFile(string $path): Manifest
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read manifest file: $path");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read manifest file: $path");
        }
        return $this->parseString($contents, $path);
    }

    public function parseString(string $contents, string $sourceLabel = '<string>'): Manifest
    {
        $parsed = @parse_ini_string($contents, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new InvalidArgumentException("Failed to parse manifest INI in $sourceLabel");
        }

        $api = isset($parsed['api']) ? (int) $parsed['api'] : Manifest::CURRENT_API;
        if ($api !== Manifest::CURRENT_API) {
            throw new InvalidArgumentException(
                "Unsupported manifest api version '$api' in $sourceLabel "
                . "(this build of moosh supports api=" . Manifest::CURRENT_API . ")."
            );
        }

        if (!isset($parsed['core']) || !is_array($parsed['core'])) {
            throw new InvalidArgumentException("Manifest $sourceLabel is missing the [core] section.");
        }
        $core = $this->buildCoreEntry($parsed['core'], $sourceLabel);

        $plugins = [];
        foreach ($parsed as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            if ($section === 'core') {
                continue;
            }
            $plugins[] = $this->buildPluginEntry($section, $values, $sourceLabel);
        }

        return new Manifest($api, $core, $plugins);
    }

    /**
     * @param array<string, string> $values
     */
    private function buildCoreEntry(array $values, string $sourceLabel): CoreEntry
    {
        $version = $values['version'] ?? null;
        if ($version === null || $version === '') {
            throw new InvalidArgumentException("[core] section in $sourceLabel must specify a 'version' (e.g. 5.2).");
        }

        $git = $values['git'] ?? self::DEFAULT_CORE_GIT;
        $branch = $values['branch'] ?? self::deriveCoreBranch($version);

        return new CoreEntry(version: $version, git: $git, branch: $branch);
    }

    /**
     * Convert a Moodle major version like "5.2" or "5.2.1" into the conventional
     * stable branch name (e.g. MOODLE_502_STABLE).
     */
    private static function deriveCoreBranch(string $version): string
    {
        if (!preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            throw new InvalidArgumentException(
                "Cannot derive a default branch from core version '$version'. "
                . "Either fix the version or set 'branch' explicitly under [core]."
            );
        }
        return sprintf('MOODLE_%d%02d_STABLE', (int) $m[1], (int) $m[2]);
    }

    /**
     * @param array<string, string> $values
     */
    private function buildPluginEntry(string $component, array $values, string $sourceLabel): PluginEntry
    {
        // Validate component name shape (a frankenstyle name like mod_attendance).
        PluginTypePaths::splitComponent($component);

        foreach (array_keys($values) as $key) {
            if (!in_array($key, self::RECOGNISED_PLUGIN_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Unknown key '$key' in [$component] section of $sourceLabel. "
                    . "Recognised keys: " . implode(', ', self::RECOGNISED_PLUGIN_KEYS) . "."
                );
            }
        }

        $version = $values['version'] ?? null;
        $git = $values['git'] ?? null;
        $branch = $values['branch'] ?? null;
        $zip = $values['zip'] ?? null;

        if ($git !== null && $zip !== null) {
            throw new InvalidArgumentException(
                "[$component] in $sourceLabel sets both 'git' and 'zip' — pick one."
            );
        }

        if ($branch !== null && $git === null) {
            throw new InvalidArgumentException(
                "[$component] in $sourceLabel sets 'branch' without 'git'."
            );
        }

        if ($version !== null && ($git !== null || $zip !== null)) {
            throw new InvalidArgumentException(
                "[$component] in $sourceLabel sets 'version' alongside 'git'/'zip'. "
                . "'version' is only used when fetching from the moodle.org plugin directory."
            );
        }

        return new PluginEntry(
            component: $component,
            version: $version === '' ? null : $version,
            git: $git,
            branch: $branch,
            zip: $zip,
        );
    }
}
