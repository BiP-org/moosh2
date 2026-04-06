#!/usr/bin/env bash
#
# Integration tests for moosh2 utility commands:
#   maintenance:on/off, debug:on/off, dashboard:reset,
#   system:check, session:kill, database:check, php:eval
#
# Usage: bash tests/test_utility.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 utility commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  maintenance:on / maintenance:off
# ═══════════════════════════════════════════════════════════════════

echo "========== maintenance:on / maintenance:off =========="
echo ""

echo "--- Test: Enable maintenance ---"
run_moosh maintenance:on -p "$MOODLE_PATH"
EC=$?
assert_exit_code "On exit code 0" 0 $EC
assert_output_contains "Shows enabled" "enabled" "$OUT"
echo ""

echo "--- Test: Enable with message ---"
run_moosh maintenance:on --message "Back soon" -p "$MOODLE_PATH"
assert_output_contains "Shows enabled" "enabled" "$OUT"
echo ""

echo "--- Test: Disable maintenance ---"
run_moosh maintenance:off -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Off exit code 0" 0 $EC
assert_output_contains "Shows disabled" "disabled" "$OUT"
echo ""

echo "--- Test: maintenance:on help ---"
run_moosh maintenance:on -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Enable maintenance mode" "$OUT"
assert_output_contains "Help shows --message" "--message" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  debug:on / debug:off
# ═══════════════════════════════════════════════════════════════════

echo "========== debug:on / debug:off =========="
echo ""

echo "--- Test: Enable debug ---"
run_moosh debug:on -p "$MOODLE_PATH"
EC=$?
assert_exit_code "On exit code 0" 0 $EC
assert_output_contains "Shows enabled" "enabled" "$OUT"
echo ""

echo "--- Test: Disable debug ---"
run_moosh debug:off -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Off exit code 0" 0 $EC
assert_output_contains "Shows disabled" "disabled" "$OUT"
echo ""

echo "--- Test: debug:on help ---"
run_moosh debug:on -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Enable developer debug" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  dashboard:reset
# ═══════════════════════════════════════════════════════════════════

echo "========== dashboard:reset =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh dashboard:reset -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Reset dashboards ---"
run_moosh dashboard:reset -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Reset exit code 0" 0 $EC
assert_output_contains "Shows reset" "reset" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh dashboard:reset -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Reset all user dashboards" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  system:check
# ═══════════════════════════════════════════════════════════════════

echo "========== system:check =========="
echo ""

echo "--- Test: Run all checks ---"
run_moosh system:check -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Check exit code 0" 0 $EC
assert_output_contains "Shows status column" "status" "$OUT"
assert_output_contains "Shows summary" "Summary" "$OUT"
echo ""

echo "--- Test: Filter by status ---"
run_moosh system:check --status ok -p "$MOODLE_PATH"
assert_output_contains "Shows ok checks" "ok" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh system:check -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "status,component,check,info" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh system:check -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Run system health" "$OUT"
assert_output_contains "Help shows --status" "--status" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  session:kill
# ═══════════════════════════════════════════════════════════════════

echo "========== session:kill =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh session:kill -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Kill sessions ---"
run_moosh session:kill -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Kill exit code 0" 0 $EC
assert_output_contains "Shows destroyed" "destroyed" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh session:kill -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Destroy all user sessions" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  database:check
# ═══════════════════════════════════════════════════════════════════

echo "========== database:check =========="
echo ""

echo "--- Test: Check schema ---"
run_moosh database:check -p "$MOODLE_PATH"
EC=$?
# May find issues or not, both valid
assert_output_not_empty "Check not empty" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh database:check -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Check database schema" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  php:eval
# ═══════════════════════════════════════════════════════════════════

echo "========== php:eval =========="
echo ""

echo "--- Test: Eval simple ---"
run_moosh php:eval 'echo $CFG->wwwroot' -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Eval exit code 0" 0 $EC
MOODLE_BASENAME="$(basename "${MOODLE_DIR:-/var/www/html/moodle52}")"
assert_output_contains "Shows wwwroot" "$MOODLE_BASENAME" "$OUT"
echo ""

echo "--- Test: Eval DB query ---"
run_moosh php:eval 'echo $DB->count_records("user")' -p "$MOODLE_PATH"
EC=$?
assert_exit_code "DB eval exit code 0" 0 $EC
assert_output_not_empty "DB result not empty" "$OUT"
echo ""

echo "--- Test: Eval with error ---"
run_moosh php:eval 'nonexistent_function()' -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for error" 1 $EC
assert_output_contains "Shows error" "Error" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh php:eval -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Evaluate PHP code" "$OUT"
echo ""


print_summary
