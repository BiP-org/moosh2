<?php
/**
 * moosh2 — Moodle Shell
 *
 * Shared ClamAV scan runner used by plugin:clamscan directly, and by
 * plugin:list-apply to scan a plugin right after installing it.
 *
 * Ported from moosh's Moosh\Command\Generic\Plugin\PluginClamscan (the
 * binary-lookup / process-invocation half of it — the plugin download and
 * frankenstyle-resolution half lives in PluginClamscan52Handler instead,
 * since that part is specific to the CLI command).
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

final class ClamscanRunner
{
    use WhitelistMatcher;

    /** clamscan exit code convention, preserved exactly. */
    public const EXIT_CLEAN = 0;
    public const EXIT_MALWARE_FOUND = 1;
    public const EXIT_ERROR = 2;

    /**
     * Name of the per-plugin whitelist file, read from the plugin's own
     * root directory. Same format/semantics as
     * PhpMusselRunner::WHITELIST_FILENAME, just a separate file so a
     * phpMussel exception and a ClamAV exception for the same plugin
     * don't have to share one file.
     */
    public const WHITELIST_FILENAME = 'clamscan-whitelist';

    /**
     * Fixed, built-in whitelist entries shipped with moosh2 itself.
     * Empty for now: unlike phpMussel's filename/content heuristics,
     * ClamAV's detections are signature-based, so moosh2 doesn't yet know
     * of a structural false positive worth baking in here. Add one the
     * same way PhpMusselRunner::BUILTIN_WHITELIST does, if one turns up.
     *
     * @var array<string>
     */
    private const BUILTIN_WHITELIST = [];

    public static function getGlobalWhitelistPath(): string
    {
        return (getenv('HOME') ?: sys_get_temp_dir()) . '/.moosh2/clamscan-whitelist';
    }

    /**
     * Locate the clamscan binary in PATH.
     *
     * @return string|null absolute path, or null if not found
     */
    public static function findBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v clamscan 2>/dev/null'));
        return $path !== '' ? $path : null;
    }

    /**
     * Build the clamscan command line as an array of already shell-escaped
     * tokens (kept as an array rather than a single string so it stays
     * directly assertable in tests without re-parsing shell-quoting).
     *
     * @param string               $binary
     * @param string               $pluginRoot
     * @param array<string, mixed> $options database (string|string[]), infected (bool), log (string)
     * @return string[]
     */
    public static function buildArgs(string $binary, string $pluginRoot, array $options = []): array
    {
        $args = [escapeshellarg($binary), '-r'];

        $databases = $options['database'] ?? [];
        if (!is_array($databases)) {
            $databases = ($databases === '' || $databases === null) ? [] : [$databases];
        }

        // Always include the moosh2 custom signature directory if it exists.
        $signatureManager = new ClamavSignatureManager();
        $signatureDir = $signatureManager->getSignatureDir();
        if (is_dir($signatureDir)) {
            array_unshift($databases, $signatureDir);
        }

        foreach ($databases as $database) {
            $args[] = '-d';
            $args[] = escapeshellarg($database);
        }

        if (!empty($options['infected'])) {
            $args[] = '-i';
        }

        if (!empty($options['log'])) {
            $args[] = '--log=' . escapeshellarg((string) $options['log']);
        }

        $args[] = escapeshellarg($pluginRoot);

        return $args;
    }

    /**
     * Run clamscan against $pluginRoot and relay its exit code.
     *
     * clamscan's own exit codes already match the contract this returns
     * (0 clean, 1 malware found, 2 error), so they're passed through as-is;
     * anything unexpected is normalized to EXIT_ERROR.
     *
     * Unlike PhpMusselRunner (which scans file-by-file in-process and can
     * skip a whole-file whitelist entry before scanning at all), clamscan
     * is one external process scanning the whole tree at once — so
     * whitelisting here always happens after the scan: every file is
     * still scanned by clamscan itself, and a matching detection is
     * filtered out of the results (and the exit code recomputed) before
     * moosh2 reports them. See WhitelistMatcher::parseWhitelistLines() for
     * the whitelist file syntax — identical to phpMussel's.
     *
     * @param string               $binary
     * @param string               $pluginRoot
     * @param array<string, mixed> $options   same shape as buildArgs(), plus
     *                                        'whitelist' => array<string> — extra
     *                                        whitelist lines (e.g. from --whitelist),
     *                                        on top of the built-in, global and
     *                                        per-plugin whitelists.
     * @return array{0: int, 1: string[]} [exitcode, output lines]
     */
    public static function scan(string $binary, string $pluginRoot, array $options = []): array
    {
        $args = self::buildArgs($binary, $pluginRoot, $options);
        $command = implode(' ', $args) . ' 2>&1';

        exec($command, $rawOutput, $exitcode);

        if ($exitcode !== self::EXIT_CLEAN && $exitcode !== self::EXIT_MALWARE_FOUND) {
            return [self::EXIT_ERROR, $rawOutput];
        }

        $extraWhitelist = is_array($options['whitelist'] ?? null) ? $options['whitelist'] : [];
        $entries = self::assembleWhitelistEntries(
            self::BUILTIN_WHITELIST,
            self::getGlobalWhitelistPath(),
            rtrim($pluginRoot, '/') . '/' . self::WHITELIST_FILENAME,
            self::WHITELIST_FILENAME,
            $extraWhitelist,
        );

        if ($entries === []) {
            return [$exitcode, $rawOutput];
        }

        $rootPrefix = rtrim($pluginRoot, '/') . '/';
        $output = [];
        $remainingInfected = 0;
        $whitelistedCount = 0;

        foreach ($rawOutput as $line) {
            // Default clamscan infected-line format: "<path>: <Signature> FOUND".
            if (preg_match('/^(.*): (.+) FOUND$/', $line, $m) === 1) {
                $absolute = $m[1];
                $signature = $m[2];
                $relative = str_starts_with($absolute, $rootPrefix)
                    ? substr($absolute, strlen($rootPrefix))
                    : $absolute;

                $match = self::matchEntries($relative, $signature, $entries);
                if ($match !== null) {
                    $whitelistedCount++;
                    $output[] = 'WHITELISTED: ' . $relative . ' — ' . $signature
                        . ' [reason "' . ($match['reason'] ?? '(any)') . '" via ' . $match['source'] . ']';
                    continue;
                }

                $remainingInfected++;
            }

            $output[] = $line;
        }

        if ($whitelistedCount === 0) {
            return [$exitcode, $rawOutput];
        }

        // clamscan's own summary line would still show the pre-whitelist
        // count, so it's rewritten to match what moosh2 actually reports.
        foreach ($output as $i => $line) {
            if (preg_match('/^Infected files: \d+$/', $line) === 1) {
                $output[$i] = 'Infected files: ' . $remainingInfected . ' (whitelisted: ' . $whitelistedCount . ')';
            }
        }

        $exitcode = $remainingInfected > 0 ? self::EXIT_MALWARE_FOUND : self::EXIT_CLEAN;

        return [$exitcode, $output];
    }
}
