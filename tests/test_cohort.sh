#!/usr/bin/env bash
#
# Integration tests for moosh2 cohort commands:
#   cohort:create, cohort:list, cohort:mod, cohort:enrol, cohort:unenrol
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_cohort.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 cohort commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# ═══════════════════════════════════════════════════════════════════
#  cohort:create
# ═══════════════════════════════════════════════════════════════════

echo "========== cohort:create =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh cohort:create "Test Cohort" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Create cohort ---"
run_moosh cohort:create "Class 2025" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "Shows name" "Class 2025" "$OUT"
COHORT_ID=$(echo "$OUT" | grep -oP '^\| \K[0-9]+' | head -1)
echo "  Created cohort ID: $COHORT_ID"
echo ""

echo "--- Test: Create with options ---"
run_moosh cohort:create "Faculty" --idnumber FAC01 --description "Faculty members" -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Options create exit code 0" 0 $EC
assert_output_contains "CSV has Faculty" "Faculty" "$OUT"
assert_output_contains "CSV has FAC01" "FAC01" "$OUT"
COHORT_FAC_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Faculty cohort ID: $COHORT_FAC_ID"
echo ""

echo "--- Test: Create in category ---"
run_moosh cohort:create "Cat Cohort" --category 2 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Category create exit code 0" 0 $EC
assert_output_contains "CSV has Cat Cohort" "Cat Cohort" "$OUT"
echo ""

echo "--- Test: Create multiple ---"
run_moosh cohort:create "Group A" "Group B" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Multi create exit code 0" 0 $EC
assert_output_contains "Shows Group A" "Group A" "$OUT"
assert_output_contains "Shows Group B" "Group B" "$OUT"
echo ""

echo "--- Test: cohort:create help ---"
run_moosh cohort:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create a cohort" "$OUT"
assert_output_contains "Help shows --idnumber" "--idnumber" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  cohort:list
# ═══════════════════════════════════════════════════════════════════

echo "========== cohort:list =========="
echo ""

echo "--- Test: List cohorts ---"
run_moosh cohort:list -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows Class 2025" "Class 2025" "$OUT"
assert_output_contains "Shows Faculty" "Faculty" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh cohort:list -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,name,idnumber,contextid,visible,members" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh cohort:list -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has name" '"name": "Class 2025"' "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh cohort:list -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
echo ""

echo "--- Test: cohort:list help ---"
run_moosh cohort:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List cohorts" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  cohort:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== cohort:mod =========="
echo ""

echo "--- Test: Mod dry run ---"
run_moosh cohort:mod $COHORT_ID --name "Renamed" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Rename cohort ---"
run_moosh cohort:mod $COHORT_ID --name "Class 2025 Renamed" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Rename exit code 0" 0 $EC
assert_output_contains "Shows renamed" "Class 2025 Renamed" "$OUT"
echo ""

echo "--- Test: Change idnumber ---"
run_moosh cohort:mod $COHORT_ID --idnumber CLS25 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Idnumber exit code 0" 0 $EC
assert_output_contains "CSV has CLS25" "CLS25" "$OUT"
echo ""

echo "--- Test: Add member by username ---"
run_moosh cohort:mod $COHORT_ID --add-member admin -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Add member exit code 0" 0 $EC
assert_output_contains "Shows added" "Added 1 member" "$OUT"
echo ""

echo "--- Test: Add member by user ID ---"
# Get a student user ID
run_moosh sql:select -p "$MOODLE_PATH" "SELECT id FROM mdl_user WHERE username='student01'" -o csv
STUDENT_ID=$(echo "$OUT" | tail -1)
run_moosh cohort:mod $COHORT_ID --add-member $STUDENT_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Add by ID exit code 0" 0 $EC
assert_output_contains "Shows added" "Added 1 member" "$OUT"
echo ""

echo "--- Test: Member count updated ---"
run_moosh cohort:mod $COHORT_ID --visible 1 -p "$MOODLE_PATH" --run -o csv
assert_output_contains "Shows 2 members" ",2" "$OUT"
echo ""

echo "--- Test: Remove member ---"
run_moosh cohort:mod $COHORT_ID --remove-member admin -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Remove member exit code 0" 0 $EC
assert_output_contains "Shows removed" "Removed 1 member" "$OUT"
echo ""

echo "--- Test: Import members from CSV ---"
cat > "$TMPDIR/members.csv" << 'CSVEOF'
username
student01
student02
student03
CSVEOF
run_moosh cohort:mod $COHORT_ID --import "$TMPDIR/members.csv" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Import exit code 0" 0 $EC
assert_output_contains "Shows added members" "Added" "$OUT"
echo ""

echo "--- Test: Invalid user ---"
run_moosh cohort:mod $COHORT_ID --add-member nonexistentuser -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid user" 1 $EC
assert_output_contains "Error for invalid user" "not found" "$OUT"
echo ""

echo "--- Test: Invalid cohort ---"
run_moosh cohort:mod 99999 --name "Test" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid cohort" 1 $EC
assert_output_contains "Error for invalid cohort" "not found" "$OUT"
echo ""

echo "--- Test: No modification ---"
run_moosh cohort:mod $COHORT_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
assert_output_contains "Error for no mod" "No modifications" "$OUT"
echo ""

echo "--- Test: cohort:delete ---"
run_moosh cohort:create "ToDelete" -p "$MOODLE_PATH" --run -o csv
DEL_OUT="$OUT"
DEL_ID=$(echo "$DEL_OUT" | tail -1 | cut -d, -f1)
run_moosh cohort:delete $DEL_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: cohort:mod help ---"
run_moosh cohort:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify a cohort" "$OUT"
assert_output_contains "Help shows --add-member" "--add-member" "$OUT"
assert_output_contains "Help shows --import" "--import" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  cohort:enrol
# ═══════════════════════════════════════════════════════════════════

echo "========== cohort:enrol =========="
echo ""

echo "--- Test: Enrol dry run ---"
run_moosh cohort:enrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows cohort name" "Faculty" "$OUT"
echo ""

echo "--- Test: Enrol cohort to course ---"
run_moosh cohort:enrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Enrol exit code 0" 0 $EC
assert_output_contains "Shows Faculty" "Faculty" "$OUT"
assert_output_contains "Shows student role" "student" "$OUT"
echo ""

echo "--- Test: Duplicate enrol ---"
run_moosh cohort:enrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for duplicate" 1 $EC
assert_output_contains "Error for duplicate" "already synced" "$OUT"
echo ""

echo "--- Test: Enrol with role ---"
run_moosh cohort:enrol $COHORT_FAC_ID 3 --role editingteacher -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Role enrol exit code 0" 0 $EC
assert_output_contains "Shows teacher role" "editingteacher" "$OUT"
echo ""

echo "--- Test: Invalid cohort ---"
run_moosh cohort:enrol 99999 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid cohort" 1 $EC
echo ""

echo "--- Test: Invalid course ---"
run_moosh cohort:enrol $COHORT_FAC_ID 999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
echo ""

echo "--- Test: Invalid role ---"
run_moosh cohort:enrol $COHORT_FAC_ID 4 --role nonexistent -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid role" 1 $EC
assert_output_contains "Error for invalid role" "not found" "$OUT"
echo ""

echo "--- Test: cohort:enrol help ---"
run_moosh cohort:enrol -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Sync a cohort to a course" "$OUT"
assert_output_contains "Help shows --role" "--role" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  cohort:unenrol
# ═══════════════════════════════════════════════════════════════════

echo "========== cohort:unenrol =========="
echo ""

echo "--- Test: Unenrol dry run ---"
run_moosh cohort:unenrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Unenrol cohort from course ---"
run_moosh cohort:unenrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Unenrol exit code 0" 0 $EC
assert_output_contains "Shows removed" "Removed" "$OUT"
echo ""

echo "--- Test: Unenrol with role filter ---"
run_moosh cohort:unenrol $COHORT_FAC_ID 3 --role editingteacher -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Role unenrol exit code 0" 0 $EC
assert_output_contains "Shows removed" "Removed" "$OUT"
echo ""

echo "--- Test: Unenrol nonexistent ---"
run_moosh cohort:unenrol $COHORT_FAC_ID 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for nonexistent" 1 $EC
assert_output_contains "Error for nonexistent" "No cohort enrolment found" "$OUT"
echo ""

echo "--- Test: cohort:unenrol help ---"
run_moosh cohort:unenrol -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Remove cohort enrolment sync" "$OUT"
echo ""


print_summary
