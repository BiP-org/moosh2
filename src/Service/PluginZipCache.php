<?php
/**
 * moosh2 — Moodle Shell
 *
 * Shared disk cache for plugin zip files downloaded by plugin:install,
 * plugin:clamscan, and plugin:list-update.
 *
 * Ported from moosh's Moosh\PluginCache.
 *
 * Cache directory resolution order:
 *   1. $MOOSH_CACHE_DIR environment variable, if set and non-empty
 *   2. ~/.moosh/moodleplugins (default)
 *
 * Every cached file is validated before being reused: it must exist,
 * be larger than 0 bytes, and pass a zip integrity check.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

final class PluginZipCache
{
    /**
     * Resolve (and create if necessary) the cache directory.
     *
     * @return string absolute path to the cache dir, without trailing slash
     */
    public static function getCacheDir(): string
    {
        $envdir = getenv('MOOSH_CACHE_DIR');

        if ($envdir !== false && trim($envdir) !== '') {
            $dir = rtrim($envdir, '/');
        } else {
            $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
            $dir = rtrim($home, '/') . '/.moosh/moodleplugins';
        }

        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create cache directory $dir - check permissions.");
            }
        }

        return $dir;
    }

    /**
     * Build the cache file path for a given plugin component + version.
     */
    public static function getCachePath(string $component, string $version): string
    {
        $safeversion = preg_replace('/[^A-Za-z0-9_.-]/', '_', $version);
        return self::getCacheDir() . '/' . $component . '-' . $safeversion . '.zip';
    }

    /**
     * Check that a file exists, is non-empty, and is a structurally valid zip.
     */
    public static function isValidZip(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        clearstatcache(true, $path);
        if (filesize($path) <= 0) {
            return false;
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $result = $zip->open($path, \ZipArchive::CHECKCONS);
            if ($result !== true) {
                return false;
            }
            $zip->close();
            return true;
        }

        // Fall back to the unzip binary's own integrity test when the zip
        // extension isn't available.
        exec('unzip -tqq ' . escapeshellarg($path), $output, $returnvar);
        return $returnvar === 0;
    }

    /**
     * Try to serve $destination from the cache.
     *
     * @return bool true if a valid cached copy was found and copied
     */
    public static function fetch(string $component, string $version, string $destination): bool
    {
        $cachepath = self::getCachePath($component, $version);

        if (self::isValidZip($cachepath)) {
            return copy($cachepath, $destination);
        }

        return false;
    }

    /**
     * Store a freshly downloaded file in the cache. Refuses to cache
     * anything that isn't a valid, non-empty zip.
     *
     * @return bool true if the file was cached
     */
    public static function store(string $component, string $version, string $downloadedfile): bool
    {
        if (!self::isValidZip($downloadedfile)) {
            return false;
        }

        return copy($downloadedfile, self::getCachePath($component, $version));
    }
}
