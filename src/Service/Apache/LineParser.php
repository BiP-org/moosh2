<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Apache;

use InvalidArgumentException;

/**
 * Builds a regex from a printf-style log-format template plus an ordered list
 * of Apache log elements, then parses individual log lines against it.
 */
final class LineParser
{
    /** @var list<array{name: string, format: string, parser: ElementParser, re: string}> */
    private array $elements;

    private string $re;

    /**
     * @param string $template printf-style template with one %s per element (e.g. '%s %s "%s"')
     * @param list<string|array{element: string, name?: string}> $params element names, or
     *     arrays of the form ['element' => 'request_header_line', 'name' => 'referer']
     *     when the same element appears multiple times under different names.
     */
    public function __construct(string $template, array $params)
    {
        if ($template === '' || $params === []) {
            throw new InvalidArgumentException('Template and element list are required');
        }

        $catalog = self::elementCatalog();
        $this->elements = [];
        foreach ($params as $param) {
            if (is_array($param)) {
                if (!isset($param['element']) || !isset($catalog[$param['element']])) {
                    throw new InvalidArgumentException('Unknown element or element not set');
                }
                $this->elements[] = array_merge(
                    ['name' => $param['element']],
                    $catalog[$param['element']],
                    $param,
                );
            } else {
                if (!isset($catalog[$param])) {
                    throw new InvalidArgumentException("Unknown element: '$param'");
                }
                $this->elements[] = ['name' => $param] + $catalog[$param];
            }
        }

        $reParams = array_map(static fn(array $e): string => $e['re'], $this->elements);
        $this->re = vsprintf($template, $reParams);
    }

    /**
     * Parse one log line. Returns parsed fields keyed by element name, or null
     * if the line does not match the configured format.
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
     * Catalog of known Apache log-format elements, indexed by canonical name.
     *
     * @return array<string, array{format: string, parser: ElementParser, re: string}>
     */
    private static function elementCatalog(): array
    {
        return [
            'remote_ip' => [
                'format' => 'a', 'parser' => new StringElement(),
                're' => '(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})'],
            'local_ip' => [
                'format' => 'A', 'parser' => new StringElement(),
                're' => '(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})'],
            'response_size' => [
                'format' => 'B', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'response_size_clf' => [
                'format' => 'b', 'parser' => new IntegerElement(),
                're' => '(\d+|-)'],
            'cookie' => [
                'format' => 'C', 'parser' => new StringElement(),
                're' => '(.*)'],
            'serving_time_microseconds' => [
                'format' => 'D', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'env_variable' => [
                'format' => 'e', 'parser' => new StringElement(),
                're' => '(.*)'],
            'filename' => [
                'format' => 'f', 'parser' => new StringElement(),
                're' => '(.*)'],
            'remote_host' => [
                'format' => 'h', 'parser' => new StringElement(),
                're' => '(.*)'],
            'request_protocol' => [
                'format' => 'H', 'parser' => new StringElement(),
                're' => '(.+)'],
            'request_header_line' => [
                'format' => 'i', 'parser' => new StringElement(),
                're' => '(.+)'],
            'remote_logname' => [
                'format' => 'l', 'parser' => new StringElement(),
                're' => '([^ ]+)'],
            'request_method' => [
                'format' => 'm', 'parser' => new StringElement(),
                're' => '(OPTIONS|GET|HEAD|POST|PUT|DELETE|TRACE|CONNECT|PATCH)'],
            'note' => [
                'format' => 'n', 'parser' => new StringElement(),
                're' => '(.*)'],
            'reply_header_line' => [
                'format' => 'o', 'parser' => new StringElement(),
                're' => '(.*)'],
            'port' => [
                'format' => 'p', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'pid' => [
                'format' => 'P', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'query' => [
                'format' => 'q', 'parser' => new StringElement(),
                're' => '(|\?.*)'],
            'request_first_line' => [
                'format' => 'r', 'parser' => new StringElement(),
                're' => '(.+)'],
            'status' => [
                'format' => 's', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'time' => [
                'format' => 't', 'parser' => new TimeElement('d/M/Y:H:i:s O'),
                're' => '(\[.+\])'],
            'serving_time' => [
                'format' => 'T', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'remote_user' => [
                'format' => 'u', 'parser' => new StringElement(),
                're' => '([^ ]+)'],
            'url' => [
                'format' => 'U', 'parser' => new StringElement(),
                're' => '([a-zA-Z0-9\-\.\?\,\'\/\\\+&;:=@%\$#_]*)'],
            'server_name' => [
                'format' => 'v', 'parser' => new StringElement(),
                're' => '(.*)'],
            'server_name_usecanonical' => [
                'format' => 'V', 'parser' => new StringElement(),
                're' => '(.*)'],
            'connection_after_response' => [
                'format' => 'X', 'parser' => new StringElement(),
                're' => '(X|\+|-)'],
            'bytes_received' => [
                'format' => 'I', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
            'bytes_sent' => [
                'format' => 'O', 'parser' => new IntegerElement(),
                're' => '(\d+)'],
        ];
    }
}
