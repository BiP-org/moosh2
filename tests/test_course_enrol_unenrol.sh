#!/usr/bin/env bash
#
# Integration test for moosh2 course:enrol, course:unenrol
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_course_enrol_unenrol.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 course:enrol/unenrol integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
# course:enrol
# ═══════════════════════════════════════════════════════════════════

echo "========== course:enrol =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:enrol -p "$MOODLE_PATH" 2 student01
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows course" "algebrafundamentals" "$OUT"
assert_output_contains "Shows role" "student" "$OUT"
assert_output_contains "Shows user" "student01" "$OUT"
echo ""

echo "--- Test: Enrol with --run ---"
run_moosh course:enrol -p "$MOODLE_PATH" --run 2 student01
assert_output_contains "Shows enrolled" "Enrolled" "$OUT"
assert_output_contains "Shows username" "student01" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_contains "user:list confirms student01 enrolled" ",student01," "$OUT"
echo ""

echo "--- Test: Enrol by ID ---"
run_moosh course:enrol -p "$MOODLE_PATH" --run --id 2 4
assert_output_contains "Enrolled by ID" "Enrolled" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_contains "user:list confirms student02 enrolled" ",student02," "$OUT"
echo ""

echo "--- Test: Enrol with custom role ---"
run_moosh course:enrol -p "$MOODLE_PATH" --run -r editingteacher 2 student05
assert_output_contains "Enrolled as teacher" "editingteacher" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=editingteacher -o csv
assert_output_contains "user:list confirms student05 has editingteacher role" ",student05," "$OUT"
echo ""

echo "--- Test: Site course rejected ---"
run_moosh course:enrol -p "$MOODLE_PATH" 1 student01
EXIT_CODE=$?
assert_exit_code "Exit code 1 for site course" 1 "$EXIT_CODE"
assert_output_contains "Cannot enrol site course" "Cannot enrol" "$OUT"
echo ""

echo "--- Test: Nonexistent user ---"
run_moosh course:enrol -p "$MOODLE_PATH" 2 nonexistentuser
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad user" 1 "$EXIT_CODE"
assert_output_contains "User not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:enrol -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Enrol users" "$OUT"
assert_output_contains "Help shows --role" "--role" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# course:unenrol
# ═══════════════════════════════════════════════════════════════════

echo "========== course:unenrol =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:unenrol -p "$MOODLE_PATH" 2 student01
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows user" "student01" "$OUT"
assert_output_contains "Shows plugin" "manual" "$OUT"
echo ""

echo "--- Test: Unenrol with --run ---"
run_moosh course:unenrol -p "$MOODLE_PATH" --run 2 student01
assert_output_contains "Shows unenrolled" "Unenrolled" "$OUT"
assert_output_contains "Shows username" "student01" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_not_contains "user:list confirms student01 unenrolled" ",student01," "$OUT"
echo ""

echo "--- Test: Already unenrolled ---"
run_moosh course:unenrol -p "$MOODLE_PATH" --run 2 student01
assert_output_contains "No enrolments" "No enrolments" "$OUT"
echo ""

echo "--- Test: Unenrol by ID ---"
run_moosh course:enrol -p "$MOODLE_PATH" --run 2 student05
run_moosh course:unenrol -p "$MOODLE_PATH" --run --id 2 7
assert_output_contains "Unenrolled by ID" "Unenrolled" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_not_contains "user:list confirms student05 unenrolled" ",student05," "$OUT"
echo ""

echo "--- Test: Nonexistent user ---"
run_moosh course:unenrol -p "$MOODLE_PATH" 2 nonexistentuser
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad user" 1 "$EXIT_CODE"
assert_output_contains "User not found" "not found" "$OUT"
echo ""

echo "--- Test: Nonexistent course ---"
run_moosh course:unenrol -p "$MOODLE_PATH" 99999 student01
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad course" 1 "$EXIT_CODE"
echo ""

echo "--- Test: Help ---"
run_moosh course:unenrol -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Unenrol users" "$OUT"
assert_output_contains "Help shows --plugin" "--plugin" "$OUT"
assert_output_contains "Help shows --id" "--id" "$OUT"
assert_output_contains "Help shows --role" "--role" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# course:unenrol --role  (partial role removal)
# ═══════════════════════════════════════════════════════════════════

echo "========== course:unenrol --role =========="
echo ""

echo "--- Setup: Enrol student02 with two roles (student + editingteacher) ---"
run_moosh course:enrol -p "$MOODLE_PATH" --run 2 student02
run_moosh course:enrol -p "$MOODLE_PATH" --run -r editingteacher 2 student02
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=student -o csv
assert_output_contains "user:list confirms student02 has student role" ",student02," "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=editingteacher -o csv
assert_output_contains "user:list confirms student02 has editingteacher role" ",student02," "$OUT"
echo ""

echo "--- Test: Dry run shows role-only removal when user has multiple roles ---"
run_moosh course:unenrol -p "$MOODLE_PATH" -r student 2 student02
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows role removal" "Remove role 'student'" "$OUT"
assert_output_contains "Mentions keeps enrolment" "keeps enrolment" "$OUT"
assert_output_not_contains "Does NOT show full unenrol" "Unenrol student02" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=student -o csv
assert_output_contains "Dry run did not change DB (student role still there)" ",student02," "$OUT"
echo ""

echo "--- Test: Remove single role with --run when user has multiple roles ---"
run_moosh course:unenrol -p "$MOODLE_PATH" --run -r student 2 student02
assert_output_contains "Shows role removed" "Removed role" "$OUT"
assert_output_contains "Shows role name" "\"student\"" "$OUT"
assert_output_contains "Shows username" "student02" "$OUT"
assert_output_not_contains "Does NOT show unenrolled" "Unenrolled" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=student -o csv
assert_output_not_contains "user:list confirms student02 lost student role" ",student02," "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 --course-role=editingteacher -o csv
assert_output_contains "user:list confirms student02 still has editingteacher role" ",student02," "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_contains "user:list confirms student02 still enrolled" ",student02," "$OUT"
echo ""

echo "--- Test: After role removal, role no longer assigned ---"
run_moosh course:unenrol -p "$MOODLE_PATH" -r student 2 student02
assert_output_contains "Reports missing role" "does not have role 'student'" "$OUT"
echo ""

echo "--- Test: User still enrolled with the other role (no -r → would full unenrol) ---"
run_moosh course:unenrol -p "$MOODLE_PATH" 2 student02
assert_output_contains "Still enrolled (dry run)" "Dry run" "$OUT"
assert_output_contains "Has manual enrolment" "manual" "$OUT"
echo ""

echo "--- Test: Removing the only remaining role triggers full unenrol ---"
run_moosh course:unenrol -p "$MOODLE_PATH" --run -r editingteacher 2 student02
assert_output_contains "Shows full unenrol" "Unenrolled" "$OUT"
assert_output_contains "Shows username" "student02" "$OUT"
run_moosh user:list -p "$MOODLE_PATH" --course=2 -o csv
assert_output_not_contains "user:list confirms student02 fully unenrolled" ",student02," "$OUT"
echo ""

echo "--- Test: After full unenrol, no role assignments remain ---"
run_moosh course:unenrol -p "$MOODLE_PATH" -r student 2 student02
assert_output_contains "No role assignments" "no role assignments" "$OUT"
echo ""

echo "--- Test: Nonexistent role ---"
run_moosh course:unenrol -p "$MOODLE_PATH" -r nonexistentrole 2 student01
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad role" 1 "$EXIT_CODE"
assert_output_contains "Role not found" "Role 'nonexistentrole' not found" "$OUT"
echo ""


print_summary
