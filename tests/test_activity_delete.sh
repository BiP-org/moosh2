#!/usr/bin/env bash
#
# Integration test for moosh2 activity:delete command
# Requires a working Moodle installation
#
# Usage: bash tests/test_activity_delete.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 activity:delete integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh" || exit 1
echo ""

# Create activities to delete
echo "--- Setup: Create activities for deletion tests ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Forum To Delete" forum 2 -o csv
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created forum cmid=$FORUM_CMID"

run_moosh activity:create -p "$MOODLE_PATH" --run --name "Assign To Delete" assign 2 -o csv
ASSIGN_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created assign cmid=$ASSIGN_CMID"

run_moosh activity:create -p "$MOODLE_PATH" --run --name "Page To Delete" page 2 -o csv
PAGE_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created page cmid=$PAGE_CMID"
echo ""

echo "========== activity:delete =========="
echo ""

# -- Dry run ---

echo "--- Test: activity:delete dry run ---"
run_moosh activity:delete -p "$MOODLE_PATH" $FORUM_CMID
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows cmid" "cmid=$FORUM_CMID" "$OUT"
assert_output_contains "Shows type" "forum" "$OUT"
echo ""

# -- Delete single activity ---

echo "--- Test: Delete single activity ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run $FORUM_CMID
assert_output_contains "Deleted message" "Deleted" "$OUT"
assert_output_contains "Shows forum type" "forum" "$OUT"
echo ""

# -- Delete multiple activities ---

echo "--- Test: Delete multiple activities ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run $ASSIGN_CMID $PAGE_CMID
assert_output_contains "First deleted" "Deleted" "$OUT"
echo ""

# -- Invalid cmid ---

echo "--- Test: Delete invalid cmid ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for invalid cmid" 1 "$EXIT_CODE"
assert_output_contains "Error for invalid cmid" "not found" "$OUT"
echo ""

# -- Help ---

echo "--- Test: activity:delete help ---"
run_moosh activity:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete activities" "$OUT"
assert_output_contains "Help shows cmid" "cmid" "$OUT"
echo ""

print_summary
