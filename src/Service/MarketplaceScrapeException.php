<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

/**
 * Thrown when a Moodle Marketplace page was fetched successfully but
 * could not be parsed as expected, or didn't contain the requested
 * plugin/version. Distinct from HttpRequestException (network/HTTP
 * failure) so callers - and moosh2's own error reporting - can tell "the
 * request failed" apart from "the request succeeded but the page didn't
 * have what we expected", which usually means Moodle Marketplace changed
 * its HTML layout and this scraper needs updating.
 */
class MarketplaceScrapeException extends \RuntimeException
{
}
