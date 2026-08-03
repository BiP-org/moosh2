<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

/**
 * Thrown when an HTTP request made by PluginApiClient fails, carrying the
 * parsed HTTP status code/text (when a response was received at all) so
 * callers can report exactly what happened - e.g. distinguishing a 429
 * (rate limited - worth retrying) from a 403 (blocked) or a network
 * failure where no response came back at all.
 */
class HttpRequestException extends \RuntimeException
{
    private ?int $statusCode;
    private ?string $statusText;

    public function __construct(string $message, ?int $statusCode = null, ?string $statusText = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->statusText = $statusText;
    }

    /**
     * The numeric HTTP status code (e.g. 429), or null if no HTTP response
     * was ever received (DNS/TLS/connection failure).
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The HTTP reason phrase (e.g. "Too Many Requests"), or null if no HTTP
     * response was ever received.
     */
    public function getStatusText(): ?string
    {
        return $this->statusText;
    }

    /** True for 429 specifically - the case most likely to be transient rate limiting. */
    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }
}
