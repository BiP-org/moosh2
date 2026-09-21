#!/usr/bin/env bash
#
# Integration test for moosh2's zip-cache pruning (--keep-versions on
# plugin:list-update, backed by PluginZipCache::pruneComponent()), ported
# from install_plugins.php's cache-pruning behaviour.
#
# Requires a working Moodle 5.2 installation at $MOODLE_DIR (see common.sh).
#
# The counting/corruption logic of pruneComponent() itself is tested
# directly via PHP (deterministic, no network, no dependency on
# plugin:list-update's checksum-reconciliation short-circuit behaviour -
# see the comment above the first such test below for why that short-
# circuit makes going through the CLI command an unreliable way to force a
# second prune to fire). Only one real network download (of the actual
# current mod_attendance) is used, to confirm the end-to-end
# plugin:list-update --keep-versions wiring itself works, not just the
# service method in isolation.
#
# Usage: bash tests/test_plugin_list_update_cache_prune.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 zip-cache pruning integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "--- Test: Help mentions --keep-versions ---"
run_moosh plugin:list-update --help
assert_output_contains "Help shows --keep-versions" "--keep-versions" "$OUT"
echo ""

# Isolate the cache for this whole file - never touch the real
# ~/.moosh/moodleplugins, and never let a previous test's cached files
# affect what pruning sees (or get pruned away by this file's own runs).
export MOOSH_CACHE_DIR
MOOSH_CACHE_DIR=$(mktemp -d)

# Build a minimal, structurally-valid (ZipArchive::CHECKCONS-passing) zip
# with the given mtime, so pruneComponent()'s isValidZip() check accepts it
# as a real cache entry rather than deleting it as corrupt.
make_valid_zip() {
    local path="$1" mtime="$2"
    "$PHP" -r '
        $zip = new ZipArchive();
        $zip->open($argv[1], ZipArchive::CREATE);
        $zip->addFromString("version.php", "<?php // synthetic test fixture, not a real plugin");
        $zip->close();
    ' "$path"
    touch -d "@$mtime" "$path"
}

NOW=$(date +%s)

# ── Direct PluginZipCache::pruneComponent() tests ──────────────────
#
# Why direct, not via `plugin:list-update --run --keep-versions=N`: after
# the *first* real run below pins version + checksum for mod_attendance,
# a *second* run with nothing changed short-circuits inside
# reconcileChecksum() before ever calling downloadPluginZip() again (see
# that method - "$existing !== null && !$forceRecompute" skips entirely).
# That means a second CLI invocation wouldn't reliably re-trigger pruning
# at all, making the CLI an unreliable way to test the "keep only N
# newest" counting logic specifically. Calling the service directly, the
# same way downloadPluginZip() does internally, tests exactly that logic
# deterministically.

echo "--- Test: pruneComponent() keeps only the N newest valid zips, deletes older + corrupt ones ---"
mkdir -p "$MOOSH_CACHE_DIR"
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020010100.zip" $((NOW - 500))
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020020100.zip" $((NOW - 400))
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020030100.zip" $((NOW - 300))
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020040100.zip" $((NOW - 200))
echo "not a zip" > "$MOOSH_CACHE_DIR/mod_attendance-2020050100.zip"
# An unrelated component's cache entries must never be touched by pruning
# scoped to mod_attendance.
make_valid_zip "$MOOSH_CACHE_DIR/mod_board-2020010100.zip" $((NOW - 500))
make_valid_zip "$MOOSH_CACHE_DIR/mod_board-2020020100.zip" $((NOW - 400))

"$PHP" -r '
    require $argv[1] . "/vendor/autoload.php";
    \Moosh2\Service\PluginZipCache::pruneComponent("mod_attendance", 2);
' "$REPO_ROOT"

REMAINING=$(ls "$MOOSH_CACHE_DIR"/mod_attendance-*.zip 2>/dev/null | wc -l)
if [ "$REMAINING" -eq 2 ]; then
    echo "  PASS: exactly 2 mod_attendance zips remain after pruneComponent(..., 2)"
    ((PASS++))
else
    echo "  FAIL: expected exactly 2 mod_attendance zips remaining, found $REMAINING"
    ((FAIL++))
fi
if [ -f "$MOOSH_CACHE_DIR/mod_attendance-2020040100.zip" ] && [ -f "$MOOSH_CACHE_DIR/mod_attendance-2020030100.zip" ]; then
    echo "  PASS: the 2 newest zips (by mtime) were kept"
    ((PASS++))
else
    echo "  FAIL: the 2 newest zips were not the ones kept"
    ((FAIL++))
fi
if [ ! -f "$MOOSH_CACHE_DIR/mod_attendance-2020010100.zip" ] && [ ! -f "$MOOSH_CACHE_DIR/mod_attendance-2020020100.zip" ]; then
    echo "  PASS: the 2 oldest zips were deleted"
    ((PASS++))
else
    echo "  FAIL: an oldest zip survived pruning"
    ((FAIL++))
fi
if [ ! -f "$MOOSH_CACHE_DIR/mod_attendance-2020050100.zip" ]; then
    echo "  PASS: the corrupt zip was deleted"
    ((PASS++))
else
    echo "  FAIL: the corrupt zip survived"
    ((FAIL++))
fi
BOARD_REMAINING=$(ls "$MOOSH_CACHE_DIR"/mod_board-*.zip 2>/dev/null | wc -l)
if [ "$BOARD_REMAINING" -eq 2 ]; then
    echo "  PASS: an unrelated component's cache entries were left untouched"
    ((PASS++))
else
    echo "  FAIL: pruning mod_attendance affected mod_board's cache entries (found $BOARD_REMAINING, expected 2)"
    ((FAIL++))
fi
echo ""

echo "--- Test: pruneComponent() with keep=0 still deletes corrupt entries, keeps every valid one ---"
rm -rf "$MOOSH_CACHE_DIR"/mod_attendance-*.zip
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020010100.zip" $((NOW - 500))
make_valid_zip "$MOOSH_CACHE_DIR/mod_attendance-2020020100.zip" $((NOW - 400))
echo "not a zip" > "$MOOSH_CACHE_DIR/mod_attendance-2020030100.zip"

"$PHP" -r '
    require $argv[1] . "/vendor/autoload.php";
    \Moosh2\Service\PluginZipCache::pruneComponent("mod_attendance", 0);
' "$REPO_ROOT"

VALID_REMAINING=$(ls "$MOOSH_CACHE_DIR"/mod_attendance-2020010100.zip "$MOOSH_CACHE_DIR"/mod_attendance-2020020100.zip 2>/dev/null | wc -l)
if [ "$VALID_REMAINING" -eq 2 ]; then
    echo "  PASS: keep=0 disables count-based pruning - both valid zips kept"
    ((PASS++))
else
    echo "  FAIL: keep=0 deleted a valid zip it should have left alone"
    ((FAIL++))
fi
if [ ! -f "$MOOSH_CACHE_DIR/mod_attendance-2020030100.zip" ]; then
    echo "  PASS: keep=0 still deletes the corrupt entry"
    ((PASS++))
else
    echo "  FAIL: keep=0 left the corrupt entry in place"
    ((FAIL++))
fi
rm -rf "$MOOSH_CACHE_DIR"/mod_attendance-*.zip "$MOOSH_CACHE_DIR"/mod_board-*.zip
echo ""

# ── End-to-end wiring test: one real download through plugin:list-update ──

echo "--- Test: a real plugin:list-update --run --keep-versions=0 run downloads and prunes corrupt entries ---"
echo "not a zip" > "$MOOSH_CACHE_DIR/mod_attendance-1111111111.zip"
LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/mod_attendance"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run --keep-versions=0 mod_attendance
assert_output_not_empty "list-update produced output" "$OUT"
if [ ! -f "$MOOSH_CACHE_DIR/mod_attendance-1111111111.zip" ]; then
    echo "  PASS: pre-existing corrupt cache entry was deleted by the real run"
    ((PASS++))
else
    echo "  FAIL: pre-existing corrupt cache entry survived a real plugin:list-update --run"
    ((FAIL++))
fi
REAL_ZIP=$(ls "$MOOSH_CACHE_DIR"/mod_attendance-*.zip 2>/dev/null | head -n1)
if [ -n "$REAL_ZIP" ]; then
    echo "  PASS: the real download was cached ($(basename "$REAL_ZIP"))"
    ((PASS++))
else
    echo "  FAIL: no cache entry found after a real download"
    ((FAIL++))
fi
echo ""

echo "--- Test: dry run (no --run) does not touch the cache ---"
BEFORE=$(ls "$MOOSH_CACHE_DIR"/mod_attendance-*.zip 2>/dev/null | wc -l)
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --keep-versions=1 mod_attendance
AFTER=$(ls "$MOOSH_CACHE_DIR"/mod_attendance-*.zip 2>/dev/null | wc -l)
if [ "$BEFORE" -eq "$AFTER" ]; then
    echo "  PASS: dry run left the cache untouched ($BEFORE files before and after)"
    ((PASS++))
else
    echo "  FAIL: dry run changed the cache file count ($BEFORE -> $AFTER)"
    ((FAIL++))
fi
echo ""

echo "--- Cleaning up ---"
rm -rf "$LISTDIR" "$MOOSH_CACHE_DIR"
unset MOOSH_CACHE_DIR
echo ""

print_summary
