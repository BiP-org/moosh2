#!/usr/bin/env bash
#
# Integration test for moosh2 activity:create, activity:delete, activity:mod commands
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_activity.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 activity commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh" || exit 1
echo ""

# Test data: Course 2 (Algebra Fundamentals) has 1 resource activity (cmid=1), 4 sections

# ═══════════════════════════════════════════════════════════════════
# activity:create
# ═══════════════════════════════════════════════════════════════════

echo "========== activity:create =========="
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: activity:create dry run ---"
run_moosh activity:create -p "$MOODLE_PATH" forum 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows activity type" "forum" "$OUT"
echo ""

# ── Add forum ─────────────────────────────────────────────────────

echo "--- Test: Add forum to course 2 ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Discussion Forum" forum 2 -o csv
assert_output_contains "Header row" "cmid,module,instance,course,section" "$OUT"
assert_output_contains "Module is forum" ",forum," "$OUT"
assert_output_contains "Course is 2" ",2," "$OUT"
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created forum cmid=$FORUM_CMID"
echo ""

# ── Add assignment ────────────────────────────────────────────────

echo "--- Test: Add assignment to section 2 ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Homework 1" --section 2 assign 2 -o csv
assert_output_contains "Module is assign" ",assign," "$OUT"
assert_output_contains "Section is 2" ",2" "$OUT"
ASSIGN_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created assign cmid=$ASSIGN_CMID"
echo ""

# ── Add page ──────────────────────────────────────────────────────

echo "--- Test: Add page with idnumber ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Welcome Page" --idnumber "PAGE001" page 2 -o csv
assert_output_contains "Module is page" ",page," "$OUT"
PAGE_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created page cmid=$PAGE_CMID"
echo ""

# ── JSON output ───────────────────────────────────────────────────

echo "--- Test: activity:create JSON output ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "JSON URL" url 2 -o json
assert_output_contains "JSON has cmid" '"cmid"' "$OUT"
assert_output_contains "JSON has module" '"module"' "$OUT"
URL_CMID=$(echo "$OUT" | grep -o '"cmid": [0-9]*' | head -1 | grep -o '[0-9]*')
echo "  Created url cmid=$URL_CMID"
echo ""

# ── Add choice ────────────────────────────────────────────────────

echo "--- Test: Add choice ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Pick One" choice 2 -o csv
assert_output_contains "Module is choice" ",choice," "$OUT"
CHOICE_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created choice cmid=$CHOICE_CMID"
echo ""

# ── Add feedback ─────────────────────────────────────────────────

echo "--- Test: Add feedback ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Course Feedback" feedback 2 -o csv
assert_output_contains "Module is feedback" ",feedback," "$OUT"
FEEDBACK_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created feedback cmid=$FEEDBACK_CMID"
echo ""

# ── Add folder ───────────────────────────────────────────────────

echo "--- Test: Add folder ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Resources Folder" folder 2 -o csv
assert_output_contains "Module is folder" ",folder," "$OUT"
FOLDER_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created folder cmid=$FOLDER_CMID"
echo ""

# ── Add glossary ─────────────────────────────────────────────────

echo "--- Test: Add glossary ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Key Terms" glossary 2 -o csv
assert_output_contains "Module is glossary" ",glossary," "$OUT"
GLOSSARY_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created glossary cmid=$GLOSSARY_CMID"
echo ""

# ── Add label ────────────────────────────────────────────────────

echo "--- Test: Add label ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Section Header" label 2 -o csv
assert_output_contains "Module is label" ",label," "$OUT"
LABEL_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created label cmid=$LABEL_CMID"
echo ""

# ── Add lesson ───────────────────────────────────────────────────

echo "--- Test: Add lesson ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Intro Lesson" lesson 2 -o csv
assert_output_contains "Module is lesson" ",lesson," "$OUT"
LESSON_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created lesson cmid=$LESSON_CMID"
echo ""

# ── Add quiz ─────────────────────────────────────────────────────

echo "--- Test: Add quiz ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Chapter Quiz" quiz 2 -o csv
assert_output_contains "Module is quiz" ",quiz," "$OUT"
QUIZ_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created quiz cmid=$QUIZ_CMID"
echo ""

# ── Add wiki ─────────────────────────────────────────────────────

echo "--- Test: Add wiki ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Class Wiki" wiki 2 -o csv
assert_output_contains "Module is wiki" ",wiki," "$OUT"
WIKI_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created wiki cmid=$WIKI_CMID"
echo ""

# ── Add workshop ─────────────────────────────────────────────────

echo "--- Test: Add workshop ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Peer Review" workshop 2 -o csv
assert_output_contains "Module is workshop" ",workshop," "$OUT"
WORKSHOP_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created workshop cmid=$WORKSHOP_CMID"
echo ""

# ── Add book ─────────────────────────────────────────────────────

echo "--- Test: Add book ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Course Handbook" book 2 -o csv
assert_output_contains "Module is book" ",book," "$OUT"
BOOK_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created book cmid=$BOOK_CMID"
echo ""

# ── Add data ─────────────────────────────────────────────────────

echo "--- Test: Add database ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Student Records" data 2 -o csv
assert_output_contains "Module is data" ",data," "$OUT"
DATA_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created data cmid=$DATA_CMID"
echo ""

# ── Add h5pactivity ──────────────────────────────────────────────

echo "--- Test: Add h5pactivity ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Interactive Content" h5pactivity 2 -o csv
assert_output_contains "Module is h5pactivity" ",h5pactivity," "$OUT"
H5P_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created h5pactivity cmid=$H5P_CMID"
echo ""

# ── --set option: override module defaults ───────────────────────

echo "--- Test: --set override wiki mode ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Individual Wiki" --set wikimode=individual wiki 2 -o csv
assert_output_contains "Module is wiki (--set)" ",wiki," "$OUT"
WIKI2_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created wiki cmid=$WIKI2_CMID"
echo ""

echo "--- Test: --set multiple values ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "Custom Forum" -S type=single -S assessed=1 forum 2 -o csv
assert_output_contains "Module is forum (--set multi)" ",forum," "$OUT"
FORUM2_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Created forum cmid=$/cleaFORUM2_CMID"
echo ""

echo "--- Test: --set invalid format ---"
run_moosh activity:create -p "$MOODLE_PATH" --run -S badformat label 2
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad --set" 1 "$EXIT_CODE"
assert_output_contains "Error for bad --set" "Invalid --set format" "$OUT"
echo ""

# ── Invalid module type ───────────────────────────────────────────

echo "--- Test: Invalid module type ---"
run_moosh activity:create -p "$MOODLE_PATH" --run nonexistent 2
EXIT_CODE=$?
assert_exit_code "Exit code 1 for invalid type" 1 "$EXIT_CODE"
assert_output_contains "Error for unknown type" "Unknown activity type" "$OUT"
echo ""

# ── Help output ───────────────────────────────────────────────────

echo "--- Test: activity:create help ---"
run_moosh activity:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create an activity in a course" "$OUT"
assert_output_contains "Help shows --name" "--name" "$OUT"
assert_output_contains "Help shows --section" "--section" "$OUT"
echo ""

# ── Alias ─────────────────────────────────────────────────────────


# ═══════════════════════════════════════════════════════════════════
# activity:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== activity:mod =========="
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: activity:mod dry run ---"
run_moosh activity:mod -p "$MOODLE_PATH" --name "New Name" $FORUM_CMID
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows name change" "name:" "$OUT"
echo ""

# ── Rename activity ───────────────────────────────────────────────

echo "--- Test: Rename activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Renamed Forum" $FORUM_CMID -o csv
assert_output_contains "Shows renamed name" "Renamed Forum" "$OUT"
echo ""

# ── Change visibility ─────────────────────────────────────────────

echo "--- Test: Hide activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --visible 0 $FORUM_CMID -o csv
assert_output_contains "Shows visible 0" ",0," "$OUT"
echo ""

echo "--- Test: Show activity ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --visible 1 $FORUM_CMID -o csv
assert_output_contains "Shows visible 1" ",1," "$OUT"
echo ""

# ── Set idnumber ──────────────────────────────────────────────────

echo "--- Test: Set idnumber ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --idnumber "FORUM001" $FORUM_CMID -o csv
assert_output_contains "Shows idnumber" "FORUM001" "$OUT"
echo ""

# ── Move to different section ─────────────────────────────────────

echo "--- Test: Move to section 3 ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --section 3 $FORUM_CMID -o csv
assert_output_contains "Moved to section 3" ",3," "$OUT"
echo ""

echo "--- Test: Move back to section 1 ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --section 1 $FORUM_CMID -o csv
assert_output_contains "Moved to section 1" ",1," "$OUT"
echo ""

# ── Multiple changes at once ──────────────────────────────────────

echo "--- Test: Multiple changes at once ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Final Forum" --visible 0 --idnumber "FIN001" $FORUM_CMID -o json
assert_output_contains "JSON name changed" '"Final Forum"' "$OUT"
assert_output_contains "JSON idnumber set" '"FIN001"' "$OUT"
echo ""

# ── --set option: modify module properties ───────────────────────

echo "--- Test: --set single property ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run -S type=single $FORUM_CMID -o csv
assert_output_contains "Shows updated forum" "Final Forum" "$OUT"
echo ""

echo "--- Test: --set multiple properties ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --set type=general --set assessed=1 $FORUM_CMID -o csv
assert_output_contains "Shows forum after multi-set" "Final Forum" "$OUT"
echo ""

echo "--- Test: --set dry run ---"
run_moosh activity:mod -p "$MOODLE_PATH" -S type=blog $FORUM_CMID
assert_output_contains "Shows dry run for --set" "Dry run" "$OUT"
assert_output_contains "Shows --set change" "type:" "$OUT"
echo ""

echo "--- Test: --set invalid format ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run -S badformat $FORUM_CMID
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad --set" 1 "$EXIT_CODE"
assert_output_contains "Error for bad --set" "Invalid --set format" "$OUT"
echo ""

echo "--- Test: --set combined with --name ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "Set And Name Forum" -S type=single $FORUM_CMID -o csv
assert_output_contains "Shows new name with --set" "Set And Name Forum" "$OUT"
echo ""

# ── No modification specified ─────────────────────────────────────

echo "--- Test: No modification specified ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run $FORUM_CMID
EXIT_CODE=$?
assert_exit_code "Exit code 1 for no modification" 1 "$EXIT_CODE"
assert_output_contains "Error for no modification" "No modifications specified" "$OUT"
echo ""

# ── Invalid cmid ──────────────────────────────────────────────────

echo "--- Test: Invalid cmid ---"
run_moosh activity:mod -p "$MOODLE_PATH" --run --name "X" 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for invalid cmid" 1 "$EXIT_CODE"
assert_output_contains "Error for invalid cmid" "not found" "$OUT"
echo ""

# ── Help ──────────────────────────────────────────────────────────

echo "--- Test: activity:mod help ---"
run_moosh activity:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify an activity" "$OUT"
assert_output_contains "Help shows --name" "--name" "$OUT"
assert_output_contains "Help shows --visible" "--visible" "$OUT"
assert_output_contains "Help shows --section" "--section" "$OUT"
assert_output_contains "Help shows --before" "--before" "$OUT"
assert_output_contains "Help shows --set" "--set" "$OUT"
echo ""

# ── Alias ─────────────────────────────────────────────────────────


# ═══════════════════════════════════════════════════════════════════
# activity:delete
# ═══════════════════════════════════════════════════════════════════

echo "========== activity:delete =========="
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: activity:delete dry run ---"
run_moosh activity:delete -p "$MOODLE_PATH" $FORUM_CMID
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows cmid" "cmid=$FORUM_CMID" "$OUT"
assert_output_contains "Shows type" "forum" "$OUT"
echo ""

# ── Delete single activity ────────────────────────────────────────

echo "--- Test: Delete single activity ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run $FORUM_CMID
assert_output_contains "Deleted message" "Deleted" "$OUT"
assert_output_contains "Shows forum type" "forum" "$OUT"
echo ""

# ── Delete multiple activities ────────────────────────────────────

echo "--- Test: Delete multiple activities ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run $ASSIGN_CMID $PAGE_CMID
assert_output_contains "First deleted" "Deleted" "$OUT"
echo ""

# ── Invalid cmid ──────────────────────────────────────────────────

echo "--- Test: Delete invalid cmid ---"
run_moosh activity:delete -p "$MOODLE_PATH" --run 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for invalid cmid" 1 "$EXIT_CODE"
assert_output_contains "Error for invalid cmid" "not found" "$OUT"
echo ""

# ── Help ──────────────────────────────────────────────────────────

echo "--- Test: activity:delete help ---"
run_moosh activity:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete activities" "$OUT"
assert_output_contains "Help shows cmid" "cmid" "$OUT"
echo ""

# ── Alias ─────────────────────────────────────────────────────────


print_summary
