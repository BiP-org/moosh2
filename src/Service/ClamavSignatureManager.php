<?php

namespace Moosh2\Service;

class ClamavSignatureManager
{
    private string $signatureDir;

    /** @var array<string, string> URL => filename mapping */
    private const SOURCES = [
        // InterServer
        'https://sigs.interserver.net/interserver256.hdb'    => 'interserver256.hdb',
        'https://sigs.interserver.net/interservertopline.db' => 'interservertopline.db',
        'https://sigs.interserver.net/shell.ldb'              => 'shell.ldb',
        'https://sigs.interserver.net/whitelist.fp'           => 'whitelist.fp',
        // phpMussel — ClamAV-compatible
        'https://raw.githubusercontent.com/phpMussel/Signatures/master/clamav/clamav.hdb'       => 'phpmussel_clamav.hdb',
        'https://raw.githubusercontent.com/phpMussel/Signatures/master/misc/phpmussel.hdb'       => 'phpmussel.hdb',
        'https://raw.githubusercontent.com/phpMussel/Signatures/master/misc/phpmussel.db'        => 'phpmussel.db',
        'https://raw.githubusercontent.com/phpMussel/Signatures/master/misc/phpmussel.fdb'       => 'phpmussel.fdb',
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

    /**
     * Ensure the signature directory exists.
     */
    public function ensureDirectory(): void
    {
        if (!is_dir($this->signatureDir)) {
            mkdir($this->signatureDir, 0755, true);
        }
    }

    /**
     * Download/update all signature files.
     *
     * @return array<string, string> filename => status message
     */
    public function update(): array
    {
        $this->ensureDirectory();
        $results = [];

        foreach (self::SOURCES as $url => $filename) {
            $target = $this->signatureDir . '/' . $filename;
            $content = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 60, 'user_agent' => 'moosh2-clamav-updater/1.0'],
            ]));

            if ($content === false || $content === '') {
                $results[$filename] = "FAILED (could not download from $url)";
                continue;
            }

            // Basic sanity: signature files should not be tiny HTML error pages.
            if (strlen($content) < 100 || str_contains($content, '<!DOCTYPE html>')) {
                $results[$filename] = "FAILED (invalid content from $url)";
                continue;
            }

            file_put_contents($target, $content);
            $results[$filename] = sprintf('OK (%d bytes)', strlen($content));
        }

        return $results;
    }
}