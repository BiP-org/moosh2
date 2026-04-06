#!/usr/bin/env bash
#
# Integration test for moosh2 activity:mod command
# Requires a working Moodle installation
#
# Usage: bash tests/test_activity_mod.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 activity:mod integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh" || exit 1
echo ""

# Create a forum activity to modify
echo "--- Setup: Create forum for modification tests ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Discussion Forum" forum 2 -o csv
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created forum cmid=$FORUM_CMID"
echo ""

echo "========== activity:mod =========="
echo ""

# -- Dry run ---

echo "--- Test: activity:mod dry run ---"
run_moosh activity:mod -p "$MOODLE_PATH" --name "New Name" $FORUM_CMID
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows name change" "name:" "$OUT"
echo ""

# -- Rename activity ---

echo "--- Test: Rename activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Renamed Forum" $FORUM_CMID -o csv
assert_output_contains "Shows renamed name" "Renamed Forum" "$OUT"
echo ""

# -- Change visibility ---

echo "--- Test: Hide activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --visible 0 $FORUM_CMID -o csv
assert_output_contains "Shows visible 0" ",0," "$OUT"
echo ""

echo "--- Test: Show activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --visible 1 $FORUM_CMID -o csv
assert_output_contains "Shows visible 1" ",1," "$OUT"
echo ""

# -- Set idnumber ---

echo "--- Test: Set idnumber ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --idnumber "FORUM001" $FORUM_CMID -o csv
assert_output_contains "Shows idnumber" "FORUM001" "$OUT"
echo ""

# -- Move to different section ---

echo "--- Test: Move to section 3 ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --section 3 $FORUM_CMID -o csv
assert_output_contains "Moved to section 3" ",3," "$OUT"
echo ""

echo "--- Test: Move back to section 1 ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --section 1 $FORUM_CMID -o csv
assert_output_contains "Moved to section 1" ",1," "$OUT"
echo ""

# -- Multiple changes at once ---

echo "--- Test: Multiple changes at once ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Final Forum" --visible 0 --idnumber "FIN001" $FORUM_CMID -o json
assert_output_contains "JSON name changed" '"Final Forum"' "$OUT"
assert_output_contains "JSON idnumber set" '"FIN001"' "$OUT"
echo ""

# -- --set option: modify module properties ---

echo "--- Test: --set single property ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run -S type=single $FORUM_CMID -o csv
assert_output_contains "Shows updated forum" "Final Forum" "$OUT"

echo "--- Test: Verify --set type=single via activity:info ---"
run_moosh activity:info -p "$MOODLE_PATH" $FORUM_CMID -o json
assert_output_contains "Forum type is single" '"single"' "$OUT"
echo ""

echo "--- Test: --set multiple properties ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --set type=general --set assessed=1 $FORUM_CMID -o csv
assert_output_contains "Shows forum after multi-set" "Final Forum" "$OUT"

echo "--- Test: Verify --set type=general and assessed=1 via activity:info ---"
run_moosh activity:info -p "$MOODLE_PATH" $FORUM_CMID -o json
assert_output_contains "Forum type is general" '"general"' "$OUT"
assert_output_contains "Forum assessed is 1" '"1"' "$OUT"
echo ""

echo "--- Test: --set dry run ---"
run_moosh activity:mod -p "$MOODLE_PATH" -S type=blog $FORUM_CMID
assert_output_contains "Shows dry run for --set" "Dry run" "$OUT"
assert_output_contains "Shows --set change" "type:" "$OUT"
echo ""

echo "--- Test: --set invalid format ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run -S badformat $FORUM_CMID
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad --set" 1 "$EXIT_CODE"
assert_output_contains "Error for bad --set" "Invalid --set format" "$OUT"
echo ""

echo "--- Test: --set combined with --name ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Set And Name Forum" -S type=single $FORUM_CMID -o csv
assert_output_contains "Shows new name with --set" "Set And Name Forum" "$OUT"

echo "--- Test: Verify --name and --set type=single via activity:info ---"
run_moosh activity:info -p "$MOODLE_PATH" $FORUM_CMID -o json
assert_output_contains "Name is Set And Name Forum" '"Set And Name Forum"' "$OUT"
assert_output_contains "Forum type is single after combined" '"single"' "$OUT"
echo ""

# -- No modification specified ---

echo "--- Test: No modification specified ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run $FORUM_CMID
EXIT_CODE=$?
assert_exit_code "Exit code 1 for no modification" 1 "$EXIT_CODE"
assert_output_contains "Error for no modification" "No modifications specified" "$OUT"
echo ""

# -- Invalid cmid ---

echo "--- Test: Invalid cmid ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "X" 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for invalid cmid" 1 "$EXIT_CODE"
assert_output_contains "Error for invalid cmid" "not found" "$OUT"
echo ""

# -- Help ---

echo "--- Test: activity:mod help ---"
run_moosh activity:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify an activity" "$OUT"
assert_output_contains "Help shows --name" "--name" "$OUT"
assert_output_contains "Help shows --visible" "--visible" "$OUT"
assert_output_contains "Help shows --section" "--section" "$OUT"
assert_output_contains "Help shows --before" "--before" "$OUT"
assert_output_contains "Help shows --set" "--set" "$OUT"
echo ""

print_summary
