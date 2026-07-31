#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-update
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_list_update.sh
#

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
echo ""

LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/block_progress" "$LISTDIR/mod_attendance"

echo "--- Test: Dry run does not write a version file ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Shows dry run banner" "Dry run" "$OUT"
if [ ! -f "$LISTDIR/block_progress/version" ]; then
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
for component in block_progress mod_attendance; do
    VERSION_FILE="$LISTDIR/$component/version"
    if [ ! -f "$VERSION_FILE" ]; then
        echo "  FAIL: $VERSION_FILE was not created"
        ((FAIL++))
        continue
    fi
    VERSION=$(cat "$VERSION_FILE")
    # Moodle plugin versions are date-stamped ints, eg. 2024010100 - just
    # confirm it looks like one rather than asserting an exact value, since
    # the actual latest version drifts over time.
    if [[ "$VERSION" =~ ^20[0-9]{8}$ ]]; then
        echo "  PASS: $component/version looks like a real version ($VERSION)"
        ((PASS++))
    else
        echo "  FAIL: $component/version content doesn't look like a version: '$VERSION'"
        ((FAIL++))
    fi
done
echo ""

echo "--- Test: Re-running --run reports already up to date ---"
FIRST_VERSION=$(cat "$LISTDIR/block_progress/version")
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
SECOND_VERSION=$(cat "$LISTDIR/block_progress/version")
if [ "$FIRST_VERSION" = "$SECOND_VERSION" ]; then
    echo "  PASS: version file unchanged on a second, back-to-back --run"
    ((PASS++))
else
    echo "  FAIL: version changed between two immediate runs ($FIRST_VERSION -> $SECOND_VERSION)"
    ((FAIL++))
fi
echo ""

echo "--- Test: Filtering by positional plugin name only updates that one ---"
rm -f "$LISTDIR/block_progress/version" "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run block_progress
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
if [ -f "$LISTDIR/block_progress/version" ] && [ ! -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: only block_progress was updated"
    ((PASS++))
else
    echo "  FAIL: expected only block_progress/version to exist"
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
