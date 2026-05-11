#!/usr/bin/env bash
#
# Integration test for moosh2 quiz:question:add
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_quiz_question_add.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 quiz:question:add integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# ── Setup ─────────────────────────────────────────────────────────

echo "========== Setup =========="

echo "--- Create question category ---"
run_moosh questioncategory:create "QAddCat" 2 -p "$MOODLE_PATH" --run -o csv
CAT_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  category id: $CAT_ID"
echo ""

echo "--- Import 3 GIFT questions ---"
cat > "$TMPDIR/questions.gift" << 'GIFTEOF'
::Q1::What is 2+2?{=4 ~3 ~5 ~6}

::Q2::Capital of France?{=Paris ~London ~Berlin}

::Q3::Largest planet?{=Jupiter ~Saturn ~Mars}
GIFTEOF
run_moosh question:import "$TMPDIR/questions.gift" $CAT_ID --format gift -p "$MOODLE_PATH" --run
echo ""

echo "--- Look up question IDs ---"
run_moosh question:list 2 --category=$CAT_ID -o csv -p "$MOODLE_PATH"
Q1_ID=$(echo "$OUT" | awk -F, '$2=="Q1"{print $1; exit}')
Q2_ID=$(echo "$OUT" | awk -F, '$2=="Q2"{print $1; exit}')
Q3_ID=$(echo "$OUT" | awk -F, '$2=="Q3"{print $1; exit}')
echo "  Q1 id: $Q1_ID, Q2 id: $Q2_ID, Q3 id: $Q3_ID"
assert_output_not_empty "Q1 ID resolved" "$Q1_ID"
assert_output_not_empty "Q2 ID resolved" "$Q2_ID"
assert_output_not_empty "Q3 ID resolved" "$Q3_ID"
echo ""

echo "--- Create quiz activity ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "QAddQuiz" quiz 2 -o csv
QUIZ_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  quiz cmid: $QUIZ_CMID"
echo ""


# ── Dry run ───────────────────────────────────────────────────────

echo "========== Dry run =========="
echo ""

echo "--- Dry run (no --run flag) ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Reports dry run mode" "Dry run" "$OUT"
assert_output_contains "Shows quiz name" "QAddQuiz" "$OUT"
assert_output_contains "Shows question name" "Q1" "$OUT"
assert_output_contains "Shows append page hint" "append to last page" "$OUT"
echo ""

echo "--- Verify nothing was added (slot count check) ---"
run_moosh sql:select "SELECT COUNT(*) AS n FROM mdl_quiz_slots WHERE quizid IN (SELECT id FROM mdl_quiz WHERE name='QAddQuiz')" -p "$MOODLE_PATH" -o csv
SLOT_COUNT=$(echo "$OUT" | tail -n 1)
assert_output_contains "Slot count is 0 after dry run" "0" "$SLOT_COUNT"
echo ""


# ── Happy path: append ────────────────────────────────────────────

echo "========== Append (--run) =========="
echo ""

echo "--- Add Q1 to quiz ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Add exit code 0" 0 $EC
assert_output_contains "Reports success" "Added question" "$OUT"
assert_output_contains "Reports slot 1" "slot 1" "$OUT"
assert_output_contains "Reports maxmark" "maxmark 1" "$OUT"
echo ""

echo "--- Add Q2 to quiz ---"
run_moosh quiz:question:add $QUIZ_CMID $Q2_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Add exit code 0" 0 $EC
assert_output_contains "Reports slot 2" "slot 2" "$OUT"
echo ""

echo "--- Verify both questions are now in slots ---"
run_moosh sql:select "SELECT COUNT(*) AS n FROM mdl_quiz_slots WHERE quizid IN (SELECT id FROM mdl_quiz WHERE name='QAddQuiz')" -p "$MOODLE_PATH" -o csv
SLOT_COUNT=$(echo "$OUT" | tail -n 1)
assert_output_contains "Slot count is 2" "2" "$SLOT_COUNT"
echo ""


# ── --maxmark ─────────────────────────────────────────────────────

echo "========== --maxmark =========="
echo ""

echo "--- Add Q3 with custom max mark ---"
run_moosh quiz:question:add $QUIZ_CMID $Q3_ID --maxmark=7.5 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Custom maxmark exit code 0" 0 $EC
assert_output_contains "Reports slot 3" "slot 3" "$OUT"
assert_output_contains "Reports maxmark 7.5" "maxmark 7.5" "$OUT"
echo ""


# ── Duplicate detection ───────────────────────────────────────────

echo "========== Duplicate =========="
echo ""

echo "--- Add Q1 again (dry run) ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Duplicate dry run exit 0" 0 $EC
assert_output_contains "Notes question already present" "already in the quiz" "$OUT"
echo ""

echo "--- Add Q1 again (--run) ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Duplicate --run exit 0" 0 $EC
assert_output_contains "Reports already in quiz" "already in quiz" "$OUT"
echo ""

echo "--- Verify slot count is still 3 ---"
run_moosh sql:select "SELECT COUNT(*) AS n FROM mdl_quiz_slots WHERE quizid IN (SELECT id FROM mdl_quiz WHERE name='QAddQuiz')" -p "$MOODLE_PATH" -o csv
SLOT_COUNT=$(echo "$OUT" | tail -n 1)
assert_output_contains "Slot count is 3" "3" "$SLOT_COUNT"
echo ""


# ── --page (insert at specific page) ──────────────────────────────

echo "========== --page =========="
echo ""

echo "--- Create another quiz for --page testing ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "QAddPageQuiz" quiz 2 -o csv
PAGE_QUIZ_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  cmid: $PAGE_QUIZ_CMID"
echo ""

echo "--- Add Q1, Q2 to set up two slots ---"
run_moosh quiz:question:add $PAGE_QUIZ_CMID $Q1_ID --run -p "$MOODLE_PATH"
run_moosh quiz:question:add $PAGE_QUIZ_CMID $Q2_ID --run -p "$MOODLE_PATH"
echo ""

echo "--- Insert Q3 onto page 1 (shifts later slots) ---"
run_moosh quiz:question:add $PAGE_QUIZ_CMID $Q3_ID --page=1 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Insert page=1 exit 0" 0 $EC
assert_output_contains "Reports page 1" "page 1" "$OUT"
echo ""


# ── Error paths ───────────────────────────────────────────────────

echo "========== Error paths =========="
echo ""

echo "--- Invalid cmid (zero) ---"
run_moosh quiz:question:add 0 $Q1_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for cmid=0" 1 $EC
assert_output_contains "Reports invalid cmid" "Invalid course module ID" "$OUT"
echo ""

echo "--- Invalid question ID (zero) ---"
run_moosh quiz:question:add $QUIZ_CMID 0 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for qid=0" 1 $EC
assert_output_contains "Reports invalid qid" "Invalid question ID" "$OUT"
echo ""

echo "--- Nonexistent cmid ---"
run_moosh quiz:question:add 99999 $Q1_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for missing cmid" 1 $EC
assert_output_contains "Reports cmid not found" "not found" "$OUT"
echo ""

echo "--- Nonexistent question ID ---"
run_moosh quiz:question:add $QUIZ_CMID 99999 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for missing qid" 1 $EC
assert_output_contains "Reports question not found" "Question with ID 99999 not found" "$OUT"
echo ""

echo "--- Cmid points to a non-quiz module ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "NotAQuiz" forum 2 -o csv
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh quiz:question:add $FORUM_CMID $Q1_ID --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for non-quiz cmid" 1 $EC
echo ""

echo "--- --page=0 rejected ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID --page=0 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for --page=0" 1 $EC
assert_output_contains "Reports invalid page" "--page must be a positive integer" "$OUT"
echo ""

echo "--- --maxmark=-1 rejected ---"
run_moosh quiz:question:add $QUIZ_CMID $Q1_ID --maxmark=-1 --run -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for negative maxmark" 1 $EC
assert_output_contains "Reports invalid maxmark" "--maxmark must be a non-negative number" "$OUT"
echo ""


# ── Help ──────────────────────────────────────────────────────────

echo "========== Help =========="
echo ""

echo "--- Help text ---"
run_moosh quiz:question:add --help -p "$MOODLE_PATH"
assert_output_contains "Help shows description" "Add a question from the question bank to a quiz" "$OUT"
assert_output_contains "Help shows --page" "--page" "$OUT"
assert_output_contains "Help shows --maxmark" "--maxmark" "$OUT"
assert_output_contains "Help shows Random note" "Random questions" "$OUT"
echo ""


print_summary
