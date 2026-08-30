<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

/**
 * One plugin version's release notes, as scraped from a Moodle
 * Marketplace plugin's "Versions" page.
 */
final class MarketplaceReleaseNotes
{
    public function __construct(
        public readonly string $releaseName,
        public readonly string $buildNumber,
        public readonly ?string $maturity,
        public readonly ?string $supportedMoodle,
        public readonly string $notes,
    ) {
    }
}
