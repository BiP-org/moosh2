#!/usr/bin/env bash
#
# Tests for Moosh2\Service\PluginZipCache::isValidZip() / store() / fetch().
#
# Regression test for plugin zips served by marketplace.moodle.com (seen
# with mod_geogebra 2026092200) that unzip/Python/PHP's lenient
# ZipArchive::open() read fine, but that ZipArchive::CHECKCONS rejects with
# ER_INCONS: every entry has the "data descriptor" flag (general purpose
# bit 3) set, yet the local file header carries the real uncompressed size
# while CRC and compressed size are 0. plugin:list-update used to abort
# with "Downloaded file ... is not a valid, non-empty zip archive." for
# such a package even though nothing was wrong with the download.
#
# Like test_version_php_parser.sh this doesn't touch a Moodle install or
# the network - it exercises the PHP service directly, and builds the
# quirky zip byte-by-byte so no binary fixture has to be checked in.
#
# Usage: bash tests/test_plugin_zip_cache_validation.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 PluginZipCache::isValidZip() tests ==="
echo ""

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
AUTOLOAD="$REPO_DIR/vendor/autoload.php"

if [ ! -f "$AUTOLOAD" ]; then
    echo "ERROR: $AUTOLOAD not found - run 'composer install' first."
    exit 1
fi

WORKDIR=$(mktemp -d)
trap 'rm -rf "$WORKDIR"; _moosh_test_release_lock' EXIT

# isValidZip() only runs the extra `unzip -tqq` integrity pass when the
# unzip binary exists; the "corrupt data is rejected" case depends on it.
if command -v unzip >/dev/null 2>&1; then
    HAVE_UNZIP=1
else
    HAVE_UNZIP=0
fi

RESULT_FILE="$WORKDIR/result.json"
MOOSH_CACHE_DIR="$WORKDIR/cache" "$PHP" -r '
require $argv[1];
use Moosh2\Service\PluginZipCache;

$workdir = $argv[2];
$out = [];

/**
 * Build a zip shaped like the marketplace one: data-descriptor flag set,
 * local header with the real uncompressed size but zero CRC/compressed
 * size, real values only in the data descriptor and central directory.
 */
$buildQuirkyZip = function (string $path, array $files): void {
    $data = "";
    $central = "";
    foreach ($files as $name => $content) {
        $offset = strlen($data);
        $crc = crc32($content);
        $comp = gzdeflate($content);
        $csize = strlen($comp);
        $usize = strlen($content);
        $data .= pack("VvvvvvVVVvv", 0x04034b50, 20, 0x0008, 8, 0x6000, 0x5a56, 0, 0, $usize, strlen($name), 0)
            . $name . $comp
            . pack("VVVV", 0x08074b50, $crc, $csize, $usize);
        $central .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0x0008, 8, 0x6000, 0x5a56, $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset)
            . $name;
    }
    $count = count($files);
    $data .= $central . pack("VvvvvVVv", 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
    file_put_contents($path, $data);
};

$text = str_repeat("The quick brown fox jumps over the lazy dog. 0123456789\n", 40);
$files = [
    "geogebra/version.php" => "<?php\n\$plugin->component = \x27mod_geogebra\x27;\n\$plugin->version = 2026092200;\n",
    "geogebra/lib.php" => "<?php\n" . $text,
];

// 1. A normal, well-formed zip.
$zip = new \ZipArchive();
$zip->open("$workdir/normal.zip", \ZipArchive::CREATE);
foreach ($files as $name => $content) {
    $zip->addFromString($name, $content);
}
$zip->close();
$out["normal_valid"] = PluginZipCache::isValidZip("$workdir/normal.zip");

// 2. The marketplace-style zip. First record whether this libzip actually
//    rejects it under CHECKCONS (it must, or the fallback is not being
//    exercised at all), then check what isValidZip() makes of it.
$buildQuirkyZip("$workdir/quirky.zip", $files);
$probe = new \ZipArchive();
$out["quirky_checkcons_result"] = $probe->open("$workdir/quirky.zip", \ZipArchive::CHECKCONS);
$probe = new \ZipArchive();
$out["quirky_lenient_ok"] = $probe->open("$workdir/quirky.zip") === true;
$out["quirky_valid"] = PluginZipCache::isValidZip("$workdir/quirky.zip");

// 3. The same zip still goes through the rest of the pipeline.
try {
    PluginZipCache::assertZipComponent("$workdir/quirky.zip", "mod_geogebra");
    $out["quirky_component_ok"] = true;
} catch (\RuntimeException $e) {
    $out["quirky_component_ok"] = false;
}

// 4. ...and survives a trip through the real cache (store() then fetch()).
$out["quirky_stored"] = PluginZipCache::store("mod_geogebra", "2026092200", "$workdir/quirky.zip");
$out["quirky_fetched"] = PluginZipCache::fetch("mod_geogebra", "2026092200", "$workdir/fetched.zip")
    && filesize("$workdir/fetched.zip") === filesize("$workdir/quirky.zip");

// 5. A quirky zip with a flipped byte inside the compressed data of its
//    first entry must still be rejected (needs the unzip binary).
$bytes = file_get_contents("$workdir/quirky.zip");
$flip = 30 + strlen("geogebra/version.php") + 10;
$bytes[$flip] = chr(ord($bytes[$flip]) ^ 0xFF);
file_put_contents("$workdir/quirky_corrupt.zip", $bytes);
$out["corrupt_valid"] = PluginZipCache::isValidZip("$workdir/quirky_corrupt.zip");

// 6. Truncated download (loses the central directory).
file_put_contents("$workdir/truncated.zip", substr(file_get_contents("$workdir/quirky.zip"), 0, 120));
$out["truncated_valid"] = PluginZipCache::isValidZip("$workdir/truncated.zip");

// 7. Things that are not zips at all.
file_put_contents("$workdir/error_page.zip", "<html><body>401 Not privileged</body></html>");
$out["html_valid"] = PluginZipCache::isValidZip("$workdir/error_page.zip");
file_put_contents("$workdir/empty.zip", "");
$out["empty_valid"] = PluginZipCache::isValidZip("$workdir/empty.zip");
file_put_contents("$workdir/pk_junk.zip", "PK" . str_repeat("\x01", 200));
$out["pk_junk_valid"] = PluginZipCache::isValidZip("$workdir/pk_junk.zip");
$out["missing_valid"] = PluginZipCache::isValidZip("$workdir/does_not_exist.zip");

// 8. store() must keep refusing anything that fails the check.
$out["corrupt_stored"] = PluginZipCache::store("mod_broken", "1", "$workdir/truncated.zip");

file_put_contents($argv[3], json_encode($out));
' "$AUTOLOAD" "$WORKDIR" "$RESULT_FILE"

if [ ! -f "$RESULT_FILE" ]; then
    echo "  FAIL: PHP harness did not produce output - check for a fatal error above"
    ((FAIL++))
    print_summary
fi

get() { "$PHP" -r '$v = json_decode(file_get_contents($argv[1]), true)[$argv[2]] ?? null; echo $v === true ? "1" : ($v === false ? "0" : $v);' "$RESULT_FILE" "$1"; }

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

echo "--- Test: a normal zip is valid ---"
check "normal zip rejected" "1" "$(get normal_valid)"
echo ""

echo "--- Test: marketplace-style zip (data descriptor + zeroed local CRC/csize) ---"
if [ "$(get quirky_lenient_ok)" != "1" ]; then
    echo "  FAIL: fixture is not even readable leniently - the test zip builder is broken"
    ((FAIL++))
fi
if [ "$(get quirky_checkcons_result)" = "1" ]; then
    # ZipArchive::open() returns true (rendered "1") when it accepted the file.
    echo "  NOTE: this libzip accepts the fixture under CHECKCONS, so the lenient fallback is not exercised here"
else
    ((PASS++))
fi
check "isValidZip() rejected a zip that unzip and a lenient open read fine" "1" "$(get quirky_valid)"
check "assertZipComponent() failed on the same zip" "1" "$(get quirky_component_ok)"
check "store() refused to cache the zip" "1" "$(get quirky_stored)"
check "fetch() did not serve the cached copy back intact" "1" "$(get quirky_fetched)"
echo ""

echo "--- Test: damaged archives are still rejected ---"
if [ "$HAVE_UNZIP" = "1" ]; then
    check "flipped byte in compressed data was accepted" "0" "$(get corrupt_valid)"
else
    echo "  SKIP: unzip binary not installed, cannot verify per-entry integrity"
fi
check "truncated download (no central directory) was accepted" "0" "$(get truncated_valid)"
check "store() cached a truncated zip" "0" "$(get corrupt_stored)"
echo ""

echo "--- Test: things that are not zips are rejected ---"
check "HTML error page was accepted" "0" "$(get html_valid)"
check "empty file was accepted" "0" "$(get empty_valid)"
check "PK + junk was accepted" "0" "$(get pk_junk_valid)"
check "missing file was accepted" "0" "$(get missing_valid)"
echo ""

print_summary
