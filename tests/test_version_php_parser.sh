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
# Traverse nested JSON keys in the order given, e.g. `getpath theme_boost_union version`
# reads $out["theme_boost_union"]["version"]. (Note: must use "$@", not "${@:2}" -
# within this function "$@" already starts at getpath's own first argument.)
getpath() { php -r '
$v = json_decode(file_get_contents($argv[1]), true);
foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; }
echo is_array($v) ? json_encode($v) : $v;
' "$RESULT_FILE" "$@"; }

echo "--- Test: component/version/release/requires/maturity/supported ---"
check() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" = "$expected" ]; then
        ((PASS++))
    else
        echo "  FAIL: $description (expected '$expected', got '$actual')"
        ((FAIL++))
    fi
}
check "component" "theme_boost_union" "$(getpath theme_boost_union component)"
check "version" "2026042012" "$(getpath theme_boost_union version)"
check "release" "v5.2-r8" "$(getpath theme_boost_union release)"
check "requires" "2026042000" "$(getpath theme_boost_union requires)"
check "maturity (expected MATURITY_STABLE=200)" "200" "$(getpath theme_boost_union maturity)"
check "supported" "[502,502]" "$(getpath theme_boost_union supported)"
echo ""

echo "--- Test: \$plugin->dependencies is parsed (theme_boost >= 2026042000) ---"
check "dependencies.theme_boost" "2026042000" "$(getpath theme_boost_union dependencies theme_boost)"
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
check "ANY_VERSION dependency not parsed as 'any'" "any" "$(getpath evil dependencies mod_forum)"
check "evil version.php's own component" "mod_evil" "$(getpath evil component)"
echo ""

echo "--- Test: Moodle core's own top-level \$version/\$release/\$branch shape (float version) ---"
check "core version (float 2026042000.02 -> int 2026042000)" "2026042000" "$(getpath core_version version)"
check "core release" "5.2" "$(getpath core_version release)"
echo ""

echo "--- Test: PluginZipCache::hasZipMagicBytes() ---"
check "PK-prefixed file not recognised as a zip" "1" "$(get magic_ok_on_real_zip)"
check "HTML error body was recognised as a zip" "" "$(get magic_ok_on_non_zip)"
echo ""

echo "--- Test: PluginZipCache::assertZipMagicBytes() throws with a diagnostic preview ---"
check "did not throw for non-zip content" "1" "$(get assert_threw_on_non_zip)"
check "exception message doesn't include a preview of the response body" "1" "$(get assert_message_has_preview)"
check "threw for a genuinely PK-prefixed file" "1" "$(get assert_ok_on_real_zip)"
echo ""

print_summary
