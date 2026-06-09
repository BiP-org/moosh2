<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service\Moodle;

/**
 * Resolves a user-supplied Moodle version into a download URL on
 * download.moodle.org and streams the tarball to disk.
 *
 * URL conventions on download.moodle.org:
 *   - Branch directory:  stableXY  for Moodle <= 3.x  (e.g. 3.10 -> stable310)
 *                        stableX0Y for Moodle >= 4.x  (e.g. 5.2  -> stable502)
 *   - Latest in branch:  moodle-latest-<collapsed>.tgz
 *   - Exact release:     moodle-<X.Y.Z>.tgz
 *   - Overall latest:    parsed from /releases/latest/
 */
final class MoodleReleaseResolver
{
    private const LATEST_PAGE_URL = 'https://download.moodle.org/releases/latest/';
    private const DOWNLOAD_BASE   = 'https://download.moodle.org/download.php/direct';

    public function __construct(private readonly ?string $proxy = null)
    {
    }

    public function resolve(?string $version): ResolvedRelease
    {
        if ($version === null || $version === '') {
            return $this->resolveLatestStable();
        }

        $parts = explode('.', $version);
        foreach ($parts as $p) {
            if ($p === '' || !ctype_digit($p)) {
                throw new \InvalidArgumentException("Invalid Moodle version '$version'. Use X.Y or X.Y.Z format.");
            }
        }

        if (count($parts) === 2) {
            $collapsed = $this->collapseBranch((int) $parts[0], (int) $parts[1]);
            $url = self::DOWNLOAD_BASE . "/stable$collapsed/moodle-latest-$collapsed.tgz";
            return new ResolvedRelease($url, "latest $parts[0].$parts[1]");
        }

        if (count($parts) === 3) {
            $collapsed = $this->collapseBranch((int) $parts[0], (int) $parts[1]);
            $exact = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
            $url = self::DOWNLOAD_BASE . "/stable$collapsed/moodle-$exact.tgz";
            return new ResolvedRelease($url, $exact);
        }

        throw new \InvalidArgumentException("Provide version in X.Y or X.Y.Z format.");
    }

    /**
     * Stream a URL to a local file path.
     */
    public function download(string $url, string $targetPath): void
    {
        $context = $this->createStreamContext();

        $src = @fopen($url, 'rb', false, $context);
        if ($src === false) {
            throw new \RuntimeException("Failed to open $url for reading.");
        }

        $dst = @fopen($targetPath, 'wb');
        if ($dst === false) {
            fclose($src);
            throw new \RuntimeException("Failed to open $targetPath for writing.");
        }

        $copied = stream_copy_to_stream($src, $dst);
        fclose($src);
        fclose($dst);

        if ($copied === false || $copied === 0) {
            @unlink($targetPath);
            throw new \RuntimeException("Failed to download $url.");
        }
    }

    private function resolveLatestStable(): ResolvedRelease
    {
        $page = @file_get_contents(self::LATEST_PAGE_URL, false, $this->createStreamContext());
        if ($page === false) {
            throw new \RuntimeException(
                'Failed to fetch ' . self::LATEST_PAGE_URL . ' to discover the latest stable version.'
            );
        }

        if (!preg_match(
            '|https://download\.moodle\.org/download\.php/stable(\d+)/moodle-|',
            $page,
            $m,
        )) {
            throw new \RuntimeException(
                "Couldn't find the latest stable version of Moodle on " . self::LATEST_PAGE_URL
            );
        }

        $collapsed = $m[1];
        $url = self::DOWNLOAD_BASE . "/stable$collapsed/moodle-latest-$collapsed.tgz";
        return new ResolvedRelease($url, "latest stable (branch $collapsed)");
    }

    private function collapseBranch(int $major, int $minor): string
    {
        // 3.10 -> "310"; 4.4 -> "404"; 5.2 -> "502". Major >= 4 inserts an extra "0".
        $collapsed = (string) $major;
        if ($major >= 4) {
            $collapsed .= '0';
        }
        $collapsed .= (string) $minor;
        return $collapsed;
    }

    /**
     * @return resource
     */
    private function createStreamContext()
    {
        $httpConfig = [
            'method' => 'GET',
            'header' => "User-Agent: moosh2\r\nConnection: close\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 60,
            'request_fulluri' => true,
        ];

        $proxyUrl = $this->proxy
            ?? (getenv('http_proxy') ?: (getenv('HTTP_PROXY') ?: null));

        if ($proxyUrl) {
            $uriParts = parse_url($proxyUrl);
            $httpConfig['proxy'] = sprintf(
                '%s://%s%s',
                $uriParts['scheme'] ?? 'tcp',
                $uriParts['host'] ?? '',
                empty($uriParts['port']) ? '' : ':' . $uriParts['port'],
            );

            if (!empty($uriParts['user']) && !empty($uriParts['pass'])) {
                $authEncoded = base64_encode($uriParts['user'] . ':' . $uriParts['pass']);
                $httpConfig['header'] .= 'Proxy-Authorization: Basic ' . $authEncoded . "\r\n";
            }
        }

        return stream_context_create(['http' => $httpConfig, 'https' => $httpConfig]);
    }
}
