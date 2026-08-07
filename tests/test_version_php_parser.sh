#!/usr/bin/env bash
#
# Tests for Moosh2\Service\VersionPhpParser and the zip magic-byte check in
# Moosh2\Service\PluginZipCache. Unlike most test_*.sh files here, these
# don't touch a Moodle install at all - they exercise pure PHP services
# directly - but this still sources common.sh for the PASS/FAIL/
# print_summary bookkeeping and the shared test-run lock the rest of the
# suite expects.
#
# Usage: bash tests/test_version_php_parser.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 VersionPhpParser / zip magic-byte tests ==="
echo ""

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
AUTOLOAD="$REPO_DIR/vendor/autoload.php"

if [ ! -f "$AUTOLOAD" ]; then
    echo "ERROR: $AUTOLOAD not found - run 'composer install' first."
    exit 1
fi

WORKDIR=$(mktemp -d)
trap 'rm -rf "$WORKDIR"' EXIT

# A real-world example: a theme depending on a minimum version of its
# parent theme, exactly the shape core\update\validator and this parser
# both need to read without executing.
cat > "$WORKDIR/theme_boost_union_version.php" << 'PHP'
<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'theme_boost_union';
$plugin->version = 2026042012;
$plugin->release = 'v5.2-r8';
$plugin->requires = 2026042000;
$plugin->supported = [502, 502];
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = ['theme_boost' => 2026042000];
PHP

# A version.php that does something malicious at the top level - proves
# parseFile() never executes the file (only evalVersionPhp(), now removed,
# used to `include` it).
cat > "$WORKDIR/evil_version.php" << PHP
<?php
defined('MOODLE_INTERNAL') || die();
file_put_contents('$WORKDIR/PWNED.txt', 'owned');
\$plugin->component = 'mod_evil';
\$plugin->version = 2024010100;
\$plugin->dependencies = ['mod_forum' => ANY_VERSION];
PHP

# Moodle core's own <moodleroot>/version.php shape: top-level $version (a
# float, to allow point releases) / $release / $branch / $maturity rather
# than $plugin->.
cat > "$WORKDIR/core_version.php" << 'PHP'
<?php
$version  = 2026042000.02;
$release  = '5.2';
$branch   = '502';
$maturity = MATURITY_STABLE;
PHP

RESULT_FILE="$WORKDIR/result.json"
php -r '
require $argv[1];
use Moosh2\Service\VersionPhpParser;
use Moosh2\Service\PluginZipCache;

$workdir = $argv[2];
$out = [];

$parsed = VersionPhpParser::parseFile("$workdir/theme_boost_union_version.php");
$out["theme_boost_union"] = $parsed;

$evilparsed = VersionPhpParser::parseFile("$workdir/evil_version.php");
$out["evil"] = $evilparsed;
$out["evil_side_effect_ran"] = file_exists("$workdir/PWNED.txt");

$out["core_version"] = VersionPhpParser::parseFile("$workdir/core_version.php");

file_put_contents("$workdir/not_a_zip.txt", "<html><body>401 Not privileged</body></html>");
file_put_contents("$workdir/real.zip", "PK\x03\x04" . str_repeat("\0", 20));

$out["magic_ok_on_real_zip"] = PluginZipCache::hasZipMagicBytes("$workdir/real.zip");
$out["magic_ok_on_non_zip"] = PluginZipCache::hasZipMagicBytes("$workdir/not_a_zip.txt");

try {
    PluginZipCache::assertZipMagicBytes("$workdir/not_a_zip.txt");
    $out["assert_threw_on_non_zip"] = false;
} catch (\RuntimeException $e) {
    $out["assert_threw_on_non_zip"] = true;
    $out["assert_message_has_preview"] = str_contains($e->getMessage(), "401 Not privileged");
}

try {
    PluginZipCache::assertZipMagicBytes("$workdir/real.zip");
    $out["assert_ok_on_real_zip"] = true;
} catch (\RuntimeException $e) {
    $out["assert_ok_on_real_zip"] = false;
}

file_put_contents($argv[3], json_encode($out));
' "$AUTOLOAD" "$WORKDIR" "$RESULT_FILE"

if [ ! -f "$RESULT_FILE" ]; then
    echo "  FAIL: PHP harness did not produce output - check for a fatal error above"
    ((FAIL++))
    print_summary
fi

get() { php -r 'echo json_decode(file_get_contents($argv[1]), true)[$argv[2]] ?? "";' "$RESULT_FILE" "$1"; }
getpath() { php -r '
$v = json_decode(file_get_contents($argv[1]), true);
foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; }
echo is_array($v) ? json_encode($v) : $v;
' "$RESULT_FILE" "${@:2}"; }

echo "--- Test: component/version/release/requires/maturity/supported ---"
[ "$(getpath component theme_boost_union)" = "theme_boost_union" ] && { ((PASS++)); } || { echo "  FAIL: component"; ((FAIL++)); }
[ "$(getpath version theme_boost_union)" = "2026042012" ] && { ((PASS++)); } || { echo "  FAIL: version"; ((FAIL++)); }
[ "$(getpath release theme_boost_union)" = "v5.2-r8" ] && { ((PASS++)); } || { echo "  FAIL: release"; ((FAIL++)); }
[ "$(getpath requires theme_boost_union)" = "2026042000" ] && { ((PASS++)); } || { echo "  FAIL: requires"; ((FAIL++)); }
[ "$(getpath maturity theme_boost_union)" = "200" ] && { ((PASS++)); } || { echo "  FAIL: maturity (expected MATURITY_STABLE=200)"; ((FAIL++)); }
[ "$(getpath supported theme_boost_union)" = "[502,502]" ] && { ((PASS++)); } || { echo "  FAIL: supported"; ((FAIL++)); }
echo ""

echo "--- Test: \$plugin->dependencies is parsed (theme_boost >= 2026042000) ---"
[ "$(getpath dependencies theme_boost_union theme_boost)" = "2026042000" ] && { ((PASS++)); } || { echo "  FAIL: dependencies.theme_boost"; ((FAIL++)); }
echo ""

echo "--- Test: a malicious version.php's top-level code is never executed ---"
if [ "$(get evil_side_effect_ran)" = "" ]; then
    ((PASS++))
else
    echo "  FAIL: file_put_contents() side effect ran - version.php was executed!"
    ((FAIL++))
fi
echo ""

echo "--- Test: ANY_VERSION dependency is understood as the string 'any' ---"
[ "$(getpath evil dependencies mod_forum)" = "any" ] && { ((PASS++)); } || { echo "  FAIL: ANY_VERSION dependency not parsed as 'any'"; ((FAIL++)); }
[ "$(getpath evil component)" = "mod_evil" ] && { ((PASS++)); } || { echo "  FAIL: evil version.php's own component"; ((FAIL++)); }
echo ""

echo "--- Test: Moodle core's own top-level \$version/\$release/\$branch shape (float version) ---"
[ "$(getpath version core_version)" = "2026042000" ] && { ((PASS++)); } || { echo "  FAIL: core version (float 2026042000.02 -> int 2026042000)"; ((FAIL++)); }
[ "$(getpath release core_version)" = "5.2" ] && { ((PASS++)); } || { echo "  FAIL: core release"; ((FAIL++)); }
echo ""

echo "--- Test: PluginZipCache::hasZipMagicBytes() ---"
[ "$(get magic_ok_on_real_zip)" = "1" ] && { ((PASS++)); } || { echo "  FAIL: PK-prefixed file not recognised as a zip"; ((FAIL++)); }
[ "$(get magic_ok_on_non_zip)" = "" ] && { ((PASS++)); } || { echo "  FAIL: HTML error body was recognised as a zip"; ((FAIL++)); }
echo ""

echo "--- Test: PluginZipCache::assertZipMagicBytes() throws with a diagnostic preview ---"
[ "$(get assert_threw_on_non_zip)" = "1" ] && { ((PASS++)); } || { echo "  FAIL: did not throw for non-zip content"; ((FAIL++)); }
[ "$(get assert_message_has_preview)" = "1" ] && { ((PASS++)); } || { echo "  FAIL: exception message doesn't include a preview of the response body"; ((FAIL++)); }
[ "$(get assert_ok_on_real_zip)" = "1" ] && { ((PASS++)); } || { echo "  FAIL: threw for a genuinely PK-prefixed file"; ((FAIL++)); }
echo ""

print_summary
