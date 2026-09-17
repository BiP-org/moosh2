<?php

namespace Moosh2\Service;

use phpMussel\Core\Loader;
use phpMussel\Core\Scanner;

class PhpMusselRunner
{
    public const EXIT_CLEAN         = 0;
    public const EXIT_MALWARE_FOUND = 1;
    public const EXIT_ERROR         = 2;

    /**
     * Repo/CI metadata directories that are never part of a plugin's
     * shipped payload. Skipped entirely (not descended into) rather than
     * scanned-then-filtered: phpMussel's "filename manipulation" heuristic
     * false-positives on dotfiles (a name with no basename before the
     * first "." reads as an all-extension filename), and there's no
     * value in scanning VCS/CI internals anyway.
     *
     * @var array<string>
     */
    private const EXCLUDED_DIRS = [
        '.git',
        '.github',
        '.gitlab',
        '.svn',
        '.hg',
    ];

    /**
     * Individual CI/repo-metadata dotfiles that can live at any level of
     * the tree (not just the root) and are excluded for the same reason
     * as EXCLUDED_DIRS above.
     *
     * @var array<string>
     */
    private const EXCLUDED_FILES = [
        '.gitlab-ci.yml',
        '.gitlab-ci.yaml',
        '.travis.yml',
        '.gitignore',
        '.gitattributes',
        '.DS_Store',
    ];

    private PhpMusselSignatureManager $signatureManager;

    public function __construct(?PhpMusselSignatureManager $signatureManager = null)
    {
        $this->signatureManager = $signatureManager ?? new PhpMusselSignatureManager();
    }

    /**
     * @return array{exitCode:int, output:string, infectedFiles:array<string>}
     */
    public function scan(string $pluginRoot): array
    {
        $configPath = $this->signatureManager->getConfigPath();
        if (!is_file($configPath) || !is_readable($configPath)) {
            return [
                'exitCode'      => self::EXIT_ERROR,
                'output'        => "phpMussel configuration not found at $configPath.\n"
                    . 'Run: moosh plugin:phpmuslescan:update-signatures',
                'infectedFiles' => [],
            ];
        }

        $signatureDir = $this->signatureManager->getSignatureDir();
        if (!is_dir($signatureDir)) {
            return [
                'exitCode'      => self::EXIT_ERROR,
                'output'        => "phpMussel signatures directory not found at $signatureDir.\n"
                    . 'Run: moosh plugin:phpmuslescan:update-signatures',
                'infectedFiles' => [],
            ];
        }

        // Cache and quarantine dirs are required by the Loader constructor.
        // Create them under ~/.moosh2/ so the vendor dir stays untouched.
        $cacheDir = $this->signatureManager->getCacheDir();
        $quarantineDir = $this->signatureManager->getQuarantineDir();
        foreach ([$cacheDir, $quarantineDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return [
                    'exitCode'      => self::EXIT_ERROR,
                    'output'        => "Could not create $dir",
                    'infectedFiles' => [],
                ];
            }
        }

        // Collect files (relative-path keys → absolute paths).
        $rootPrefix = rtrim($pluginRoot, '/') . '/';
        $files = [];
        foreach ($this->iterateFiles($pluginRoot) as $absolute) {
            $relative = str_starts_with($absolute, $rootPrefix)
                ? substr($absolute, strlen($rootPrefix))
                : $absolute;
            $files[$relative] = $absolute;
        }

        if ($files === []) {
            return [
                'exitCode'      => self::EXIT_CLEAN,
                'output'        => "No files to scan in $pluginRoot",
                'infectedFiles' => [],
            ];
        }

        try {
            // Exactly one Loader per scan: it installs a global error
            // handler in its constructor and restores it in __destruct(),
            // so chaining two would nest handlers.
            $loader = new Loader($configPath, $cacheDir, $quarantineDir, $signatureDir);
            $scanner = new Scanner($loader);
        } catch (\Throwable $e) {
            return [
                'exitCode'      => self::EXIT_ERROR,
                'output'        => 'phpMussel initialisation failed: ' . $e->getMessage(),
                'infectedFiles' => [],
            ];
        }

        // Format 1: integer results per scanned item. This is the
        // authoritative signal for the exit code because it distinguishes
        // "clean" (1) from every failure mode (-5..0).
        $intResults = $scanner->scan($files, 1);

        // Only re-run with Format 3 (human-readable) if anything needs
        // reporting — avoids a redundant pass on the common all-clean case.
        $needsDetail = false;
        foreach ($intResults as $result) {
            if ((int) $result !== 1) {
                $needsDetail = true;
                break;
            }
        }
        $strResults = $needsDetail ? $scanner->scan($files, 3) : [];

        $infected = [];
        $errors   = [];
        $lines    = [];
        $lines[]  = "Scanning $pluginRoot with phpMussel";
        $lines[]  = "Signatures: $signatureDir";
        $lines[]  = '';

        foreach ($intResults as $key => $result) {
            $result = (int) $result;
            // phpMussel returns result keys in the form
            // "HASH:FILESIZE:FILENAME" regardless of how the input was
            // keyed, and the human-readable Format 3/4 message names the
            // signature but NOT the file. Extract the filename so every
            // line below names what was actually flagged — a report that
            // says "something was infected" without saying which file is
            // useless to the operator.
            $filename = $this->filenameFromKey((string) $key);

            if ($result === 2) {
                $infected[] = $filename;
                $msg = $strResults[$key] ?? null;
                $lines[] = 'INFECTED: ' . $filename
                    . ($msg !== null ? ' — ' . $msg : '');
            } elseif ($result < 0) {
                $errors[] = $filename;
                $msg = $strResults[$key] ?? null;
                $lines[] = 'SCAN ERROR: ' . $filename
                    . ($msg !== null ? ' — ' . $msg : " (code $result)");
            } elseif ($result === 0) {
                $lines[] = "SKIP: $filename (target not found)";
            }
            // $result === 1: clean — no output.
        }

        $lines[] = '';
        $lines[] = '----------- SCAN SUMMARY -----------';
        $lines[] = 'Scanned files:  ' . count($files);
        $lines[] = 'Infected files: ' . count($infected);
        $lines[] = 'Scan errors:    ' . count($errors);

        $exitCode = self::EXIT_CLEAN;
        if ($infected !== []) {
            $exitCode = self::EXIT_MALWARE_FOUND;
        } elseif ($errors !== []) {
            $exitCode = self::EXIT_ERROR;
        }

        return [
            'exitCode'      => $exitCode,
            'output'        => implode("\n", $lines),
            'infectedFiles' => $infected,
        ];
    }

    /**
     * @return \Generator<string>
     */
    private function iterateFiles(string $root): \Generator
    {
        // SKIP_DOTS only skips the "." and ".." entries — it does NOT skip
        // files/directories whose own name starts with a dot (.git,
        // .github, .gitlab-ci.yml, ...). Those are filtered explicitly
        // below via a RecursiveCallbackFilterIterator so excluded
        // directories are never descended into at all.
        $inner = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $inner,
            function (\SplFileInfo $current) {
                $name = $current->getFilename();
                if ($current->isDir()) {
                    return !in_array($name, self::EXCLUDED_DIRS, true);
                }
                return !in_array($name, self::EXCLUDED_FILES, true);
            },
        );
        $it = new \RecursiveIteratorIterator($filter);
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * phpMussel returns per-item result keys as "HASH:FILESIZE:FILENAME".
     * Extract just the filename for report output. Falls back to the
     * whole key if there's no colon — better to print something than
     * nothing.
     */
    private function filenameFromKey(string $key): string
    {
        $pos = strrpos($key, ':');
        return $pos === false ? $key : substr($key, $pos + 1);
    }
}