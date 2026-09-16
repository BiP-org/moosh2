<?php

namespace Moosh2\Service;

class ClamavSignatureManager
{
    private string $signatureDir;

    /**
     * URL => target filename (the name the file will have on disk,
     * i.e. the *decompressed* name — ClamAV cannot read .gz databases).
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        // InterServer (plain-text signature files served directly)
        'https://sigs.interserver.net/interserver256.hdb'    => 'interserver256.hdb',
        'https://sigs.interserver.net/interservertopline.db' => 'interservertopline.db',
        'https://sigs.interserver.net/shell.ldb'              => 'shell.ldb',
        'https://sigs.interserver.net/whitelist.fp'           => 'whitelist.fp',
    ];

    public function __construct(?string $signatureDir = null)
    {
        $this->signatureDir = $signatureDir
            ?? (getenv('HOME') ?: sys_get_temp_dir()) . '/.moosh2/clamav-signatures';
    }

    public function getSignatureDir(): string
    {
        return $this->signatureDir;
    }

    public function ensureDirectory(): void
    {
        if (!is_dir($this->signatureDir)) {
            mkdir($this->signatureDir, 0755, true);
        }
    }

    /**
     * Download/update all signature files.
     *
     * @return array<string, string> target filename => status message
     */
    public function update(): array
    {
        $this->ensureDirectory();
        $results = [];

        foreach (self::SOURCES as $url => $filename) {
            $target = $this->signatureDir . '/' . $filename;

            $raw = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'timeout'         => 60,
                    'user_agent'      => 'moosh2-clamav-updater/1.0',
                    'follow_location' => 1,
                ],
            ]));

            if ($raw === false || $raw === '') {
                $results[$filename] = "FAILED (could not download from $url)";
                continue;
            }

            // phpMussel serves .gz on GitHub. ClamAV itself only reads
            // uncompressed .hdb/.ndb/.db/.fdb/.fp/.ldb/.ign2 files, so
            // decompress here before writing to disk.
            if (str_ends_with($url, '.gz')) {
                $decoded = @gzdecode($raw);
                if ($decoded === false || $decoded === '') {
                    $results[$filename] = "FAILED (could not gunzip $url)";
                    continue;
                }
                $raw = $decoded;
            }

            // Basic sanity: signature files should not be tiny HTML error pages.
            if (strlen($raw) < 50 || str_contains(substr($raw, 0, 512), '<!DOCTYPE html>')) {
                $results[$filename] = "FAILED (invalid content from $url)";
                continue;
            }

            file_put_contents($target, $raw);
            $results[$filename] = sprintf('OK (%d bytes)', strlen($raw));
        }

        return $results;
    }
}