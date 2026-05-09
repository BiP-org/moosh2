<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Apache;

/**
 * Standard Apache access-log format presets.
 *
 * Each case exposes a printf-style template and the ordered list of element
 * names that fill it, ready to feed into LineParser.
 */
enum Format
{
    /** "%h %l %u %t \"%r\" %>s %b" */
    case Common;

    /** "%v %h %l %u %t \"%r\" %>s %b" */
    case CommonVhost;

    /** "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" */
    case Combined;

    /** "%v:%p %h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" */
    case VhostCombined;

    /**
     * @return array{0: string, 1: list<string|array{element: string, name: string}>}
     */
    public function template(): array
    {
        return match ($this) {
            self::Common => [
                '%s %s %s %s "%s" %s %s',
                [
                    'remote_host', 'remote_logname', 'remote_user', 'time',
                    'request_first_line', 'status', 'response_size_clf',
                ],
            ],
            self::CommonVhost => [
                '%s %s %s %s %s "%s" %s %s',
                [
                    'server_name', 'remote_host', 'remote_logname', 'remote_user', 'time',
                    'request_first_line', 'status', 'response_size_clf',
                ],
            ],
            self::Combined => [
                '%s %s %s %s "%s" %s %s "%s" "%s"',
                [
                    'remote_host', 'remote_logname', 'remote_user', 'time',
                    'request_first_line', 'status', 'response_size_clf',
                    ['element' => 'request_header_line', 'name' => 'referer'],
                    ['element' => 'request_header_line', 'name' => 'user_agent'],
                ],
            ],
            self::VhostCombined => [
                '%s:%s %s %s %s %s "%s" %s %s "%s" "%s"',
                [
                    'server_name', 'port', 'remote_host', 'remote_logname', 'remote_user', 'time',
                    'request_first_line', 'status', 'response_size_clf',
                    ['element' => 'request_header_line', 'name' => 'referer'],
                    ['element' => 'request_header_line', 'name' => 'user_agent'],
                ],
            ],
        };
    }
}
