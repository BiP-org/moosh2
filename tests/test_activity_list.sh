#!/usr/bin/env bash
#
# Integration test for moosh2 activity:list command
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_activity_list.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 activity:list integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh" || exit 1
echo ""

# Setup: create a few activities of mixed types in course 2.
echo "--- Setup: Create activities for listing tests ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "List Forum 1" forum 2 -o csv
FORUM1_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh activity:create -p "$MOODLE_PATH" --run --name "List Forum 2" --section 2 forum 2 -o csv
FORUM2_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh activity:create -p "$MOODLE_PATH" --run --name "List Assign" --section 2 assign 2 -o csv
ASSIGN_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created cmids: forum1=$FORUM1_CMID forum2=$FORUM2_CMID assign=$ASSIGN_CMID"
echo ""

echo "========== activity:list =========="
echo ""

# -- All activities for a course ---

echo "--- Test: List by course ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 -o csv
assert_output_contains "Header row" "cmid,course,section,module,name,visible,idnumber" "$OUT"
assert_output_contains "Forum 1 listed" "List Forum 1" "$OUT"
assert_output_contains "Assign listed" "List Assign" "$OUT"
echo ""

# -- Filter by module type ---

echo "--- Test: Filter by module=forum ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --module forum -o csv
assert_output_contains "Forum 1 listed" "List Forum 1" "$OUT"
assert_output_contains "Forum 2 listed" "List Forum 2" "$OUT"
assert_output_not_contains "Assign excluded" "List Assign" "$OUT"
echo ""

# -- Filter by section ---

echo "--- Test: Filter by section=2 ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --section 2 -o csv
assert_output_contains "Forum 2 in section 2" "List Forum 2" "$OUT"
assert_output_contains "Assign in section 2" "List Assign" "$OUT"
assert_output_not_contains "Forum 1 not in section 2" "List Forum 1" "$OUT"
echo ""

# -- ID-only output for piping ---

echo "--- Test: --id-only output ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --module forum -i
assert_output_contains "Forum 1 cmid present" "$FORUM1_CMID" "$OUT"
assert_output_contains "Forum 2 cmid present" "$FORUM2_CMID" "$OUT"
line_count=$(printf '%s' "$OUT" | wc -l)
if [ "$line_count" -le 1 ]; then
    echo "  PASS: Output is a single line"
    ((PASS++))
else
    echo "  FAIL: Expected single line, got $line_count lines"
    ((FAIL++))
fi
echo ""

# -- JSON output ---

echo "--- Test: JSON output ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --module forum -o json
assert_output_contains "JSON has cmid" '"cmid"' "$OUT"
assert_output_contains "JSON has module" '"module"' "$OUT"
echo ""

# -- Pipe into activity:mod via --stdin ---

echo "--- Test: Pipe activity:list cmids into activity:mod --stdin ---"
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --module forum -i
CMIDS="$OUT"
run_moosh activity:mod -p "$MOODLE_PATH" --stdin --visible 0 --run -o csv <<< "$CMIDS"
assert_output_contains "Forum 1 visibility output" "$FORUM1_CMID" "$OUT"
assert_output_contains "Forum 2 visibility output" "$FORUM2_CMID" "$OUT"

# Verify both forums are now hidden
run_moosh activity:list -p "$MOODLE_PATH" --course 2 --module forum -o csv
assert_output_contains "Forum 1 hidden" ',"List Forum 1",0,' "$OUT"
assert_output_contains "Forum 2 hidden" ',"List Forum 2",0,' "$OUT"
echo ""

# -- Help ---

echo "--- Test: activity:list help ---"
run_moosh activity:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List course module activities" "$OUT"
assert_output_contains "Help shows --course" "--course" "$OUT"
assert_output_contains "Help shows --section" "--section" "$OUT"
assert_output_contains "Help shows --module" "--module" "$OUT"
assert_output_contains "Help shows --id-only" "--id-only" "$OUT"
assert_output_contains "Help shows --stdin" "--stdin" "$OUT"
echo ""

print_summary
