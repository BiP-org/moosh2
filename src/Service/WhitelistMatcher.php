<?php

namespace Moosh2\Service;

/**
 * Shared whitelist-entry parsing and pattern matching for scan runners
 * (PhpMusselRunner, ClamscanRunner). Kept as a trait rather than a base
 * class since the runners aren't otherwise related, and this is purely
 * mechanical, stateless string/pattern handling — nothing here reads or
 * writes instance state.
 *
 * A whitelist entry is a line of the form:
 *   pattern
 *   pattern | reason
 *
 * See parseWhitelistLines() for the full syntax (glob/** /regex:, and the
 * pattern-vs-scoped-by-reason distinction).
 */
trait WhitelistMatcher
{
    /**
     * Read raw, non-comment, non-blank lines from a whitelist file.
     * Missing file simply means no entries from this source — not an error.
     *
     * @return array<string>
     */
    private static function loadWhitelistLines(string $whitelistFile): array
    {
        if (!is_file($whitelistFile) || !is_readable($whitelistFile)) {
            return [];
        }

        $lines = [];
        foreach (file($whitelistFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Parse raw whitelist lines into entries.
     *
     * Each line is either:
     *   pattern
     *   pattern | reason
     *
     * "pattern" is either a glob or a regular expression, matched against
     * the file's path relative to the plugin root (forward slashes, no
     * leading slash). (Examples below write "**\/" with a backslash only
     * because "*" immediately followed by "/" would end this comment —
     * the actual pattern has no backslash, just "**" then "/".)
     *
     *   - Glob (the default): "*" matches within one path segment (never
     *     crosses "/"), "**" matches across any number of segments —
     *     including zero, so "**\/*.min.js" also matches a file directly
     *     in the plugin root, not only a nested one — and "?" matches one
     *     non-"/" character. Examples:
     *
     *       lib/thirdparty/foo.php
     *       lib/thirdparty/*
     *       **\/*.min.js
     *       **\/jquery-*.js
     *       .idea/**
     *
     *   - Regex: prefix the pattern with "regex:" for full PCRE control
     *     when a glob can't express it, e.g. "regex:^jquery-\d+(\.\d+)*
     *     (\.min)?\.js$". Matched case-sensitively, anchored to the whole
     *     relative path (no need to add your own ^/$ or delimiters).
     *
     * With no reason, the whole file is whitelisted outright — every
     * detection on it is suppressed (for phpMussel this also skips the
     * file before scanning at all; for clamscan, which doesn't scan
     * file-by-file, it's filtered out of the results afterward). With a
     * reason, only a detection whose message/signature name contains that
     * reason as a case-insensitive substring is suppressed; anything else
     * found on that file still fires normally, e.g.:
     *
     *   **\/*.min.js | phpMussel-Suspect.DoubleExtension-00
     *   tests/behat/*.feature | PHP chameleon attack
     *
     * @param array<string> $lines
     * @return array<array{pattern:string, reason:?string, source:string}>
     */
    private static function parseWhitelistLines(array $lines, string $source): array
    {
        $entries = [];
        foreach ($lines as $line) {
            $parts   = explode('|', $line, 2);
            $pattern = trim($parts[0]);
            if ($pattern === '') {
                continue;
            }
            $reason = isset($parts[1]) ? trim($parts[1]) : '';
            $entries[] = [
                'pattern' => $pattern,
                'reason'  => $reason !== '' ? $reason : null,
                'source'  => $source,
            ];
        }
        return $entries;
    }

    /**
     * Find the first entry whose pattern matches $relativePath and, if
     * the entry has a reason, whose reason is a case-insensitive
     * substring of $message.
     *
     * @param array<array{pattern:string, reason:?string, source:string}> $entries
     * @return array{pattern:string, reason:?string, source:string}|null
     */
    private static function matchEntries(string $relativePath, ?string $message, array $entries): ?array
    {
        foreach ($entries as $entry) {
            if (!self::patternMatches($entry['pattern'], $relativePath)) {
                continue;
            }
            if ($entry['reason'] === null) {
                return $entry;
            }
            if ($message !== null && stripos($message, $entry['reason']) !== false) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @see parseWhitelistLines() for the supported pattern syntax.
     */
    private static function patternMatches(string $pattern, string $relativePath): bool
    {
        if (str_starts_with($pattern, 'regex:')) {
            $regex = substr($pattern, strlen('regex:'));
            return @preg_match('#' . $regex . '#', $relativePath) === 1;
        }

        return preg_match(self::globToRegex($pattern), $relativePath) === 1;
    }

    /**
     * Translate a glob into an anchored regex. "*" matches within one path
     * segment; "**" matches across any number of segments, including
     * zero — "**\/foo" (no backslash in the real pattern; see the note on
     * parseWhitelistLines()) also matches a top-level "foo"; "?" matches
     * one non-"/" character. Everything else is matched literally.
     */
    private static function globToRegex(string $pattern): string
    {
        $regex = '';
        $len   = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                if (($pattern[$i + 2] ?? '') === '/') {
                    // "**/" also matches zero directories, so the
                    // segment before the next literal becomes optional.
                    $regex .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $regex .= '.*';
                    $i += 1;
                }
            } elseif ($c === '*') {
                $regex .= '[^/]*';
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($c, '#');
            }
        }
        return '#^' . $regex . '$#';
    }

    /**
     * Assemble whitelist entries from every tier, each tagged with where
     * it came from (for report output). Later tiers don't override
     * earlier ones — a match anywhere whitelists it. Shared helper so
     * every runner wires up built-in + global + per-plugin + extra the
     * same way.
     *
     * @param array<string> $builtin           Raw lines, e.g. self::BUILTIN_WHITELIST.
     * @param string        $globalWhitelist   Path to the global whitelist file.
     * @param string        $pluginWhitelist   Path to the plugin's own whitelist file.
     * @param string        $pluginSourceLabel Source label to report for $pluginWhitelist's entries.
     * @param array<string> $extraWhitelist    Raw lines from e.g. --whitelist.
     * @return array<array{pattern:string, reason:?string, source:string}>
     */
    private static function assembleWhitelistEntries(
        array $builtin,
        string $globalWhitelist,
        string $pluginWhitelist,
        string $pluginSourceLabel,
        array $extraWhitelist,
    ): array {
        return [
            ...self::parseWhitelistLines($builtin, 'built-in'),
            ...self::parseWhitelistLines(self::loadWhitelistLines($globalWhitelist), 'global'),
            ...self::parseWhitelistLines(self::loadWhitelistLines($pluginWhitelist), $pluginSourceLabel),
            ...self::parseWhitelistLines($extraWhitelist, '--whitelist'),
        ];
    }
}
