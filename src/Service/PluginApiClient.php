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

        $content = file_get_contents(self::API_URL, false, $this->createStreamContext(expectJson: true, url: self::API_URL));
        if ($content === false) {
            throw self::httpFailure('Failed to fetch plugin list from ' . self::API_URL, $http_response_header ?? null);
        }
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
        $content = file_get_contents($url, false, $this->createStreamContext(url: $url));
        if ($content === false) {
            throw self::httpFailure("Failed to download from $url", $http_response_header ?? null);
        }

        if (file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException("Failed to write to $targetPath");
        }
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
     *   binary zip downloads - moodle.org has previously blocked requests
     *   from unusual/obviously-automated User-Agent strings (we hit and
     *   fixed the identical issue in moosh 1.x), so this deliberately uses a
     *   generic, curl-like UA rather than identifying as "moosh2".
     * @param string|null $url the request URL, used only to decide whether
     *   the Marketplace bearer token applies (see isMarketplaceHost())
     * @return resource
     */
    private function createStreamContext(bool $expectJson = false, ?string $url = null)
    {
        $header = "User-Agent: curl/7.81.0\r\n"
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
