<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

/**
 * Standard Nginx access-log format presets.
 *
 * Each case exposes the literal log_format string Nginx would use for that
 * preset, ready to feed into LineParser.
 */
enum Format
{
    /**
     * The default Nginx access log format.
     *
     * log_format combined '$remote_addr - $remote_user [$time_local] '
     *                     '"$request" $status $body_bytes_sent '
     *                     '"$http_referer" "$http_user_agent"';
     */
    case Combined;

    /**
     * Common extension of "combined" with the X-Forwarded-For request header,
     * frequently used behind a reverse proxy or load balancer.
     */
    case CombinedForwarded;

    /**
     * Returns the Nginx log_format string for this preset.
     */
    public function logFormat(): string
    {
        return match ($this) {
            self::Combined =>
                '$remote_addr - $remote_user [$time_local] '
                . '"$request" $status $body_bytes_sent '
                . '"$http_referer" "$http_user_agent"',
            self::CombinedForwarded =>
                '$remote_addr - $remote_user [$time_local] '
                . '"$request" $status $body_bytes_sent '
                . '"$http_referer" "$http_user_agent" '
                . '"$http_x_forwarded_for"',
        };
    }
}
