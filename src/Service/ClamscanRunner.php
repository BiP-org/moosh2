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
    /** clamscan exit code convention, preserved exactly. */
    public const EXIT_CLEAN = 0;
    public const EXIT_MALWARE_FOUND = 1;
    public const EXIT_ERROR = 2;

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
     * @param string               $binary
     * @param string               $pluginRoot
     * @param array<string, mixed> $options same shape as buildArgs()
     * @return array{0: int, 1: string[]} [exitcode, output lines]
     */
    public static function scan(string $binary, string $pluginRoot, array $options = []): array
    {
        $args = self::buildArgs($binary, $pluginRoot, $options);
        $command = implode(' ', $args) . ' 2>&1';

        exec($command, $output, $exitcode);

        if ($exitcode === self::EXIT_CLEAN || $exitcode === self::EXIT_MALWARE_FOUND) {
            return [$exitcode, $output];
        }

        return [self::EXIT_ERROR, $output];
    }
}
