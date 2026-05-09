<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Make;

use InvalidArgumentException;

/**
 * Maps a Moodle plugin type to its directory under the Moodle root.
 *
 * Mirrors the canonical `plugintypes` map shipped with Moodle in
 * lib/components.json. Paths are relative to the Moodle root and include
 * the Moodle 5.x "public/" prefix used for the web-accessible code.
 */
final class PluginTypePaths
{
    /**
     * @return array<string, string> Map of plugin type → relative path
     */
    public static function map(): array
    {
        return [
            'aiplacement' => 'public/ai/placement',
            'aiprovider' => 'public/ai/provider',
            'antivirus' => 'public/lib/antivirus',
            'auth' => 'public/auth',
            'availability' => 'public/availability/condition',
            'block' => 'public/blocks',
            'cachelock' => 'public/cache/locks',
            'cachestore' => 'public/cache/stores',
            'calendartype' => 'public/calendar/type',
            'communication' => 'public/communication/provider',
            'contenttype' => 'public/contentbank/contenttype',
            'coursereport' => 'public/course/report',
            'customfield' => 'public/customfield/field',
            'dataformat' => 'public/dataformat',
            'editor' => 'public/lib/editor',
            'enrol' => 'public/enrol',
            'fileconverter' => 'public/files/converter',
            'filter' => 'public/filter',
            'format' => 'public/course/format',
            'gradeexport' => 'public/grade/export',
            'gradeimport' => 'public/grade/import',
            'gradepenalty' => 'public/grade/penalty',
            'gradereport' => 'public/grade/report',
            'gradingform' => 'public/grade/grading/form',
            'h5plib' => 'public/h5p/h5plib',
            'local' => 'public/local',
            'media' => 'public/media/player',
            'message' => 'public/message/output',
            'mlbackend' => 'public/lib/mlbackend',
            'mod' => 'public/mod',
            'paygw' => 'public/payment/gateway',
            'plagiarism' => 'public/plagiarism',
            'portfolio' => 'public/portfolio',
            'profilefield' => 'public/user/profile/field',
            'qbank' => 'public/question/bank',
            'qbehaviour' => 'public/question/behaviour',
            'qformat' => 'public/question/format',
            'qtype' => 'public/question/type',
            'report' => 'public/report',
            'repository' => 'public/repository',
            'search' => 'public/search/engine',
            'smsgateway' => 'public/sms/gateway',
            'theme' => 'public/theme',
            'tool' => 'public/admin/tool',
            'webservice' => 'public/webservice',
        ];
    }

    /**
     * Compute the destination directory for a plugin's source files.
     *
     * @throws InvalidArgumentException if the component name is malformed or
     *         names a plugin type that is not in the canonical Moodle list.
     */
    public static function pathFor(string $component, string $moodleRoot): string
    {
        [$type, $shortname] = self::splitComponent($component);

        $map = self::map();
        if (!isset($map[$type])) {
            throw new InvalidArgumentException(
                "Unknown plugin type '$type' for component '$component'. "
                . "Add support to " . self::class . "::map() if this is a valid Moodle plugin type."
            );
        }

        return rtrim($moodleRoot, '/') . '/' . $map[$type] . '/' . $shortname;
    }

    /**
     * Split a frankenstyle component name into [type, shortname].
     *
     * @return array{0: string, 1: string}
     * @throws InvalidArgumentException if the component name is malformed.
     */
    public static function splitComponent(string $component): array
    {
        $pos = strpos($component, '_');
        if ($pos === false || $pos === 0 || $pos === strlen($component) - 1) {
            throw new InvalidArgumentException(
                "Invalid component name '$component' (expected the form type_shortname, e.g. mod_attendance)."
            );
        }
        return [substr($component, 0, $pos), substr($component, $pos + 1)];
    }
}
