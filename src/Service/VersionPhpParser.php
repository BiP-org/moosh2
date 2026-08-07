<?php
/**
 * moosh2 — Moodle Shell
 *
 * A plugin's version.php is PHP source that ships inside a zip downloaded
 * from moodle.org/the Marketplace - i.e. content from a third party. The
 * previous approach (Moosh2\Command\Plugin\PluginListApply52Handler::
 * evalVersionPhp(), now removed) `include`d it directly in-process to read
 * $plugin->version, which is arbitrary code execution on downloaded
 * content: anything a version.php's top level does (write files, make
 * network calls, mutate globals, ...) ran for real, with only \Throwable
 * swallowed afterwards.
 *
 * This parses the small set of assignments version.php files actually use
 * ($plugin->version, ->component, ->release, ->requires, ->maturity,
 * ->supported, ->dependencies) via PHP's own tokenizer (token_get_all())
 * and simple pattern matching over the resulting token stream - the file
 * is never include()d, require()d, or eval()d. This mirrors the technique
 * Moodle core itself uses to read a plugin's version.php ahead of trusting
 * it (core\update\validator and friends parse rather than execute).
 *
 * Only literal values are understood: string/int literals, the
 * MATURITY_* / ANY_VERSION constants, and array literals of the exact
 * shapes real version.php files use (`[502, 502]`,
 * `['theme_boost' => 2026042000]`). Anything computed (a variable,
 * a function call, a ternary, string concatenation, ...) is simply not
 * recognised and the corresponding field comes back null/empty - safe by
 * construction, since no expression is ever evaluated.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Service;

final class VersionPhpParser
{
    private const MATURITY_CONSTANTS = [
        'MATURITY_ALPHA' => 50,
        'MATURITY_BETA' => 100,
        'MATURITY_RC' => 150,
        'MATURITY_STABLE' => 200,
    ];

    /**
     * @return array{
     *     component: ?string,
     *     version: ?int,
     *     release: ?string,
     *     requires: ?int,
     *     maturity: ?int,
     *     branch: ?string,
     *     supported: int[],
     *     dependencies: array<string, int|string>,
     * }
     * @throws \RuntimeException if $path doesn't exist or can't be read
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("version.php not found: $path");
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("could not read $path");
        }
        return self::parseSource($source);
    }

    /**
     * @return array{
     *     component: ?string,
     *     version: ?int,
     *     release: ?string,
     *     requires: ?int,
     *     maturity: ?int,
     *     branch: ?string,
     *     supported: int[],
     *     dependencies: array<string, int|string>,
     * }
     */
    public static function parseSource(string $source): array
    {
        $result = [
            'component' => null,
            'version' => null,
            'release' => null,
            'requires' => null,
            'maturity' => null,
            'branch' => null,
            'supported' => [],
            'dependencies' => [],
        ];

        $tokens = self::significantTokens($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if ($tok['id'] !== T_VARIABLE) {
                continue;
            }

            // ($plugin|$module) -> propertyname = <expr>;
            if (in_array($tok['text'], ['$plugin', '$module'], true)) {
                if (
                    !isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3])
                    || $tokens[$i + 1]['id'] !== T_OBJECT_OPERATOR
                    || $tokens[$i + 2]['id'] !== T_STRING
                    || $tokens[$i + 3]['text'] !== '='
                ) {
                    continue;
                }
                $property = $tokens[$i + 2]['text'];
                [$exprTokens, $i] = self::collectExpr($tokens, $i + 4, $count);
                self::apply($result, $property, $exprTokens);
                continue;
            }

            // Top-level $version / $release / $branch / $maturity = <expr>;
            // - the shape Moodle core's own <moodleroot>/version.php uses
            // instead of $plugin->.
            if (
                in_array($tok['text'], ['$version', '$release', '$branch', '$maturity'], true)
                && isset($tokens[$i + 1]) && $tokens[$i + 1]['text'] === '='
            ) {
                $property = ltrim($tok['text'], '$');
                [$exprTokens, $i] = self::collectExpr($tokens, $i + 2, $count);
                self::apply($result, $property, $exprTokens);
            }
        }

        return $result;
    }

    /**
     * Apply one recognised assignment's already-collected expression
     * tokens to the result array, based on the property name.
     *
     * @param array<string, mixed> $result
     * @param array<int, array{id: int|null, text: string}> $exprTokens
     */
    private static function apply(array &$result, string $property, array $exprTokens): void
    {
        switch ($property) {
            case 'component':
            case 'release':
            case 'branch':
                $result[$property] = self::extractString($exprTokens);
                break;
            case 'version':
            case 'requires':
                $result[$property] = self::extractInt($exprTokens);
                break;
            case 'maturity':
                $result['maturity'] = self::extractMaturity($exprTokens);
                break;
            case 'supported':
                $result['supported'] = self::extractIntList($exprTokens);
                break;
            case 'dependencies':
                $result['dependencies'] = self::extractDependencies($exprTokens);
                break;
            default:
                // Unrecognised property (e.g. ->cron, ->incompatible) - ignore.
                break;
        }
    }

    /**
     * Collect every token from $start up to (but not including) the next
     * top-level ';', which is always the end of one PHP assignment
     * statement here (none of the literal shapes we parse can contain a
     * semicolon themselves).
     *
     * @param array<int, array{id: int|null, text: string}> $tokens
     * @return array{0: array<int, array{id: int|null, text: string}>, 1: int}
     *   the collected expression tokens, and the index of the ';' itself
     *   (the caller's for-loop increment then moves past it)
     */
    private static function collectExpr(array $tokens, int $start, int $count): array
    {
        $exprTokens = [];
        $j = $start;
        while ($j < $count && $tokens[$j]['text'] !== ';') {
            $exprTokens[] = $tokens[$j];
            $j++;
        }
        return [$exprTokens, $j];
    }

    /**
     * Tokenize with token_get_all() and drop whitespace/comments, folding
     * every token into a uniform ['id' => int|null, 'text' => string]
     * shape (single-char tokens like ';', '=', '[' come back from
     * token_get_all() as bare strings, not [id, text] pairs).
     *
     * @return array<int, array{id: int|null, text: string}>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $t) {
            if (is_array($t)) {
                [$id, $text] = $t;
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $out[] = ['id' => $id, 'text' => $text];
            } else {
                $out[] = ['id' => null, 'text' => $t];
            }
        }
        return $out;
    }

    /** @param array<int, array{id: int|null, text: string}> $exprTokens */
    private static function extractString(array $exprTokens): ?string
    {
        foreach ($exprTokens as $t) {
            if ($t['id'] === T_CONSTANT_ENCAPSED_STRING) {
                return self::decodeStringLiteral($t['text']);
            }
        }
        return null;
    }

    /**
     * @param array<int, array{id: int|null, text: string}> $exprTokens
     */
    private static function extractInt(array $exprTokens): ?int
    {
        $sign = 1;
        foreach ($exprTokens as $t) {
            if ($t['id'] === null && $t['text'] === '-') {
                $sign = -1;
                continue;
            }
            if ($t['id'] === T_LNUMBER) {
                return $sign * (int) $t['text'];
            }
            // Moodle core's own <moodleroot>/version.php writes $version
            // as a float (e.g. 2024100700.01) to allow minor point
            // releases - plugin version.php files never do, but this
            // class is also used to read core's version.php.
            if ($t['id'] === T_DNUMBER) {
                return $sign * (int) (float) $t['text'];
            }
        }
        return null;
    }

    /** @param array<int, array{id: int|null, text: string}> $exprTokens */
    private static function extractMaturity(array $exprTokens): ?int
    {
        foreach ($exprTokens as $t) {
            if ($t['id'] === T_STRING && isset(self::MATURITY_CONSTANTS[$t['text']])) {
                return self::MATURITY_CONSTANTS[$t['text']];
            }
            if ($t['id'] === T_LNUMBER) {
                return (int) $t['text'];
            }
        }
        return null;
    }

    /**
     * @param array<int, array{id: int|null, text: string}> $exprTokens
     * @return int[]
     */
    private static function extractIntList(array $exprTokens): array
    {
        $values = [];
        foreach ($exprTokens as $t) {
            if ($t['id'] === T_LNUMBER) {
                $values[] = (int) $t['text'];
            }
        }
        return $values;
    }

    /**
     * Extracts 'component' => value pairs from an array-literal expression
     * (either `[...]` or legacy `array(...)`) by scanning for
     * STRING T_DOUBLE_ARROW VALUE triples - deliberately format-agnostic
     * about the surrounding brackets, since only the pairs matter.
     *
     * @param array<int, array{id: int|null, text: string}> $exprTokens
     * @return array<string, int|string> component => required version (int),
     *   or the literal string 'any' for ANY_VERSION
     */
    private static function extractDependencies(array $exprTokens): array
    {
        $deps = [];
        $n = count($exprTokens);
        for ($i = 0; $i < $n; $i++) {
            if ($exprTokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (!isset($exprTokens[$i + 1]) || $exprTokens[$i + 1]['id'] !== T_DOUBLE_ARROW) {
                continue;
            }
            if (!isset($exprTokens[$i + 2])) {
                continue;
            }
            $component = self::decodeStringLiteral($exprTokens[$i]['text']);
            $valueTok = $exprTokens[$i + 2];

            if ($valueTok['id'] === T_LNUMBER) {
                $deps[$component] = (int) $valueTok['text'];
            } elseif ($valueTok['id'] === T_STRING && $valueTok['text'] === 'ANY_VERSION') {
                $deps[$component] = 'any';
            } elseif ($valueTok['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $deps[$component] = self::decodeStringLiteral($valueTok['text']);
            }
            // Anything else (a variable, a function call, ...) is left out
            // rather than guessed at.
        }
        return $deps;
    }

    /**
     * Decode a T_CONSTANT_ENCAPSED_STRING token's raw text (quotes
     * included) into its string value. Deliberately minimal: version.php
     * string literals in practice are plain identifiers/labels, never
     * interpolated or exotic, so full double-quoted-string semantics
     * aren't needed.
     */
    private static function decodeStringLiteral(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        $inner = substr($raw, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }
        return stripcslashes($inner);
    }
}
