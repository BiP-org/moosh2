#!/usr/bin/env bash
#
# Integration test for moosh2 user:delete and course:delete
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_user_course_delete.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 user:delete and course:delete integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
# user:delete
# ═══════════════════════════════════════════════════════════════════

echo "========== user:delete =========="
echo ""

echo "--- Test: Dry run by username ---"
run_moosh user:delete -p "$MOODLE_PATH" student01
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows username" "student01" "$OUT"
assert_output_contains "Shows email" "student01@example" "$OUT"
echo ""

echo "--- Test: Dry run by ID ---"
run_moosh user:delete -p "$MOODLE_PATH" --id 3
assert_output_contains "Shows dry run by ID" "Dry run" "$OUT"
assert_output_contains "Shows ID=3" "ID=3" "$OUT"
echo ""

echo "--- Test: Delete single user by username ---"
run_moosh user:delete -p "$MOODLE_PATH" --run student01
assert_output_contains "Shows deleted" "Deleted user" "$OUT"
assert_output_contains "Shows username" "student01" "$OUT"
# Verify user is deleted
run_moosh user:delete -p "$MOODLE_PATH" student01
EXIT_CODE=$?
assert_exit_code "Already deleted user returns error" 1 "$EXIT_CODE"
echo ""

echo "--- Test: Delete multiple users by ID ---"
run_moosh user:delete -p "$MOODLE_PATH" --id --run 4 5
assert_output_contains "Deleted student02" "student02" "$OUT"
assert_output_contains "Deleted student03" "student03" "$OUT"
echo ""

echo "--- Test: Cannot delete admin ---"
run_moosh user:delete -p "$MOODLE_PATH" admin
EXIT_CODE=$?
assert_exit_code "Exit code 1 for admin" 1 "$EXIT_CODE"
assert_output_contains "Admin protection" "Cannot delete" "$OUT"
echo ""

echo "--- Test: Cannot delete guest ---"
run_moosh user:delete -p "$MOODLE_PATH" guest
EXIT_CODE=$?
assert_exit_code "Exit code 1 for guest" 1 "$EXIT_CODE"
assert_output_contains "Guest protection" "Cannot delete" "$OUT"
echo ""

echo "--- Test: Nonexistent user ---"
run_moosh user:delete -p "$MOODLE_PATH" nonexistentuser123
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent" 1 "$EXIT_CODE"
assert_output_contains "Shows not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh user:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete users" "$OUT"
assert_output_contains "Help shows --id" "--id" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# course:delete
# ═══════════════════════════════════════════════════════════════════

echo "========== course:delete =========="
echo ""

# Ensure recycle bin is off for basic delete tests
run_moosh config:set -p "$MOODLE_PATH" categorybinenable 0 --plugin=tool_recyclebin --run

echo "--- Test: Dry run (recycle bin disabled) ---"
run_moosh course:delete -p "$MOODLE_PATH" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows shortname" "algebrafundamentals" "$OUT"
assert_output_contains "Shows fullname" "Algebra Fundamentals" "$OUT"
assert_output_contains "Shows recycle bin disabled" "recycle bin is disabled" "$OUT"
echo ""

echo "--- Test: Dry run multiple ---"
run_moosh course:delete -p "$MOODLE_PATH" 2 3
assert_output_contains "Shows Algebra" "Algebra" "$OUT"
assert_output_contains "Shows Calculus" "Calculus" "$OUT"
echo ""

echo "--- Test: Delete single course ---"
run_moosh course:delete -p "$MOODLE_PATH" --run 2
assert_output_contains "Shows deleted" "Deleted course" "$OUT"
assert_output_contains "Shows shortname" "algebrafundamentals" "$OUT"
# Verify it's gone
run_moosh course:delete -p "$MOODLE_PATH" 2
EXIT_CODE=$?
assert_exit_code "Deleted course returns error" 1 "$EXIT_CODE"
echo ""

echo "--- Test: Delete multiple courses ---"
run_moosh course:delete -p "$MOODLE_PATH" --run 3 4
assert_output_contains "Deleted Calculus" "calculusi" "$OUT"
assert_output_contains "Deleted Statistics" "statisticsandprobabi" "$OUT"
echo ""

echo "--- Test: Cannot delete site course ---"
run_moosh course:delete -p "$MOODLE_PATH" 1
EXIT_CODE=$?
assert_exit_code "Exit code 1 for site course" 1 "$EXIT_CODE"
assert_output_contains "Site course protection" "Cannot delete" "$OUT"
echo ""

echo "--- Test: Nonexistent course ---"
run_moosh course:delete -p "$MOODLE_PATH" 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent" 1 "$EXIT_CODE"
assert_output_contains "Shows not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete courses" "$OUT"
assert_output_contains "Help shows --force" "--force" "$OUT"
echo ""

echo "--- Test: Dry run shows recycle bin enabled notice ---"
run_moosh config:set -p "$MOODLE_PATH" categorybinenable 1 --plugin=tool_recyclebin --run
run_moosh course:delete -p "$MOODLE_PATH" 5
assert_output_contains "Shows recycle bin warning" "recycle bin is enabled" "$OUT"
echo ""

echo "--- Test: Run blocked when recycle bin enabled ---"
run_moosh course:delete -p "$MOODLE_PATH" --run 5
EXIT_CODE=$?
assert_exit_code "Blocked without --force" 1 "$EXIT_CODE"
assert_output_contains "Shows recycle bin error" "recycle bin is enabled" "$OUT"
echo ""

echo "--- Test: Run succeeds with --force when recycle bin enabled ---"
run_moosh course:delete -p "$MOODLE_PATH" --run --force 5
assert_output_contains "Deleted with --force" "Deleted course" "$OUT"
run_moosh config:set -p "$MOODLE_PATH" categorybinenable 0 --plugin=tool_recyclebin --run
echo ""


print_summary
