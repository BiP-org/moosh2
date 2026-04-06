#!/usr/bin/env bash
#
# Integration tests for moosh2 grade commands:
#   gradecategory:create, gradecategory:list, gradecategory:mod
#   gradeitem:create, gradeitem:list, gradeitem:mod
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_grade.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 grade commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  gradecategory:create
# ═══════════════════════════════════════════════════════════════════

echo "========== gradecategory:create =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh gradecategory:create "Assignments" 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows name" "Assignments" "$OUT"
echo ""

echo "--- Test: Create grade category ---"
run_moosh gradecategory:create "Assignments" 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "Shows fullname" "Assignments" "$OUT"
assert_output_contains "Shows courseid" "2" "$OUT"
echo ""

echo "--- Test: Create with aggregation ---"
run_moosh gradecategory:create "Exams" 2 -p "$MOODLE_PATH" --aggregation 0 --run -o csv
EC=$?
assert_exit_code "Aggregation create exit code 0" 0 $EC
assert_output_contains "CSV has Exams" "Exams" "$OUT"
CAT_EXAMS_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created Exams category ID: $CAT_EXAMS_ID"
echo ""

echo "--- Test: Create with keephigh and droplow ---"
run_moosh gradecategory:create "Quizzes" 2 -p "$MOODLE_PATH" --keephigh 3 --droplow 1 --run -o csv
EC=$?
assert_exit_code "Keephigh/droplow exit code 0" 0 $EC
assert_output_contains "CSV has Quizzes" "Quizzes" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh gradecategory:create "Test" 999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
assert_output_contains "Error for invalid course" "not found" "$OUT"
echo ""

echo "--- Test: gradecategory:create help ---"
run_moosh gradecategory:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create a grade category" "$OUT"
assert_output_contains "Help shows name" "name" "$OUT"
assert_output_contains "Help shows --aggregation" "--aggregation" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  gradecategory:list
# ═══════════════════════════════════════════════════════════════════

echo "========== gradecategory:list =========="
echo ""

echo "--- Test: List grade categories ---"
run_moosh gradecategory:list 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows Assignments" "Assignments" "$OUT"
assert_output_contains "Shows Exams" "Exams" "$OUT"
assert_output_contains "Shows Quizzes" "Quizzes" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh gradecategory:list 2 -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,fullname,parent,depth" "$OUT"
assert_output_contains "CSV has Assignments" "Assignments" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh gradecategory:list 2 -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has fullname" '"fullname": "Assignments"' "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh gradecategory:list 2 -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh gradecategory:list 999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
echo ""

echo "--- Test: gradecategory:list help ---"
run_moosh gradecategory:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List grade categories" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  gradecategory:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== gradecategory:mod =========="
echo ""

# Get the Assignments category ID
run_moosh gradecategory:list 2 -p "$MOODLE_PATH" -o csv
ASSIGN_CAT_ID=$(echo "$OUT" | grep Assignments | head -1 | cut -d, -f1)
echo "  Assignments category ID: $ASSIGN_CAT_ID"

echo "--- Test: Mod dry run ---"
run_moosh gradecategory:mod $ASSIGN_CAT_ID --name "Homework" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows name change" "Homework" "$OUT"
echo ""

echo "--- Test: Rename category ---"
run_moosh gradecategory:mod $ASSIGN_CAT_ID --name "Homework" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Rename exit code 0" 0 $EC
assert_output_contains "Shows Homework" "Homework" "$OUT"
echo ""

echo "--- Test: Change aggregation ---"
run_moosh gradecategory:mod $ASSIGN_CAT_ID --aggregation 0 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Aggregation change exit code 0" 0 $EC
assert_output_contains "CSV has category" "Homework" "$OUT"
echo ""

echo "--- Test: Change hidden ---"
run_moosh gradecategory:mod $ASSIGN_CAT_ID --hidden 1 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Hidden change exit code 0" 0 $EC
echo ""

echo "--- Test: No modification specified ---"
run_moosh gradecategory:mod $ASSIGN_CAT_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
assert_output_contains "Error for no mod" "No modifications specified" "$OUT"
echo ""

echo "--- Test: Invalid ID ---"
run_moosh gradecategory:mod 99999 --name "Test" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
assert_output_contains "Error for invalid ID" "not found" "$OUT"
echo ""

echo "--- Test: gradecategory:delete ---"
# Create a temporary category to delete
run_moosh gradecategory:create "ToDelete" 2 -p "$MOODLE_PATH" --run -o csv
DEL_OUT="$OUT"
DEL_ID=$(echo "$DEL_OUT" | tail -1 | cut -d, -f1)
run_moosh gradecategory:delete $DEL_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: gradecategory:mod help ---"
run_moosh gradecategory:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify a grade category" "$OUT"
assert_output_contains "Help shows --name" "--name" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  gradeitem:create
# ═══════════════════════════════════════════════════════════════════

echo "========== gradeitem:create =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh gradeitem:create "Test Item" 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Create grade item ---"
run_moosh gradeitem:create "Homework 1" 2 --category $ASSIGN_CAT_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "Shows itemname" "Homework 1" "$OUT"
assert_output_contains "Shows manual" "manual" "$OUT"
echo ""

echo "--- Test: Create with custom grademax ---"
run_moosh gradeitem:create "Final Exam" 2 --category $CAT_EXAMS_ID --grademax 200 --gradepass 120 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Grademax create exit code 0" 0 $EC
assert_output_contains "CSV has Final Exam" "Final Exam" "$OUT"
assert_output_contains "CSV has 200" "200" "$OUT"
echo ""

echo "--- Test: Create with idnumber ---"
run_moosh gradeitem:create "Quiz Score" 2 --idnumber QZ001 -p "$MOODLE_PATH" --run -o json
EC=$?
assert_exit_code "Idnumber create exit code 0" 0 $EC
assert_output_contains "JSON has itemname" '"itemname": "Quiz Score"' "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh gradeitem:create "Test" 999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
assert_output_contains "Error for invalid course" "not found" "$OUT"
echo ""

echo "--- Test: Invalid category ---"
run_moosh gradeitem:create "Test" 2 --category 99999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid category" 1 $EC
assert_output_contains "Error for invalid category" "not found" "$OUT"
echo ""

echo "--- Test: gradeitem:create help ---"
run_moosh gradeitem:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create a manual grade item" "$OUT"
assert_output_contains "Help shows --grademax" "--grademax" "$OUT"
assert_output_contains "Help shows --gradetype" "--gradetype" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  gradeitem:list
# ═══════════════════════════════════════════════════════════════════

echo "========== gradeitem:list =========="
echo ""

echo "--- Test: List grade items ---"
run_moosh gradeitem:list 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows Homework 1" "Homework 1" "$OUT"
assert_output_contains "Shows Final Exam" "Final Exam" "$OUT"
assert_output_contains "Shows Quiz Score" "Quiz Score" "$OUT"
echo ""

echo "--- Test: Filter by itemtype ---"
run_moosh gradeitem:list 2 --itemtype manual -p "$MOODLE_PATH"
assert_output_contains "Shows manual items" "Homework 1" "$OUT"
assert_output_not_contains "No course type" "course" "$OUT"
echo ""

echo "--- Test: Filter by category ---"
run_moosh gradeitem:list 2 --category $ASSIGN_CAT_ID -p "$MOODLE_PATH"
assert_output_contains "Shows category items" "Homework 1" "$OUT"
assert_output_not_contains "No Final Exam" "Final Exam" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh gradeitem:list 2 -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,itemname,itemtype" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh gradeitem:list 2 -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has itemname" '"itemname": "Homework 1"' "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh gradeitem:list 2 -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh gradeitem:list 999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
echo ""

echo "--- Test: gradeitem:list help ---"
run_moosh gradeitem:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List grade items" "$OUT"
assert_output_contains "Help shows --itemtype" "--itemtype" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  gradeitem:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== gradeitem:mod =========="
echo ""

# Get the Homework 1 grade item ID
run_moosh gradeitem:list 2 --itemtype manual -p "$MOODLE_PATH" -o csv
HW_ITEM_ID=$(echo "$OUT" | grep "Homework 1" | head -1 | cut -d, -f1)
echo "  Homework 1 item ID: $HW_ITEM_ID"

echo "--- Test: Mod dry run ---"
run_moosh gradeitem:mod $HW_ITEM_ID --name "Homework 1 (Updated)" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Rename item ---"
run_moosh gradeitem:mod $HW_ITEM_ID --name "Homework 1A" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Rename exit code 0" 0 $EC
assert_output_contains "Shows renamed" "Homework 1A" "$OUT"
echo ""

echo "--- Test: Change grademax ---"
run_moosh gradeitem:mod $HW_ITEM_ID --grademax 75 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Grademax change exit code 0" 0 $EC
assert_output_contains "CSV has 75" "75" "$OUT"
echo ""

echo "--- Test: Change gradepass ---"
run_moosh gradeitem:mod $HW_ITEM_ID --gradepass 50 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Gradepass change exit code 0" 0 $EC
assert_output_contains "CSV has 50" "50" "$OUT"
echo ""

echo "--- Test: Set hidden ---"
run_moosh gradeitem:mod $HW_ITEM_ID --hidden 1 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Hidden change exit code 0" 0 $EC
echo ""

echo "--- Test: Set locked ---"
run_moosh gradeitem:mod $HW_ITEM_ID --locked 1 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Locked change exit code 0" 0 $EC
echo ""

echo "--- Test: Move to different category ---"
run_moosh gradeitem:mod $HW_ITEM_ID --category $CAT_EXAMS_ID -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Category move exit code 0" 0 $EC
assert_output_contains "Shows new category" "$CAT_EXAMS_ID" "$OUT"
echo ""

echo "--- Test: Set idnumber ---"
run_moosh gradeitem:mod $HW_ITEM_ID --idnumber HW001 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Idnumber change exit code 0" 0 $EC
echo ""

echo "--- Test: Multiple changes at once ---"
run_moosh gradeitem:mod $HW_ITEM_ID --name "Homework Final" --grademax 100 --hidden 0 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Multiple changes exit code 0" 0 $EC
assert_output_contains "Shows renamed" "Homework Final" "$OUT"
echo ""

echo "--- Test: No modification specified ---"
run_moosh gradeitem:mod $HW_ITEM_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
assert_output_contains "Error for no mod" "No modifications specified" "$OUT"
echo ""

echo "--- Test: Invalid ID ---"
run_moosh gradeitem:mod 99999 --name "Test" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
assert_output_contains "Error for invalid ID" "not found" "$OUT"
echo ""

echo "--- Test: gradeitem:delete ---"
# Create a temporary item to delete
run_moosh gradeitem:create "ToDelete" 2 -p "$MOODLE_PATH" --run -o csv
DEL_OUT="$OUT"
DEL_ITEM_ID=$(echo "$DEL_OUT" | tail -1 | cut -d, -f1)
run_moosh gradeitem:delete $DEL_ITEM_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: gradeitem:mod help ---"
run_moosh gradeitem:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify a grade item" "$OUT"
assert_output_contains "Help shows --name" "--name" "$OUT"
assert_output_contains "Help shows --grademax" "--grademax" "$OUT"
echo ""


print_summary
