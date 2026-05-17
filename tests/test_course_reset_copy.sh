#!/usr/bin/env bash
#
# Integration test for moosh2 course:reset, course:copy
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_course_reset_copy.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 course:reset/copy integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
# course:reset
# ═══════════════════════════════════════════════════════════════════

echo "========== course:reset =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:reset -p "$MOODLE_PATH" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows course" "algebrafundamentals" "$OUT"
assert_output_contains "Shows reset_events" "reset_events" "$OUT"
assert_output_contains "Shows reset_gradebook" "reset_gradebook_grades" "$OUT"
echo ""

echo "--- Test: Reset with --run ---"
run_moosh course:reset -p "$MOODLE_PATH" --run 2
assert_output_contains "Shows reset complete" "has been reset" "$OUT"
echo ""

echo "--- Test: Custom settings ---"
run_moosh course:reset -p "$MOODLE_PATH" -s "reset_events=0 reset_notes=0" 2
assert_output_contains "Shows events=0" "reset_events = 0" "$OUT"
assert_output_contains "Shows notes=0" "reset_notes = 0" "$OUT"
echo ""

echo "--- Test: Nonexistent course ---"
run_moosh course:reset -p "$MOODLE_PATH" 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad course" 1 "$EXIT_CODE"
assert_output_contains "Not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:reset -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Reset course data" "$OUT"
assert_output_contains "Help shows --settings" "--settings" "$OUT"
echo ""

echo "--- Test: Date-shift regression — assignment duedate shifts by correct delta ---"
echo "    (moosh1 bug: duedate became ~2070 instead of 1 year later)"
run_moosh course:create -p "$MOODLE_PATH" --run date_shift_test -o csv
DATE_COURSEID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created course id=$DATE_COURSEID"
run_moosh course:mod "$DATE_COURSEID" --startdate "2019-09-28" -p "$MOODLE_PATH" --run
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Date Shift Assign" assign "$DATE_COURSEID" -o csv
DATE_ASSIGN_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created assign cmid=$DATE_ASSIGN_CMID"
run_moosh sql:select -p "$MOODLE_PATH" "SELECT startdate FROM {course} WHERE id = $DATE_COURSEID" -o csv
OLD_START=$(echo "$OUT" | tail -1 | tr -d '\r')
OLD_DUEDATE=$((OLD_START + 43200))
run_moosh activity:mod -p "$MOODLE_PATH" --run --set "duedate=$OLD_DUEDATE" "$DATE_ASSIGN_CMID"
echo "  old_start=$OLD_START  old_duedate=$OLD_DUEDATE (startdate + 12h)"
YEAR_DELTA=31536000
NEW_START=$((OLD_START + YEAR_DELTA))
EXPECTED_DUEDATE=$((OLD_DUEDATE + YEAR_DELTA))
BUGGY_DUEDATE=$((OLD_DUEDATE + NEW_START))
run_moosh course:reset -p "$MOODLE_PATH" --run -s "reset_start_date=$NEW_START" "$DATE_COURSEID"
assert_output_contains "Reset with new start date succeeds" "has been reset" "$OUT"
run_moosh sql:select -p "$MOODLE_PATH" "SELECT duedate FROM {assign} WHERE course = $DATE_COURSEID" -o csv
ACTUAL_DUEDATE=$(echo "$OUT" | tail -1 | tr -d '\r')
echo "  new_start=$NEW_START  expected=$EXPECTED_DUEDATE  actual=$ACTUAL_DUEDATE  buggy=$BUGGY_DUEDATE"
assert_output_contains "Assignment duedate shifts by the correct 1-year delta" "$EXPECTED_DUEDATE" "$ACTUAL_DUEDATE"
assert_output_not_contains "Assignment duedate is not the inflated 2070 buggy value" "$BUGGY_DUEDATE" "$ACTUAL_DUEDATE"
echo ""


# ═══════════════════════════════════════════════════════════════════
# course:copy
# ═══════════════════════════════════════════════════════════════════

echo "========== course:copy =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:copy -p "$MOODLE_PATH" 2 "Test Copy" test_copy_course 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows source" "algebrafundamentals" "$OUT"
assert_output_contains "Shows new name" "Test Copy" "$OUT"
assert_output_contains "Shows new shortname" "test_copy_course" "$OUT"
echo ""

echo "--- Test: Copy with --run ---"
run_moosh course:copy -p "$MOODLE_PATH" --run 2 "Test Copy" test_copy_course 2
assert_output_contains "Shows queued" "task queued" "$OUT"
assert_output_contains "Shows shortnames" "test_copy_course" "$OUT"
echo ""

echo "--- Test: Duplicate shortname ---"
run_moosh course:copy -p "$MOODLE_PATH" 2 "Another Copy" algebrafundamentals_2 2
EXIT_CODE=$?
assert_exit_code "Exit code 1 for duplicate shortname" 1 "$EXIT_CODE"
assert_output_contains "Shortname taken" "already exists" "$OUT"
echo ""

echo "--- Test: Nonexistent category ---"
run_moosh course:copy -p "$MOODLE_PATH" 2 "Copy" unique_sn_123 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad category" 1 "$EXIT_CODE"
assert_output_contains "Category not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:copy -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Copy a course" "$OUT"
assert_output_contains "Help shows --userdata" "--userdata" "$OUT"
echo ""


print_summary
