<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Nginx;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Translates an Nginx `log_format` directive into a regular expression and
 * parses individual log lines against it.
 *
 * Supported variable syntax:
 *   $name      — bare variable; name is read until a non-[A-Za-z0-9_] character
 *   ${name}    — braced form for disambiguating from adjacent literal text
 *
 * Variable lookup precedence:
 *   1. Explicit catalog (well-known Nginx variables — see variableCatalog())
 *   2. Header-prefix fallback ($http_*, $sent_http_*, $upstream_http_*) → string with spaces
 *   3. Cookie / argument fallback ($cookie_*, $arg_*) → non-whitespace string
 *   4. Anything else → non-whitespace string (treated as opaque)
 */
final class LineParser
{
    /** @var list<array{name: string, parser: ElementParser, re: string}> */
    private array $elements = [];

    private string $re;

    public function __construct(string $logFormat)
    {
        if ($logFormat === '') {
            throw new InvalidArgumentException('log_format string is required');
        }

        $catalog = self::variableCatalog();

        $regex = '';
        $pos = 0;
        $len = strlen($logFormat);
        $literalStart = 0;

        while ($pos < $len) {
            if ($logFormat[$pos] !== '$') {
                $pos++;
                continue;
            }

            // Flush literal text accumulated up to this $.
            if ($pos > $literalStart) {
                $regex .= preg_quote(substr($logFormat, $literalStart, $pos - $literalStart), '/');
            }

            // Parse variable name.
            if ($pos + 1 < $len && $logFormat[$pos + 1] === '{') {
                $closeBrace = strpos($logFormat, '}', $pos + 2);
                if ($closeBrace === false) {
                    throw new InvalidArgumentException("Unclosed \${...} in log_format at offset $pos");
                }
                $varName = substr($logFormat, $pos + 2, $closeBrace - $pos - 2);
                $newPos = $closeBrace + 1;
            } else {
                $end = $pos + 1;
                while ($end < $len && (ctype_alnum($logFormat[$end]) || $logFormat[$end] === '_')) {
                    $end++;
                }
                $varName = substr($logFormat, $pos + 1, $end - $pos - 1);
                $newPos = $end;
            }

            if ($varName === '') {
                // Bare $ with no following name — treat as a literal dollar sign.
                $regex .= '\$';
                $pos++;
                $literalStart = $pos;
                continue;
            }

            $def = self::resolveVariable($varName, $catalog);
            $this->elements[] = ['name' => $varName, 'parser' => $def['parser'], 're' => $def['re']];
            $regex .= $def['re'];
            $pos = $newPos;
            $literalStart = $pos;
        }

        if ($literalStart < $len) {
            $regex .= preg_quote(substr($logFormat, $literalStart), '/');
        }

        if ($this->elements === []) {
            throw new InvalidArgumentException('log_format contains no $variables');
        }

        $this->re = $regex;
    }

    /**
     * Parse one log line. Returns parsed fields keyed by variable name (without
     * the leading $), or null if the line does not match the configured format.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $line): ?array
    {
        $matches = [];
        if (!preg_match('/' . $this->re . '/', $line, $matches)) {
            return null;
        }

        $result = [];
        $i = 1;
        foreach ($this->elements as $element) {
            $result[$element['name']] = $element['parser']->parse($matches[$i++]);
        }
        return $result;
    }

    /**
     * Returns the regular expression used to parse each line, including delimiters.
     */
    public function getRE(): string
    {
        return '/' . $this->re . '/';
    }

    /**
     * Resolve a variable name to its parser and capture-group regex.
     *
     * @param array<string, array{parser: ElementParser, re: string}> $catalog
     * @return array{parser: ElementParser, re: string}
     */
    private static function resolveVariable(string $name, array $catalog): array
    {
        if (isset($catalog[$name])) {
            return $catalog[$name];
        }

        // Header-style variables: values often contain spaces, so allow .* with
        // surrounding literals (typically quotes) to anchor the match.
        if (str_starts_with($name, 'http_')
            || str_starts_with($name, 'sent_http_')
            || str_starts_with($name, 'upstream_http_')) {
            return ['parser' => new StringElement(), 're' => '(.*)'];
        }

        // Cookie / query-arg / generic fallbacks: assume no whitespace.
        return ['parser' => new StringElement(), 're' => '(\S*)'];
    }

    /**
     * Catalog of well-known Nginx variables, indexed by name (no `$` prefix).
     *
     * @return array<string, array{parser: ElementParser, re: string}>
     */
    private static function variableCatalog(): array
    {
        $str = static fn(string $re = '(\S+)'): array
            => ['parser' => new StringElement(), 're' => $re];
        $int = static fn(string $re = '(\d+)'): array
            => ['parser' => new IntegerElement(), 're' => $re];
        $float = static fn(string $re = '(\d+\.\d+)'): array
            => ['parser' => new FloatElement(), 're' => $re];

        return [
            // Time. Nginx itself writes "10/Oct/2000:13:55:36 +0000" without
            // brackets — the conventional "[...]" wrapping comes from literal
            // characters in the user's log_format string, which already anchor
            // the capture, so the regex must NOT consume the brackets.
            'time_local' => [
                'parser' => new TimeElement('d/M/Y:H:i:s O'),
                're' => '([^\]]+)',
            ],
            'time_iso8601' => [
                'parser' => new TimeElement(DateTimeInterface::ATOM),
                're' => '(\S+)',
            ],
            'msec' => $float(),

            // Client
            'remote_addr' => $str(),
            'remote_port' => $int(),
            'remote_user' => $str(),
            'binary_remote_addr' => $str(),
            'realip_remote_addr' => $str(),

            // Request
            'request' => $str('(.+)'),
            'request_method' => $str('([A-Z]+)'),
            'request_uri' => $str(),
            'uri' => $str(),
            'document_uri' => $str(),
            'request_length' => $int(),
            'request_time' => $float(),
            'request_id' => $str(),
            'request_completion' => $str('(OK|)'),
            'args' => $str('(\S*)'),
            'query_string' => $str('(\S*)'),
            'is_args' => $str('(\?|)'),

            // Response
            'status' => $int(),
            'body_bytes_sent' => $int(),
            'bytes_sent' => $int(),

            // Server
            'server_name' => $str(),
            'server_addr' => $str(),
            'server_port' => $int(),
            'server_protocol' => $str(),
            'host' => $str(),
            'hostname' => $str(),
            'scheme' => $str(),

            // Connection
            'connection' => $int(),
            'connection_requests' => $int(),
            'pid' => $int(),

            // Upstream — values can be a single token or comma/colon-separated
            // lists when a request hits multiple upstreams or cache layers, so
            // keep them as opaque strings here.
            'upstream_addr' => $str(),
            'upstream_status' => $str(),
            'upstream_response_time' => $str(),
            'upstream_connect_time' => $str(),
            'upstream_header_time' => $str(),
            'upstream_cache_status' => $str(),
            'upstream_bytes_received' => $str(),
            'upstream_bytes_sent' => $str(),

            // TLS / misc
            'gzip_ratio' => $str(),
            'https' => $str('(on|)'),
            'ssl_protocol' => $str(),
            'ssl_cipher' => $str(),
            'ssl_session_id' => $str(),
            'ssl_session_reused' => $str('(r|\.)'),
        ];
    }
}
