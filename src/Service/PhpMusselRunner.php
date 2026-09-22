<?php

namespace Moosh2\Service;

use phpMussel\Core\Loader;
use phpMussel\Core\Scanner;

class PhpMusselRunner
{
    use WhitelistMatcher;

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
    private string $globalWhitelistPath;

    public function __construct(?PhpMusselSignatureManager $signatureManager = null, ?string $globalWhitelistPath = null)
    {
        $this->signatureManager = $signatureManager ?? new PhpMusselSignatureManager();
        $this->globalWhitelistPath = $globalWhitelistPath
            ?? (getenv('HOME') ?: sys_get_temp_dir()) . '/.moosh2/phpmuslescan-whitelist';
    }

    /**
     * Name of the per-plugin whitelist file. Read from the whitelist
     * directory passed to scan() ($whitelistDir, defaulting to
     * $pluginRoot) — scoped to one plugin automatically, no global/shared
     * config to keep in sync across plugins.
     *
     * `plugin:phpmuslescan` (standalone) scans a bare plugin tree with no
     * other directory available, so there $whitelistDir is always
     * $pluginRoot and the file lives alongside that plugin's version.php.
     * `plugin:list-apply` instead passes the declarative plugin list's
     * component directory (the same directory as that component's
     * `version`/`checksum`/`archive/`) — NOT the installed Moodle plugin
     * directory ($pluginRoot there), because the installed directory is
     * replaced wholesale by every (re)install and would silently drop the
     * whitelist file on the next upgrade.
     */
    public const WHITELIST_FILENAME = 'phpmuslescan-whitelist';

    /**
     * Fixed, built-in whitelist entries shipped with moosh2 itself.
     * Always active on every scan — not read from any file, not
     * user-editable. These are known, structural false positives caused
     * by moosh2's own conventions or by heuristics that are inherently
     * noisy on certain file types, not project-specific exceptions.
     *
     * Format is the same as any whitelist line: "pattern" (whitelists the
     * whole file, any detection) or "pattern | reason" (only suppresses a
     * detection whose message contains that substring, case-insensitive
     * — anything else found on a matching file still fires normally).
     *
     * @var array<string>
     */
    private const BUILTIN_WHITELIST = [
        // moosh2 (plugin:list-apply) itself touches this marker file in
        // every downloaded-plugin directory it manages — see
        // PluginListApply52Handler::MARKER_FILENAME. Its name has
        // nothing before the first ".", which phpMussel's filename-
        // manipulation heuristic reads as an all-extension filename.
        '.downloaded-non-core-plugin | Filename manipulation detected',
        // IDE Settings
        '.editorconfig | Filename manipulation detected',
        '.eslintrc | Filename manipulation detected',
        '.eslintignore | Filename manipulation detected',
        '.idea/** | Filename manipulation detected',
        '.jshintignore | Filename manipulation detected',
        '.jshintrc | Filename manipulation detected',
        '.mdtconfig | Filename manipulation detected',
        '.nojekyll | Filename manipulation detected',
        '.php-cs-fixer.php | Filename manipulation detected',
        '.phpcs.xml | Filename manipulation detected',
        '.prettierrc | Filename manipulation detected',
        '.vscode/* | Filename manipulation detected',
        // Minified/versioned JS filenames (jquery-3.6.0.min.js) have two
        // "extension-like" suffixes (.6.0.min.js), which trips phpMussel's
        // double-extension heuristic.
        '**/*.js | phpMussel-Suspect.DoubleExtension-00',
        '**/*.js.map | phpMussel-Suspect.DoubleExtension-00',
        // Behat .feature files are Gherkin scenarios; their prose can read
        // enough like PHP to trip the chameleon heuristic. Scoped to
        // tests/behat/ and to that one detection.
        'tests/behat/*.feature | PHP chameleon attack',
        // allow vendor/ directory
        'vendor/** | Detected phpMussel-FN.X-05',
        'vendor/**/*.asc | phpMussel-Encrypted.PGP-1',
    ];

    public function getGlobalWhitelistPath(): string
    {
        return $this->globalWhitelistPath;
    }

    /**
     * @param string        $pluginRoot     Root directory of the plugin to scan.
     * @param array<string> $extraWhitelist Additional whitelist lines (e.g. from --whitelist),
     *                                      same format as any whitelist file, merged in on top
     *                                      of the built-in, global and per-plugin whitelists.
     * @param string|null   $whitelistDir   Directory the per-plugin WHITELIST_FILENAME is read
     *                                      from. Defaults to $pluginRoot (the standalone
     *                                      plugin:phpmuslescan behaviour). plugin:list-apply
     *                                      passes the declarative plugin list's component
     *                                      directory instead — see WHITELIST_FILENAME.
     * @return array{exitCode:int, output:string, infectedFiles:array<string>}
     */
    public function scan(string $pluginRoot, array $extraWhitelist = [], ?string $whitelistDir = null): array
    {
        $whitelistDir ??= $pluginRoot;
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

        // Assemble whitelist entries from every tier, each tagged with
        // where it came from (for report output). Later tiers don't
        // override earlier ones — a match anywhere whitelists it.
        $entries = self::assembleWhitelistEntries(
            self::BUILTIN_WHITELIST,
            $this->globalWhitelistPath,
            rtrim($whitelistDir, '/') . '/' . self::WHITELIST_FILENAME,
            self::WHITELIST_FILENAME,
            $extraWhitelist,
        );
        // Whole-file entries (no reason) are excluded before scanning at
        // all — cheaper, and matches the pre-existing behaviour. Scoped
        // entries (pattern + reason) need the actual detection message,
        // so those can only be applied after scanning.
        $wholeFileEntries = array_filter($entries, static fn (array $e) => $e['reason'] === null);
        $scopedEntries    = array_filter($entries, static fn (array $e) => $e['reason'] !== null);

        // Collect files (relative-path keys → absolute paths), skipping
        // anything matched by a whole-file whitelist entry.
        $rootPrefix = rtrim($pluginRoot, '/') . '/';
        $files = [];
        $whitelistedBySource = [];
        foreach ($this->iterateFiles($pluginRoot) as $absolute) {
            $relative = str_starts_with($absolute, $rootPrefix)
                ? substr($absolute, strlen($rootPrefix))
                : $absolute;

            $match = self::matchEntries($relative, null, $wholeFileEntries);
            if ($match !== null) {
                $whitelistedBySource[$match['source']][] = $relative;
                continue;
            }

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
        foreach ($whitelistedBySource as $source => $items) {
            $lines[] = "Whitelisted ($source): " . count($items) . ' file(s)';
            foreach ($items as $w) {
                $lines[] = "  - $w";
            }
        }
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
                $msg = $strResults[$key] ?? null;
                $scopedMatch = self::matchEntries($filename, $msg, $scopedEntries);
                if ($scopedMatch !== null) {
                    $lines[] = 'WHITELISTED: ' . $filename . ' — ' . ($msg ?? '(no detail)')
                        . ' [reason "' . $scopedMatch['reason'] . '" via ' . $scopedMatch['source'] . ']';
                    continue;
                }
                $infected[] = $filename;
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
                // The whitelist file is moosh2's own tooling config, not
                // plugin payload — and being a dotfile itself, scanning
                // it would trip the exact "filename manipulation"
                // heuristic it's meant to help suppress.
                return !in_array($name, self::EXCLUDED_FILES, true) && $name !== self::WHITELIST_FILENAME;
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