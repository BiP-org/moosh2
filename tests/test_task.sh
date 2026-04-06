#!/usr/bin/env bash
#
# Integration tests for moosh2 task commands
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_task.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 task commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# Use a known task for testing
TASK_CLASS='\core\task\send_new_user_passwords_task'

# ═══════════════════════════════════════════════════════════════════
#  task:list
# ═══════════════════════════════════════════════════════════════════

echo "========== task:list =========="
echo ""

echo "--- Test: List all tasks ---"
run_moosh task:list -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows classname header" "classname" "$OUT"
assert_output_contains "Shows schedule header" "schedule" "$OUT"
assert_output_contains "Shows a core task" "core" "$OUT"
echo ""

echo "--- Test: Filter by component ---"
run_moosh task:list --component moodle -p "$MOODLE_PATH"
assert_output_contains "Shows moodle tasks" "moodle" "$OUT"
echo ""

echo "--- Test: Filter disabled ---"
run_moosh task:list --disabled -p "$MOODLE_PATH"
# All shown should have 'yes' in disabled column
assert_output_contains "Shows disabled tasks" "yes" "$OUT"
echo ""

echo "--- Test: Filter enabled ---"
run_moosh task:list --enabled -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Enabled filter exit code 0" 0 $EC
echo ""

echo "--- Test: Running filter (no running expected) ---"
run_moosh task:list --running -p "$MOODLE_PATH"
assert_output_contains "No running tasks" "No tasks found" "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh task:list --classname-only -p "$MOODLE_PATH"
assert_output_not_empty "ID-only not empty" "$OUT"
assert_output_contains "Shows task classname" "task" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh task:list -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "classname,component,schedule" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh task:list --component moodle -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has component" '"component": "moodle"' "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh task:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List scheduled tasks" "$OUT"
assert_output_contains "Help shows --running" "--running" "$OUT"
assert_output_contains "Help shows --failed" "--failed" "$OUT"
echo ""



# ═══════════════════════════════════════════════════════════════════
#  task:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== task:mod =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh task:mod "$TASK_CLASS" --enabled 0 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Disable task ---"
run_moosh task:mod "$TASK_CLASS" --enabled 0 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Disable exit code 0" 0 $EC
assert_output_contains "Shows disabled" "yes" "$OUT"
echo ""

echo "--- Test: Enable task ---"
run_moosh task:mod "$TASK_CLASS" --enabled 1 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Enable exit code 0" 0 $EC
assert_output_contains "Shows enabled" "no" "$OUT"
echo ""

echo "--- Test: Change schedule ---"
run_moosh task:mod "$TASK_CLASS" --minute '*/10' --hour '2' -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Schedule change exit code 0" 0 $EC
assert_output_contains "Shows new schedule" "*/10 2" "$OUT"
echo ""

echo "--- Test: Reset to default ---"
run_moosh task:mod "$TASK_CLASS" --reset -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Reset exit code 0" 0 $EC
assert_output_contains "Shows reset" "Reset" "$OUT"
echo ""

echo "--- Test: Invalid task ---"
run_moosh task:mod '\nonexistent\task' --enabled 0 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid task" 1 $EC
assert_output_contains "Error for invalid task" "not found" "$OUT"
echo ""

echo "--- Test: No modification ---"
run_moosh task:mod "$TASK_CLASS" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh task:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify a scheduled task" "$OUT"
assert_output_contains "Help shows --minute" "--minute" "$OUT"
assert_output_contains "Help shows --reset" "--reset" "$OUT"
assert_output_contains "Help shows --clear-fail" "--clear-fail" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  task:run
# ═══════════════════════════════════════════════════════════════════

echo "========== task:run =========="
echo ""

echo "--- Test: Run task ---"
run_moosh task:run "$TASK_CLASS" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Run exit code 0" 0 $EC
assert_output_contains "Shows executing" "Executing" "$OUT"
assert_output_contains "Shows completed" "completed" "$OUT"
echo ""

echo "--- Test: Invalid task ---"
run_moosh task:run '\nonexistent\task' -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid task" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh task:run -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Execute a scheduled task" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  task:adhoc
# ═══════════════════════════════════════════════════════════════════

echo "========== task:adhoc =========="
echo ""

echo "--- Test: List adhoc tasks ---"
run_moosh task:adhoc -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
# May have tasks or may say "No adhoc tasks found" - both valid
assert_output_not_empty "List not empty" "$OUT"
echo ""

echo "--- Test: Count ---"
run_moosh task:adhoc --count -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Count exit code 0" 0 $EC
assert_output_contains "Shows total" "Total adhoc tasks" "$OUT"
assert_output_contains "Shows pending" "Pending" "$OUT"
assert_output_contains "Shows running" "Running" "$OUT"
assert_output_contains "Shows failed" "Failed" "$OUT"
echo ""

echo "--- Test: Failed filter ---"
run_moosh task:adhoc --failed -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Failed filter exit code 0" 0 $EC
echo ""

echo "--- Test: Clean dry run ---"
run_moosh task:adhoc --clean -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Clean dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Clean ---"
run_moosh task:adhoc --clean -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Clean exit code 0" 0 $EC
assert_output_contains "Shows cleaned" "Cleaned" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh task:adhoc -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List or manage adhoc tasks" "$OUT"
assert_output_contains "Help shows --failed" "--failed" "$OUT"
assert_output_contains "Help shows --execute" "--execute" "$OUT"
assert_output_contains "Help shows --clean" "--clean" "$OUT"
assert_output_contains "Help shows --count" "--count" "$OUT"
echo ""


print_summary
