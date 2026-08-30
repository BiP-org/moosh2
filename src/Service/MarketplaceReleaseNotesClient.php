<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

/**
 * Fetches release notes for a specific plugin version from Moodle
 * Marketplace (https://marketplace.moodle.com).
 *
 * There is currently no documented, public *read* API for Moodle
 * Marketplace (see the "Add a new plugin version" write-only API at
 * https://moodledev.io/general/community/plugincontribution/moodlemarketplaceapi) -
 * a plugin's version history and release notes are only exposed by the
 * marketplace.moodle.com website itself. This client scrapes that page.
 *
 * Because there is no stable, documented markup contract to depend on,
 * this deliberately does NOT key off CSS classes or element IDs (those
 * are the most likely things to change across a site redesign). Instead
 * it flattens the page's block-level elements (headings, paragraphs,
 * list items, definition terms/values) into plain text lines - similar
 * to how a Markdown renderer would - and locates version boundaries by
 * matching the *visible label text* Moodle Marketplace shows a human on
 * that page (e.g. "Version build number:", a heading of the form
 * "{release name} ({build number})"). That visible text is far less
 * likely to change than any particular div/class structure, and if it
 * does change, this fails loudly (MarketplaceScrapeException) rather
 * than silently returning wrong data.
 */
final class MarketplaceReleaseNotesClient
{
    private const BASE_URL = 'https://marketplace.moodle.com';

    private ?string $proxy;
    private ?string $token;

    public function __construct(?string $proxy = null, ?string $token = null)
    {
        $this->proxy = $proxy;
        $this->token = $token;
    }

    /**
     * Fetch and return the release notes for one specific plugin version.
     *
     * @param string $component Frankenstyle plugin name (e.g. atto_wiris)
     * @param string $buildNumber Exact version build number (e.g. 2025041400)
     *
     * @throws HttpRequestException on network/HTTP failure
     * @throws MarketplaceScrapeException if the page can't be parsed, or
     *   the plugin/version isn't found in it
     */
    public function getReleaseNotes(string $component, string $buildNumber): MarketplaceReleaseNotes
    {
        $url = self::BASE_URL . '/plugins/' . rawurlencode($component) . '/versions?show=all';
        $html = $this->fetch($url);

        $lines = self::htmlToLines($html);
        $blocks = self::splitIntoVersionBlocks($lines);

        if ($blocks === []) {
            throw new MarketplaceScrapeException(
                "Could not find any version listing on $url. "
                . 'The plugin may not exist on Moodle Marketplace, or the page layout has changed '
                . 'and this scraper needs updating.',
            );
        }

        foreach ($blocks as $block) {
            if ($block->buildNumber === $buildNumber) {
                return $block;
            }
        }

        $known = implode(', ', array_map(
            static fn (MarketplaceReleaseNotes $b): string => "{$b->releaseName} ({$b->buildNumber})",
            $blocks,
        ));

        throw new MarketplaceScrapeException(
            "Version $buildNumber of '$component' was not found on $url.\n"
            . "Versions found on that page: $known\n"
            . 'Note: the marketplace page only lists versions it chooses to show; '
            . 'very old versions may not be listed even with ?show=all.',
        );
    }

    /**
     * @return list<MarketplaceReleaseNotes>
     */
    public function getAllReleaseNotes(string $component): array
    {
        $url = self::BASE_URL . '/plugins/' . rawurlencode($component) . '/versions?show=all';
        $html = $this->fetch($url);
        $lines = self::htmlToLines($html);
        $blocks = self::splitIntoVersionBlocks($lines);

        if ($blocks === []) {
            throw new MarketplaceScrapeException(
                "Could not find any version listing on $url. "
                . 'The plugin may not exist on Moodle Marketplace, or the page layout has changed '
                . 'and this scraper needs updating.',
            );
        }

        return $blocks;
    }

    private function fetch(string $url): string
    {
        $content = @file_get_contents($url, false, $this->createStreamContext());
        if ($content === false) {
            throw self::httpFailure("Failed to fetch $url", $http_response_header ?? null);
        }

        return $content;
    }

    /**
     * @return resource
     */
    private function createStreamContext()
    {
        // Same rationale as PluginApiClient: moodle.org properties have
        // previously blocked obviously-automated User-Agent strings, so
        // this deliberately looks like a generic HTTP client rather than
        // identifying as "moosh2".
        $header = "User-Agent: curl/7.81.0\r\n"
            . "Accept: text/html\r\n"
            . "Connection: close\r\n";

        if ($this->token !== null && $this->token !== '') {
            $header .= 'Authorization: Bearer ' . $this->token . "\r\n";
        }

        $httpConfig = [
            'method' => 'GET',
            'header' => $header,
            'request_fulluri' => true,
            'ignore_errors' => true, // so we can read body + status on 4xx/5xx too
        ];

        $proxyUrl = $this->proxy
            ?? (getenv('http_proxy') ?: (getenv('HTTP_PROXY') ?: null));

        if ($proxyUrl) {
            $uriParts = parse_url($proxyUrl);
            $httpConfig['proxy'] = sprintf(
                '%s://%s%s',
                $uriParts['scheme'] ?? 'tcp',
                $uriParts['host'],
                empty($uriParts['port']) ? '' : ':' . $uriParts['port'],
            );

            if (!empty($uriParts['user']) && !empty($uriParts['pass'])) {
                $authEncoded = base64_encode($uriParts['user'] . ':' . $uriParts['pass']);
                $httpConfig['header'] .= 'Proxy-Authorization: Basic ' . $authEncoded . "\r\n";
            }
        }

        return stream_context_create(['http' => $httpConfig]);
    }

    /**
     * Flatten an HTML document's block-level elements (headings,
     * paragraphs, list items, definition terms/values) into an ordered
     * list of plain-text lines, roughly mirroring how a Markdown render
     * of the page would read. Headings are prefixed with '#' * level so
     * downstream parsing can still tell a heading from body text.
     *
     * @return list<string>
     */
    private static function htmlToLines(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // Marketplace pages are UTF-8; DOMDocument's HTML parser guesses
        // encoding from the document itself but a mb_convert_encoding
        // round-trip avoids mojibake on documents without a <meta charset>.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        // Remove script/style content so it never leaks into text lines.
        foreach ($xpath->query('//script | //style') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //p | //li | //dt | //dd');

        $lines = [];
        foreach ($nodes as $node) {
            $text = self::normalizeWhitespace($node->textContent);
            if ($text === '') {
                continue;
            }

            $tag = strtolower($node->nodeName);
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $lines[] = str_repeat('#', (int) $m[1]) . ' ' . $text;
            } elseif ($tag === 'li') {
                $lines[] = '- ' . $text;
            } else {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Split the flattened lines into one block per plugin version.
     *
     * A version block starts at a heading line of the form
     * "{release name} ({10-digit build number})" - the format Moodle
     * Marketplace uses for its version headings - and runs up to (but
     * not including) the next such heading, or a known trailing marker
     * ("Show previous versions", the page footer) if that comes first.
     *
     * @param list<string> $lines
     * @return list<MarketplaceReleaseNotes>
     */
    private static function splitIntoVersionBlocks(array $lines): array
    {
        $headingPattern = '/^#{1,6}\s+(.+?)\s*\((\d{6,14})\)\s*$/';
        $endMarkerPattern = '/^(Show previous versions|Share your plugin with the Moodle community)/i';

        $starts = [];
        foreach ($lines as $i => $line) {
            if (preg_match($headingPattern, $line, $m)) {
                $starts[] = ['index' => $i, 'releaseName' => trim($m[1]), 'buildNumber' => $m[2]];
            }
        }

        $blocks = [];
        $count = count($starts);
        foreach ($starts as $n => $start) {
            $blockStart = $start['index'] + 1;
            $blockEnd = $n + 1 < $count ? $starts[$n + 1]['index'] : count($lines);

            for ($i = $blockStart; $i < $blockEnd; $i++) {
                if (preg_match($endMarkerPattern, $lines[$i])) {
                    $blockEnd = $i;
                    break;
                }
            }

            $blockLines = array_slice($lines, $blockStart, $blockEnd - $blockStart);

            $blocks[] = new MarketplaceReleaseNotes(
                releaseName: $start['releaseName'],
                buildNumber: $start['buildNumber'],
                maturity: self::extractField($blockLines, 'Maturity'),
                supportedMoodle: self::extractField($blockLines, 'Supported Moodle versions'),
                notes: self::extractNotes($blockLines),
            );
        }

        return $blocks;
    }

    /**
     * @param list<string> $blockLines
     */
    private static function extractField(array $blockLines, string $label): ?string
    {
        foreach ($blockLines as $line) {
            if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/i', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * The release notes proper: everything from the "Changelog" heading
     * (if present) to the end of the block, otherwise the whole block
     * minus the "Version information" field lines (label: value pairs),
     * so metadata isn't duplicated into the notes text.
     *
     * @param list<string> $blockLines
     */
    private static function extractNotes(array $blockLines): string
    {
        $changelogIndex = null;
        foreach ($blockLines as $i => $line) {
            if (preg_match('/^#{1,6}\s*(Changelog|Release notes)\s*$/i', $line)) {
                $changelogIndex = $i;
                break;
            }
        }

        if ($changelogIndex !== null) {
            $notesLines = array_slice($blockLines, $changelogIndex + 1);
        } else {
            // No explicit "Changelog" heading found - fall back to
            // everything that isn't a recognised "Label: value" metadata
            // line.
            $notesLines = array_filter(
                $blockLines,
                static fn (string $line): bool => !preg_match('/^[A-Za-z][A-Za-z0-9 \/()]{2,40}:\s*.+$/', $line),
            );
        }

        return trim(implode("\n", $notesLines));
    }

    /**
     * @param string[]|null $responseHeaders
     */
    private static function httpFailure(string $message, ?array $responseHeaders): HttpRequestException
    {
        [$statusCode, $statusText] = self::parseHttpStatus($responseHeaders);

        if ($statusCode === null) {
            return new HttpRequestException(
                "$message (no HTTP response received - network/DNS/TLS failure, or the request never completed)",
            );
        }

        $suffix = $statusText !== null && $statusText !== ''
            ? "HTTP $statusCode $statusText"
            : "HTTP $statusCode";

        return new HttpRequestException("$message ($suffix)", $statusCode, $statusText);
    }

    /**
     * @param string[]|null $responseHeaders
     * @return array{0: int|null, 1: string|null}
     */
    private static function parseHttpStatus(?array $responseHeaders): array
    {
        if (empty($responseHeaders)) {
            return [null, null];
        }

        $statusLine = null;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$#i', $line)) {
                $statusLine = $line;
            }
        }

        if ($statusLine === null || !preg_match('#^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$#i', $statusLine, $matches)) {
            return [null, null];
        }

        return [(int) $matches[1], isset($matches[2]) ? trim($matches[2]) : null];
    }
}
