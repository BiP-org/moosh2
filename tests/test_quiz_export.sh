#!/usr/bin/env bash
#
# Integration test for moosh2 quiz:export
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_quiz_export.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 quiz:export integration tests ==="
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
run_moosh questioncategory:create "QExport Cat" 2 -p "$MOODLE_PATH" --run -o csv
CAT_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  category id: $CAT_ID"
echo ""

echo "--- Import 2 plain GIFT questions ---"
cat > "$TMPDIR/questions.gift" << 'GIFTEOF'
::Capital of France::What is the capital of France?{=Paris ~London ~Berlin ~Madrid}

::Largest planet::What is the largest planet?{=Jupiter ~Saturn ~Mars ~Earth}
GIFTEOF
run_moosh question:import "$TMPDIR/questions.gift" $CAT_ID --format gift -p "$MOODLE_PATH" --run
echo ""

echo "--- Import 1 question with embedded image (base64 PNG) ---"
# 1x1 transparent PNG, base64-encoded — exercises the <file> round-trip path.
cat > "$TMPDIR/with_image.xml" << 'XMLEOF'
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
<question type="truefalse">
  <name><text>WithImage</text></name>
  <questiontext format="html">
    <text><![CDATA[<p>Look: <img src="@@PLUGINFILE@@/dot.png" alt=""></p>]]></text>
    <file name="dot.png" path="/" encoding="base64">iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=</file>
  </questiontext>
  <generalfeedback format="html"><text></text></generalfeedback>
  <defaultgrade>1</defaultgrade>
  <penalty>1</penalty>
  <hidden>0</hidden>
  <answer fraction="100" format="html"><text>true</text><feedback format="html"><text></text></feedback></answer>
  <answer fraction="0" format="html"><text>false</text><feedback format="html"><text></text></feedback></answer>
</question>
</quiz>
XMLEOF
run_moosh question:import "$TMPDIR/with_image.xml" $CAT_ID --format xml -p "$MOODLE_PATH" --run
echo ""

echo "--- Create quiz activity ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "TestQuiz" quiz 2 -o csv
QUIZ_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  quiz cmid: $QUIZ_CMID"
echo ""

echo "--- Add all 3 imported questions to the quiz ---"
ADD_PHP="require_once(\$CFG->dirroot.'/mod/quiz/locallib.php'); \$cm=get_coursemodule_from_id('quiz', $QUIZ_CMID); \$quiz=\$DB->get_record('quiz', ['id'=>\$cm->instance]); \$qs=\$DB->get_records_sql(\"SELECT q.id FROM {question} q JOIN {question_versions} qv ON qv.questionid=q.id JOIN {question_bank_entries} qbe ON qbe.id=qv.questionbankentryid WHERE qbe.questioncategoryid=$CAT_ID AND qv.status='ready' ORDER BY q.id\"); foreach(\$qs as \$q){ quiz_add_quiz_question(\$q->id, \$quiz); } echo count(\$qs).' questions added';"
run_moosh php:eval "$ADD_PHP" -p "$MOODLE_PATH"
assert_output_contains "3 questions added" "3 questions added" "$OUT"
echo ""


# ── quiz:export (default — question bank XML) ────────────────────

echo "========== quiz:export (default) =========="
echo ""

echo "--- Export quiz ---"
run_moosh quiz:export $QUIZ_CMID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Export exit code 0" 0 $EC
assert_output_contains "Has XML declaration" '<?xml version="1.0"' "$OUT"
assert_output_contains "Has <quiz> root" "<quiz>" "$OUT"
assert_output_contains "Has </quiz>" "</quiz>" "$OUT"
assert_output_contains "Has multichoice question" "<question type=\"multichoice\">" "$OUT"
assert_output_contains "Has truefalse question" "<question type=\"truefalse\">" "$OUT"
assert_output_contains "Has Capital of France" "Capital of France" "$OUT"
assert_output_contains "Has Largest planet" "Largest planet" "$OUT"
assert_output_contains "Has WithImage" "WithImage" "$OUT"
assert_output_not_contains "No moosh-quiz wrapper" "<moosh-quiz>" "$OUT"
echo ""

echo "--- Image is inlined as base64 <file> ---"
# The exact base64 prefix of the imported 1x1 PNG must reappear in the export.
assert_output_contains "Has <file ... encoding=\"base64\"" 'encoding="base64"' "$OUT"
assert_output_contains "Has dot.png filename" 'name="dot.png"' "$OUT"
assert_output_contains "Has PNG base64 prefix" "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB" "$OUT"
echo ""


# ── quiz:export --with-quiz (moosh wrapper) ──────────────────────

echo "========== quiz:export --with-quiz =========="
echo ""

echo "--- Export with quiz settings wrapper ---"
run_moosh quiz:export $QUIZ_CMID --with-quiz -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Wrapper export exit code 0" 0 $EC
assert_output_contains "Has <moosh-quiz>" "<moosh-quiz>" "$OUT"
assert_output_contains "Has </moosh-quiz>" "</moosh-quiz>" "$OUT"
assert_output_contains "Has <quizinfo>" "<quizinfo>" "$OUT"
assert_output_contains "Has quiz name in info" "<name>TestQuiz</name>" "$OUT"
assert_output_contains "Has preferredbehaviour" "<preferredbehaviour>" "$OUT"
assert_output_contains "Has grademethod" "<grademethod>" "$OUT"
assert_output_contains "Has <slots>" "<slots>" "$OUT"
assert_output_contains "Has slot 1" 'slot="1"' "$OUT"
assert_output_contains "Has slot 2" 'slot="2"' "$OUT"
assert_output_contains "Has slot 3" 'slot="3"' "$OUT"
assert_output_contains "Has <questions>" "<questions>" "$OUT"
assert_output_contains "Question payload preserved" "Capital of France" "$OUT"
assert_output_contains "Image payload preserved" "iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB" "$OUT"
echo ""


# ── Error paths ──────────────────────────────────────────────────

echo "========== Error paths =========="
echo ""

echo "--- Invalid cmid (zero) ---"
run_moosh quiz:export 0 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for cmid=0" 1 $EC
assert_output_contains "Reports invalid cmid" "Invalid course module ID" "$OUT"
echo ""

echo "--- Nonexistent cmid ---"
run_moosh quiz:export 99999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for missing cmid" 1 $EC
assert_output_contains "Reports not found" "not found" "$OUT"
echo ""

echo "--- Cmid points at non-quiz module ---"
# Create a forum (cmid is a forum, not a quiz) and pass it in.
run_moosh activity:create -p "$MOODLE_PATH" --run --name "NotAQuiz" forum 2 -o csv
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh quiz:export $FORUM_CMID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for non-quiz cmid" 1 $EC
echo ""

echo "--- Empty quiz (no slots) ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "EmptyQuiz" quiz 2 -o csv
EMPTY_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh quiz:export $EMPTY_CMID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 0 for empty quiz" 0 $EC
assert_output_contains "Reports empty quiz" "no slots" "$OUT"
echo ""


# ── Help ─────────────────────────────────────────────────────────

echo "========== Help =========="
echo ""

echo "--- Help text ---"
run_moosh quiz:export -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Export a quiz" "$OUT"
assert_output_contains "Help shows --with-quiz" "--with-quiz" "$OUT"
assert_output_contains "Help mentions base64" "base64" "$OUT"
echo ""


print_summary
