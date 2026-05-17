#!/usr/bin/env bash
#
# Integration test for moosh2 context:search command
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_context_search.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 context:search integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# Discover test data IDs via direct PHP so tests are not coupled to
# hardcoded context IDs (which vary per install).
echo "Discovering test data IDs..."
COURSE_ID=$($PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
\$c = \$DB->get_record('course', ['shortname' => 'algebrafundamentals_2']);
echo \$c->id;
" 2>/dev/null)
CMID=$($PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
\$cm = \$DB->get_record_sql('SELECT id FROM {course_modules} WHERE course = ? LIMIT 1', [$COURSE_ID]);
echo \$cm->id;
" 2>/dev/null)
COURSE_CTX=$($PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
\$c = \$DB->get_record('context', ['contextlevel' => 50, 'instanceid' => $COURSE_ID]);
echo \$c->id;
" 2>/dev/null)
MODULE_CTX=$($PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
\$c = \$DB->get_record('context', ['contextlevel' => 70, 'instanceid' => $CMID]);
echo \$c->id;
" 2>/dev/null)
COURSE_PATH=$($PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
\$c = \$DB->get_record('context', ['contextlevel' => 50, 'instanceid' => $COURSE_ID]);
echo \$c->path;
" 2>/dev/null)
echo "  CourseID=$COURSE_ID, CMID=$CMID, CourseCtx=$COURSE_CTX, ModuleCtx=$MODULE_CTX, CoursePath=$COURSE_PATH"
echo ""

# ── No filters: returns all contexts ─────────────────────────────

echo "--- Test: No filters returns all contexts (CSV) ---"
run_moosh context:search -p "$MOODLE_PATH" -o csv
assert_output_contains "Header row present" 'id,contextlevel,instanceid,depth,path' "$OUT"
assert_output_contains "System context (level 10) present" ',10,' "$OUT"
assert_output_contains "Course context (level 50) present" ',50,' "$OUT"
assert_output_contains "Module context (level 70) present" ',70,' "$OUT"
echo ""

# ── Filter by level name ──────────────────────────────────────────

echo "--- Test: Filter by level=module (named) ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module -o csv
assert_output_contains "Header present" 'id,contextlevel,instanceid,depth,path' "$OUT"
assert_output_contains "Module level 70 in output" ',70,' "$OUT"
assert_output_not_contains "Course level 50 not in output" ',50,' "$OUT"
assert_output_not_contains "System level 10 not in output" ',10,' "$OUT"
echo ""

echo "--- Test: Filter by level=course (named) ---"
run_moosh context:search -p "$MOODLE_PATH" --level=course -o csv
assert_output_contains "Course level 50 in output" ',50,' "$OUT"
assert_output_not_contains "Module level 70 not in output" ',70,' "$OUT"
echo ""

echo "--- Test: Filter by numeric level 70 (module) ---"
run_moosh context:search -p "$MOODLE_PATH" --level=70 -o csv
assert_output_contains "Module level 70 in output" ',70,' "$OUT"
assert_output_not_contains "Course level 50 not in output" ',50,' "$OUT"
echo ""

# ── Filter by level + instanceid ─────────────────────────────────

echo "--- Test: Find module context for known cmid ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module --instanceid="$CMID" -o csv
assert_output_contains "Module level 70" ',70,' "$OUT"
assert_output_contains "Module context ID present" "$MODULE_CTX" "$OUT"
assert_output_contains "Instanceid matches cmid" ",$CMID," "$OUT"
echo ""

echo "--- Test: Find module context returns exactly one row ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module --instanceid="$CMID" -o csv
DATA_ROWS=$(echo "$OUT" | tail -n +2 | grep -c ',')
if [ "$DATA_ROWS" -eq 1 ]; then
    echo "  PASS: Exactly one result row"
    ((PASS++))
else
    echo "  FAIL: Expected 1 result row, got $DATA_ROWS"
    ((FAIL++))
fi
echo ""

echo "--- Test: Find course context for known course ID ---"
run_moosh context:search -p "$MOODLE_PATH" --level=course --instanceid="$COURSE_ID" -o csv
assert_output_contains "Course level 50" ',50,' "$OUT"
assert_output_contains "Course context ID present" "$COURSE_CTX" "$OUT"
echo ""

echo "--- Test: JSON output for module context lookup ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module --instanceid="$CMID" -o json
assert_output_contains "JSON has contextlevel 70" '"contextlevel": 70' "$OUT"
assert_output_contains "JSON has correct instanceid" "\"instanceid\": $CMID" "$OUT"
assert_output_contains "JSON has context ID" "\"id\": $MODULE_CTX" "$OUT"
echo ""

# ── Filter by path-contains ───────────────────────────────────────

echo "--- Test: path-contains finds descendants of course context ---"
run_moosh context:search -p "$MOODLE_PATH" --path-contains="${COURSE_PATH}/" -o csv
assert_output_contains "Module context found under course" ',70,' "$OUT"
assert_output_not_contains "Course context itself not in path children" "$COURSE_CTX,50" "$OUT"
echo ""

echo "--- Test: level + path-contains combined ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module --path-contains="${COURSE_PATH}/" -o csv
assert_output_contains "Module context present" ',70,' "$OUT"
assert_output_not_contains "No non-module contexts" ',50,' "$OUT"
echo ""

# ── instanceid alone (no level) ───────────────────────────────────

echo "--- Test: instanceid only (matches across all levels) ---"
run_moosh context:search -p "$MOODLE_PATH" --instanceid=1 -o csv
assert_output_contains "System context instanceid 1 present" ',1,' "$OUT"
echo ""

# ── Error paths ───────────────────────────────────────────────────

echo "--- Test: Invalid level name ---"
run_moosh context:search -p "$MOODLE_PATH" --level=bogus
EXIT_CODE=$?
assert_exit_code "Exit code is 1 for invalid level" 1 "$EXIT_CODE"
assert_output_contains "Error message mentions level" "bogus" "$OUT"
echo ""

echo "--- Test: nonexistent instanceid returns no rows (empty table output) ---"
run_moosh context:search -p "$MOODLE_PATH" --level=module --instanceid=999999 -o csv
assert_output_contains "Header still present" 'id,contextlevel,instanceid,depth,path' "$OUT"
DATA_ROWS=$(echo "$OUT" | tail -n +2 | grep -c ',' || true)
if [ "$DATA_ROWS" -eq 0 ]; then
    echo "  PASS: No result rows for nonexistent instanceid"
    ((PASS++))
else
    echo "  FAIL: Expected 0 result rows, got $DATA_ROWS"
    ((FAIL++))
fi
echo ""

# ── Output formats ────────────────────────────────────────────────

echo "--- Test: table output (default) ---"
run_moosh context:search -p "$MOODLE_PATH" --level=system
assert_output_contains "Table border present" '---' "$OUT"
assert_output_contains "contextlevel header in table" 'contextlevel' "$OUT"
echo ""

echo "--- Test: oneline output ---"
run_moosh context:search -p "$MOODLE_PATH" --level=system -o oneline
LINE_COUNT=$(echo "$OUT" | grep -c '.' || true)
if [ "$LINE_COUNT" -le 1 ]; then
    echo "  PASS: Oneline output is a single line"
    ((PASS++))
else
    echo "  FAIL: Expected 1 line, got $LINE_COUNT"
    ((FAIL++))
fi
echo ""

# ── Help ──────────────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh context:search -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Search contexts" "$OUT"
assert_output_contains "Help shows --level option" "--level" "$OUT"
assert_output_contains "Help shows --instanceid option" "--instanceid" "$OUT"
assert_output_contains "Help shows --path-contains option" "--path-contains" "$OUT"
echo ""

print_summary
