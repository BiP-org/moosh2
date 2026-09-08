<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

/**
 * Client for the moodle.org plugin directory API.
 *
 * Fetches and caches the plugin list, resolves compatible versions,
 * and downloads plugin ZIP files.
 */
final class PluginApiClient
{
    private const API_URL = 'https://download.moodle.org/api/1.3/pluglist.php';
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * User-Agent strings to try, in order, for every request. Moodle core
     * itself never calls pluglist.php (see the "TODO" note in
     * \core\update\api - only pluginfo.php, one plugin at a time, is used
     * by Moodle's own "check for available updates"), so there's no
     * literal reference request to copy. But \core\update\api's own HTTP
     * calls go through Moodle core's `curl` wrapper (lib/filelib.php),
     * whose default - used for every download.moodle.org API call a real
     * Moodle site makes - is 'MoodleBot/1.0'. download.moodle.org can't
     * afford to block that string without breaking every Moodle site's
     * update-check feature, which makes it the safest first guess for
     * whatever is filtering plainer/generic-looking User-Agents (curl's
     * own default UA, empty UAs, etc.) with a 403.
     *
     * If MoodleBot/1.0 is ever rejected too, fetchWithUserAgentFallback()
     * falls through to these as a last resort - ordinary browser/tool UAs
     * that don't look like a bot at all.
     */
    private const USER_AGENTS = [
        'MoodleBot/1.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'curl/8.5.0',
        'Wget/1.21.3',
    ];

    private ?string $proxy;
    private ?string $token;

    public function __construct(?string $proxy = null, ?string $token = null)
    {
        $this->proxy = $proxy;
        $this->token = $token;
    }

    /**
     * Return the full plugin list from the API (cached locally).
     */
    public function getPluginList(bool $forceRefresh = false): object
    {
        $cachePath = self::getCachePath();
        $this->ensureCacheFresh($forceRefresh);

        $json = file_get_contents($cachePath);
        if ($json === false) {
            throw new \RuntimeException("Cannot read cache file: $cachePath");
        }

        $data = json_decode($json);
        if (!$data) {
            @unlink($cachePath);
            throw new \RuntimeException("Invalid JSON in cache file (deleted). Run command again.");
        }

        return $data;
    }

    /**
     * Refresh plugins.json if missing or older than the cache TTL.
     *
     * @param bool $forceRefresh Always re-download, even if the cache is fresh.
     * @return bool True if a download happened, false if the existing cache was reused.
     */
    public function ensureCacheFresh(bool $forceRefresh = false): bool
    {
        $cachePath = self::getCachePath();
        $cacheDir = dirname($cachePath);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (!$forceRefresh && self::isCacheFresh($cachePath)) {
            return false;
        }

        $content = $this->fetchWithUserAgentFallback(self::API_URL, expectJson: true);
        file_put_contents($cachePath, $content);

        return true;
    }

    private static function isCacheFresh(string $cachePath): bool
    {
        if (!file_exists($cachePath)) {
            return false;
        }
        $stat = stat($cachePath);
        if (!$stat || !$stat['size']) {
            return false;
        }
        return (time() - $stat['mtime']) <= self::CACHE_TTL;
    }

    /**
     * Find a plugin by its frankenstyle component name.
     */
    public function findPlugin(string $component): ?object
    {
        $data = $this->getPluginList();

        foreach ($data->plugins as $plugin) {
            if (!empty($plugin->component) && $plugin->component === $component) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Find the best version of a plugin for the given Moodle release.
     *
     * @param string      $component      Frankenstyle name (e.g. mod_attendance)
     * @param string      $moodleRelease  Moodle major version (e.g. "4.5")
     * @param string|null $pluginVersion  Specific plugin version, or null for latest
     * @param bool        $force          Allow unsupported versions
     * @return object     Version object with downloadurl, version, supportedmoodles, etc.
     */
    public function findBestVersion(
        string $component,
        string $moodleRelease,
        ?string $pluginVersion = null,
        bool $force = false,
    ): object {
        $plugin = $this->findPlugin($component);
        if ($plugin === null) {
            throw new \RuntimeException("Plugin '$component' not found in the moodle.org directory.");
        }

        $bestVersion = null;
        $altVersion = null;

        foreach ($plugin->versions as $version) {
            $supported = $this->isSupportedByMoodle($version, $moodleRelease);

            if ($pluginVersion !== null) {
                if ((string) $version->version === $pluginVersion) {
                    if ($supported) {
                        $bestVersion = $version;
                    } else {
                        $altVersion = $version;
                    }
                }
            } else {
                // Latest: pick the highest supported version
                if ($supported && (!$bestVersion || $version->version > $bestVersion->version)) {
                    $bestVersion = $version;
                } elseif (!$altVersion || $version->version > $altVersion->version) {
                    $altVersion = $version;
                }
            }
        }

        if ($bestVersion) {
            return $bestVersion;
        }

        if ($altVersion && $force) {
            return $altVersion;
        }

        if ($altVersion) {
            throw new \RuntimeException(
                "Plugin '$component' is not supported for Moodle $moodleRelease. "
                . "Use --force to install an unsupported version."
            );
        }

        $label = $pluginVersion ?? 'latest';
        throw new \RuntimeException("Could not find '$component' version $label.");
    }

    /**
     * Download a file from a URL to a local path.
     */
    public function downloadFile(string $url, string $targetPath): void
    {
        $content = $this->fetchWithUserAgentFallback($url, expectJson: false);

        if (file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException("Failed to write to $targetPath");
        }
    }

    /**
     * GET $url, retrying with each of self::USER_AGENTS in turn whenever a
     * request comes back HTTP 403 specifically - download.moodle.org has,
     * at times, blocked plainer/generic-looking User-Agent strings while
     * allowing others through (see self::USER_AGENTS for the reasoning
     * behind the order), so a single 403 doesn't necessarily mean the
     * resource itself is off-limits.
     *
     * Any other outcome - success, or a failure that isn't 403 (404, 429,
     * 5xx, or no HTTP response at all e.g. DNS/TLS/network failure) - is
     * returned/thrown immediately on the first attempt, without burning
     * through the rest of the list: those aren't User-Agent-related, so
     * retrying with a different one wouldn't help.
     *
     * @param bool $expectJson true for the plugins.json API call, false for
     *   binary zip downloads.
     * @return string the response body
     * @throws HttpRequestException on a non-403 failure, or if every
     *   User-Agent in the list was also rejected with 403 - the message
     *   lists all of them, since at that point it's more likely
     *   moodle.org is blocking the request's network/IP outright rather
     *   than filtering by User-Agent.
     */
    private function fetchWithUserAgentFallback(string $url, bool $expectJson): string
    {
        $lastException = null;
        $tried = [];

        foreach (self::USER_AGENTS as $userAgent) {
            $tried[] = $userAgent;

            $content = @file_get_contents($url, false, $this->createStreamContext($expectJson, $url, $userAgent));
            if ($content !== false) {
                return $content;
            }

            $exception = self::httpFailure("Failed to fetch $url", $http_response_header ?? null);
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
            $lastException = $exception;
        }

        throw new HttpRequestException(
            "Failed to fetch $url: rejected with HTTP 403 using every User-Agent tried (" . implode(', ', $tried) . '). '
            . 'download.moodle.org may be blocking this network/IP outright, rather than filtering by User-Agent.',
            403,
            $lastException?->getStatusText(),
        );
    }

    /**
     * Path to the local plugins.json cache file.
     */
    public static function getCachePath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        return $home . '/.moosh/plugins.json';
    }

    private function isSupportedByMoodle(object $version, string $moodleRelease): bool
    {
        foreach ($version->supportedmoodles as $supported) {
            if ((string) $supported->release === $moodleRelease) {
                return true;
            }
        }
        return false;
    }

    /**
     * $http_response_header is a magic local variable PHP populates
     * alongside file_get_contents() over an http:// wrapper, even when the
     * call itself returns false - this parses it into a proper HTTP status
     * code/text so a failure states *why* (eg. 429 Too Many Requests)
     * instead of just that something failed.
     *
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
     * Parse the numeric status code and reason phrase out of the response's
     * first header line (e.g. "HTTP/1.1 429 Too Many Requests" -> [429,
     * "Too Many Requests"]). PHP's stream wrapper follows redirects, so
     * $responseHeaders may contain more than one status line; the last one
     * is the final response actually received.
     *
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

    /**
     * @param bool $expectJson true for the plugins.json API call, false for
     *   binary zip downloads.
     * @param string|null $url the request URL, used only to decide whether
     *   the Marketplace bearer token applies (see isMarketplaceHost())
     * @param string $userAgent see self::USER_AGENTS / fetchWithUserAgentFallback()
     * @return resource
     */
    private function createStreamContext(bool $expectJson, ?string $url, string $userAgent)
    {
        $header = "User-Agent: $userAgent\r\n"
            . "Connection: close\r\n";
        if ($expectJson) {
            $header .= "Accept: application/json\r\n";
        }

        if ($this->token !== null && $this->token !== '' && self::isMarketplaceHost($url)) {
            $header .= 'Authorization: Bearer ' . $this->token . "\r\n";
        }

        $httpConfig = [
            'method' => 'GET',
            'header' => $header,
            'request_fulluri' => true,
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
     * True if $url's host is marketplace.moodle.com (or a subdomain of
     * it) - the only host the Marketplace bearer token should ever be sent
     * to. download.moodle.org (the plugin list API and most plugin zips)
     * never gets the Authorization header, token or not.
     */
    private static function isMarketplaceHost(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        return $host === 'marketplace.moodle.com' || str_ends_with($host, '.marketplace.moodle.com');
    }

    /**
     * Public wrapper around isMarketplaceHost() so callers (e.g.
     * plugin:list-update) can tell, ahead of time, whether a given
     * downloadurl is one that only ever gets the Marketplace bearer token
     * — and therefore the only kind of URL that can 401 with "Not
     * privileged to request the resource" for a plugin that's listed but
     * gated behind a paid Moodle Marketplace subscription.
     */
    public static function isMarketplaceUrl(string $url): bool
    {
        return self::isMarketplaceHost($url);
    }
}
