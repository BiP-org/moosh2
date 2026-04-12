<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Shared helper for reading space-separated IDs from stdin.
 */
trait StdinIdsTrait
{
    /**
     * Read space-separated IDs from stdin.
     *
     * @return int[]|null  Array of IDs when --stdin is active, null otherwise.
     */
    private function readStdinIds(InputInterface $input): ?array
    {
        if (!$input->getOption('stdin')) {
            return null;
        }

        $raw = file_get_contents('php://stdin');
        $ids = array_filter(
            array_map('intval', preg_split('/\s+/', trim($raw))),
            fn(int $id) => $id > 0,
        );

        return $ids;
    }
}
