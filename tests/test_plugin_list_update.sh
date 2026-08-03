#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-update
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_list_update.sh
#
# Note: only mod_attendance is used as a "real" plugin here. Not every
# plugin on moodle.org has a version explicitly marked compatible with
# every Moodle release - block_progress, for example, does not reliably
# resolve a version for --moodle-version=5.1 the way mod_attendance does,
# even though it installs fine via `plugin:install --force` (a different
# code path that skips compatibility matching entirely). mod_attendance is
# actively maintained and has consistently resolved in CI, so it's the only
# plugin this file relies on actually existing on moodle.org.

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-update integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:list-update --help
assert_output_contains "Help description" "declarative plugin list" "$OUT"
assert_output_contains "Help shows --directory" "--directory" "$OUT"
assert_output_contains "Help shows --moodle-version" "--moodle-version" "$OUT"
assert_output_contains "Help shows --run" "--run" "$OUT"
assert_output_contains "Help shows --token" "--token" "$OUT"
echo ""

LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/mod_attendance"

echo "--- Test: Dry run does not write a version file ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Shows dry run banner" "Dry run" "$OUT"
if [ ! -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: No version file written without --run"
    ((PASS++))
else
    echo "  FAIL: version file was written despite dry run"
    ((FAIL++))
fi
echo ""

echo "--- Test: --run writes a plausible version number ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0 for --run" 0 "$EC"
VERSION_FILE="$LISTDIR/mod_attendance/version"
if [ ! -f "$VERSION_FILE" ]; then
    echo "  FAIL: $VERSION_FILE was not created"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
else
    VERSION=$(cat "$VERSION_FILE")
    # Moodle plugin versions are date-stamped ints, eg. 2024010100 - just
    # confirm it looks like one rather than asserting an exact value, since
    # the actual latest version drifts over time.
    if [[ "$VERSION" =~ ^20[0-9]{8}$ ]]; then
        echo "  PASS: mod_attendance/version looks like a real version ($VERSION)"
        ((PASS++))
    else
        echo "  FAIL: mod_attendance/version content doesn't look like a version: '$VERSION'"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: Re-running --run reports already up to date ---"
FIRST_VERSION=$(cat "$LISTDIR/mod_attendance/version")
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
SECOND_VERSION=$(cat "$LISTDIR/mod_attendance/version")
if [ "$FIRST_VERSION" = "$SECOND_VERSION" ]; then
    echo "  PASS: version file unchanged on a second, back-to-back --run"
    ((PASS++))
else
    echo "  FAIL: version changed between two immediate runs ($FIRST_VERSION -> $SECOND_VERSION)"
    ((FAIL++))
fi
echo ""

echo "--- Test: Filtering by positional plugin name only updates that one ---"
# Uses a pre-seeded sentinel in an unrelated "control" directory rather than
# a second real moodle.org plugin, so this doesn't depend on that second
# plugin also happening to resolve a compatible version (see note above).
rm -f "$LISTDIR/mod_attendance/version"
mkdir -p "$LISTDIR/zzz_filter_control"
echo "9999999999" > "$LISTDIR/zzz_filter_control/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
CONTROL_VALUE=$(cat "$LISTDIR/zzz_filter_control/version")
if [ -f "$LISTDIR/mod_attendance/version" ] && [ "$CONTROL_VALUE" = "9999999999" ]; then
    echo "  PASS: mod_attendance was updated, zzz_filter_control was left untouched"
    ((PASS++))
else
    echo "  FAIL: expected only mod_attendance/version to be written (control now: '$CONTROL_VALUE')"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
fi
rm -rf "$LISTDIR/zzz_filter_control"
echo ""

echo "--- Test: --token doesn't affect a normal (non-Marketplace) update ---"
# The token is only ever sent as a Bearer header to marketplace.moodle.com
# (see PluginApiClient::isMarketplaceHost()) - download.moodle.org, which
# is all this test actually talks to, should behave identically whether or
# not one is supplied. This guards against the option breaking normal
# usage, e.g. via a parsing mistake or the host check being backwards.
rm -f "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run --token=dummy-test-token mod_attendance
EC=$?
assert_exit_code "Exit code 0 with --token set" 0 "$EC"
if [ -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: --token didn't interfere with a normal update"
    ((PASS++))
else
    echo "  FAIL: version file missing when --token was supplied"
    ((FAIL++))
fi
echo ""

echo "--- Test: MOODLE_MARKETPLACE_TOKEN env var behaves the same way ---"
rm -f "$LISTDIR/mod_attendance/version"
export MOODLE_MARKETPLACE_TOKEN="dummy-env-token"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
EC=$?
unset MOODLE_MARKETPLACE_TOKEN
assert_exit_code "Exit code 0 with MOODLE_MARKETPLACE_TOKEN set" 0 "$EC"
if [ -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: MOODLE_MARKETPLACE_TOKEN didn't interfere with a normal update"
    ((PASS++))
else
    echo "  FAIL: version file missing when MOODLE_MARKETPLACE_TOKEN was set"
    ((FAIL++))
fi
echo ""

echo "--- Test: Unknown component directory reports an error ---"
mkdir -p "$LISTDIR/totally_not_a_real_plugin_xyz"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run totally_not_a_real_plugin_xyz
EC=$?
assert_exit_code "Nonzero exit for unknown plugin" 1 "$EC"
assert_output_contains "Error mentions the component" "totally_not_a_real_plugin_xyz" "$OUT"
rm -rf "$LISTDIR/totally_not_a_real_plugin_xyz"
echo ""

echo "--- Test: Nonexistent --directory ---"
run_moosh plugin:list-update --directory=/tmp/does_not_exist_$$_listupdate --moodle-version=5.1
EC=$?
assert_exit_code "Exit code nonzero" 1 "$EC"
assert_output_contains "Directory not found error" "Directory not found" "$OUT"
echo ""

rm -rf "$LISTDIR"
print_summary
