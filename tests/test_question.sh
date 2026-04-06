#!/usr/bin/env bash
#
# Integration tests for moosh2 question and questioncategory commands
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_question.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 question commands integration tests ==="
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
#  questioncategory:create
# ═══════════════════════════════════════════════════════════════════

echo "========== questioncategory:create =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh questioncategory:create "Unit 1" 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Create category ---"
run_moosh questioncategory:create "Unit 1" 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "Shows name" "Unit 1" "$OUT"
CAT_ID=$(echo "$OUT" | grep -oP '^\| \K[0-9]+' | head -1)
echo "  Created category ID: $CAT_ID"
echo ""

echo "--- Test: Create with idnumber ---"
run_moosh questioncategory:create "Unit 2" 2 --idnumber U2 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Idnumber create exit code 0" 0 $EC
assert_output_contains "CSV has U2" "U2" "$OUT"
CAT_ID2=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Unit 2 category ID: $CAT_ID2"
echo ""

echo "--- Test: Create subcategory ---"
run_moosh questioncategory:create "Subtopic" 2 --parent $CAT_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Subcategory exit code 0" 0 $EC
assert_output_contains "Shows Subtopic" "Subtopic" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh questioncategory:create "Test" 999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh questioncategory:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create a question category" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  questioncategory:list
# ═══════════════════════════════════════════════════════════════════

echo "========== questioncategory:list =========="
echo ""

echo "--- Test: List categories ---"
run_moosh questioncategory:list 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows Unit 1" "Unit 1" "$OUT"
assert_output_contains "Shows Unit 2" "Unit 2" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh questioncategory:list 2 -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,name,parent,idnumber,questions" "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh questioncategory:list 2 -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh questioncategory:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List question categories" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  questioncategory:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== questioncategory:mod =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh questioncategory:mod $CAT_ID --name "Renamed" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Rename ---"
run_moosh questioncategory:mod $CAT_ID --name "Unit 1 Renamed" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Rename exit code 0" 0 $EC
assert_output_contains "Shows renamed" "Unit 1 Renamed" "$OUT"
echo ""

echo "--- Test: Change idnumber ---"
run_moosh questioncategory:mod $CAT_ID --idnumber U1R -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Idnumber exit code 0" 0 $EC
assert_output_contains "CSV has U1R" "U1R" "$OUT"
echo ""

echo "--- Test: Invalid ID ---"
run_moosh questioncategory:mod 99999 --name "Test" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
echo ""

echo "--- Test: No modification ---"
run_moosh questioncategory:mod $CAT_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
echo ""

echo "--- Test: questioncategory:delete ---"
run_moosh questioncategory:create "ToDelete" 2 -p "$MOODLE_PATH" --run -o csv
DEL_OUT="$OUT"
DEL_ID=$(echo "$DEL_OUT" | tail -1 | cut -d, -f1)
run_moosh questioncategory:delete $DEL_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh questioncategory:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify a question category" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  question:import (test before list/export to have questions)
# ═══════════════════════════════════════════════════════════════════

echo "========== question:import =========="
echo ""

# Create a GIFT file for import
cat > "$TMPDIR/questions.gift" << 'GIFTEOF'
::Capital of France::What is the capital of France?{=Paris ~London ~Berlin ~Madrid}

::Largest planet::What is the largest planet in our solar system?{=Jupiter ~Saturn ~Mars ~Earth}

::Water formula::The chemical formula for water is H2O.{TRUE}
GIFTEOF

echo "--- Test: Dry run ---"
run_moosh question:import "$TMPDIR/questions.gift" $CAT_ID --format gift -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Import GIFT ---"
run_moosh question:import "$TMPDIR/questions.gift" $CAT_ID --format gift -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Import exit code 0" 0 $EC
assert_output_contains "Shows imported" "Imported" "$OUT"
assert_output_contains "Shows count" "question(s)" "$OUT"
echo ""

echo "--- Test: Invalid file ---"
run_moosh question:import /nonexistent.gift $CAT_ID --format gift -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid file" 1 $EC
echo ""

echo "--- Test: Invalid category ---"
run_moosh question:import "$TMPDIR/questions.gift" 99999 --format gift -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid category" 1 $EC
echo ""

echo "--- Test: Invalid format ---"
run_moosh question:import "$TMPDIR/questions.gift" $CAT_ID --format yaml -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid format" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh question:import -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Import questions" "$OUT"
assert_output_contains "Help shows --format" "--format" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  question:list
# ═══════════════════════════════════════════════════════════════════

echo "========== question:list =========="
echo ""

echo "--- Test: List questions ---"
run_moosh question:list 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows Capital" "Capital of France" "$OUT"
assert_output_contains "Shows Largest" "Largest planet" "$OUT"
assert_output_contains "Shows Water" "Water formula" "$OUT"
echo ""

echo "--- Test: Filter by category ---"
run_moosh question:list 2 --category $CAT_ID -p "$MOODLE_PATH"
assert_output_contains "Shows filtered results" "Capital of France" "$OUT"
echo ""

echo "--- Test: Filter by qtype ---"
run_moosh question:list 2 --qtype multichoice -p "$MOODLE_PATH"
assert_output_contains "Shows multichoice" "multichoice" "$OUT"
assert_output_not_contains "No truefalse" "truefalse" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh question:list 2 -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,name,qtype" "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh question:list 2 -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh question:list 999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh question:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List questions" "$OUT"
assert_output_contains "Help shows --qtype" "--qtype" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  question:export
# ═══════════════════════════════════════════════════════════════════

echo "========== question:export =========="
echo ""

echo "--- Test: Export XML ---"
run_moosh question:export $CAT_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Export exit code 0" 0 $EC
assert_output_contains "XML has quiz tag" "quiz" "$OUT"
assert_output_contains "XML has question" "question" "$OUT"
echo ""

echo "--- Test: Export GIFT ---"
run_moosh question:export $CAT_ID --format gift -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Gift export exit code 0" 0 $EC
assert_output_contains "GIFT has question" "Capital of France" "$OUT"
echo ""

echo "--- Test: Invalid category ---"
run_moosh question:export 99999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid category" 1 $EC
echo ""

echo "--- Test: Invalid format ---"
run_moosh question:export $CAT_ID --format yaml -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid format" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh question:export -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Export questions" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  question:delete
# ═══════════════════════════════════════════════════════════════════

echo "========== question:delete =========="
echo ""

# Get a question ID to delete
run_moosh question:list 2 -p "$MOODLE_PATH" -o csv 2>&1 | grep "Water formula" | head -1 | cut -d, -f1
Q_ID="$OUT"
echo "  Water formula question ID: $Q_ID"

echo "--- Test: Delete dry run ---"
run_moosh question:delete $Q_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows question name" "Water formula" "$OUT"
echo ""

echo "--- Test: Delete question ---"
run_moosh question:delete $Q_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: Orphaned check ---"
run_moosh question:delete --orphaned -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Orphaned exit code 0" 0 $EC
# May find orphans from the deleted question or none — both are valid
assert_output_not_empty "Orphaned output not empty" "$OUT"
echo ""

echo "--- Test: No args ---"
run_moosh question:delete -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no args" 1 $EC
echo ""

echo "--- Test: Invalid ID ---"
run_moosh question:delete 99999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh question:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete questions" "$OUT"
assert_output_contains "Help shows --orphaned" "--orphaned" "$OUT"
echo ""


print_summary
