#!/usr/bin/env bash
#
# Integration test for moosh2 content:search command
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_content_search.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 content:search integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# Seed unique markers into representative columns:
#   - course.fullname          (CHAR, static known column)
#   - course.summary           (TEXT with summaryformat companion)
#   - page.content             (TEXT with contentformat companion)
#   - forum_posts.message      (TEXT with messageformat companion)
#   - glossary_entries.concept (CHAR, static known column)
#
# UNIQ_TOKEN is unique-per-run so we never collide with leftover data.
UNIQ_TOKEN="MooshSearchMarker_$$_$(date +%s)"
LOWER_TOKEN="$(echo "$UNIQ_TOKEN" | tr 'A-Z' 'a-z')"

echo "--- Seeding known content with token: $UNIQ_TOKEN ---"
$PHP -r "
define('CLI_SCRIPT', true);
require('$MOODLE_PATH/config.php');
global \$DB;

// 1. course.fullname + course.summary
\$courseid = \$DB->get_field('course', 'id', ['shortname' => 'C1']);
if (!\$courseid) {
    \$courses = \$DB->get_records('course', null, 'id ASC', 'id', 0, 1);
    \$first = reset(\$courses);
    \$courseid = \$first->id;
}
\$DB->set_field('course', 'fullname',
    'Course $UNIQ_TOKEN Title', ['id' => \$courseid]);
\$DB->set_field('course', 'summary',
    '<p>Course summary containing $UNIQ_TOKEN body.</p>', ['id' => \$courseid]);

// 2. page activity content (create one in course)
require_once(\$CFG->dirroot . '/course/modlib.php');
\$pagemod = new stdClass();
\$pagemod->course = \$courseid;
\$pagemod->name = 'Page $UNIQ_TOKEN';
\$pagemod->intro = 'intro';
\$pagemod->introformat = FORMAT_HTML;
\$pagemod->content = '<p>Page body has $UNIQ_TOKEN inside.</p>';
\$pagemod->contentformat = FORMAT_HTML;
\$pagemod->display = 0;
\$pagemod->displayoptions = serialize([]);
\$pagemod->revision = 1;
\$pagemod->timemodified = time();
\$pageid = \$DB->insert_record('page', \$pagemod);
echo 'page_id=' . \$pageid . PHP_EOL;

// 3. forum_posts.message — pick the first existing post if any, else add one
\$post = \$DB->get_record_sql('SELECT id FROM {forum_posts} ORDER BY id ASC', null, IGNORE_MULTIPLE);
if (\$post) {
    \$DB->set_field('forum_posts', 'message',
        '<p>Forum post mentions $UNIQ_TOKEN openly.</p>', ['id' => \$post->id]);
}

// 4. glossary_entries.concept (CHAR static-known column)
\$entry = \$DB->get_record_sql('SELECT id FROM {glossary_entries} ORDER BY id ASC', null, IGNORE_MULTIPLE);
if (\$entry) {
    \$DB->set_field('glossary_entries', 'concept',
        'Term $UNIQ_TOKEN', ['id' => \$entry->id]);
}

echo 'course_id=' . \$courseid . PHP_EOL;
" 2>&1
echo ""

# ── Substring search (CSV) ────────────────────────────────────────

echo "--- Test: Substring search across DB (CSV) ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o csv
assert_output_contains "Header row" "table,id,column,snippet" "$OUT"
assert_output_contains "Hits course.fullname" "course,"        "$OUT"
assert_output_contains "Hits course.summary"  "summary"        "$OUT"
assert_output_contains "Hits page.content"    "page,"          "$OUT"
assert_output_contains "Hits page content col" "content"       "$OUT"
assert_output_contains "Snippet has token"    "$UNIQ_TOKEN"    "$OUT"
echo ""

# ── Case-insensitive by default ───────────────────────────────────

echo "--- Test: Case-insensitive default ---"
run_moosh content:search "$LOWER_TOKEN" -p "$MOODLE_PATH" -o csv
assert_output_contains "Lower-case still hits" "$UNIQ_TOKEN" "$OUT"
echo ""

# ── --case-sensitive misses lowercase ─────────────────────────────

echo "--- Test: --case-sensitive misses lowercase ---"
run_moosh content:search "$LOWER_TOKEN" -p "$MOODLE_PATH" -o csv --case-sensitive
LINE_COUNT=$(echo "$OUT" | wc -l)
assert_output_contains "Only header line" "1" "$LINE_COUNT"
echo ""

# ── --tables filter ───────────────────────────────────────────────

echo "--- Test: --tables limits scope ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o csv --tables=page
assert_output_contains "Hits page" "page," "$OUT"
assert_output_not_contains "No course rows" "course," "$OUT"
assert_output_not_contains "No forum rows"  "forum_posts," "$OUT"
echo ""

# ── --skip-tables excludes ────────────────────────────────────────

echo "--- Test: --skip-tables excludes ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o csv --skip-tables=course
assert_output_not_contains "No course rows" "course," "$OUT"
assert_output_contains "Page still present" "page," "$OUT"
echo ""

# ── --exact match ─────────────────────────────────────────────────

echo "--- Test: --exact requires whole-value match ---"
# course.fullname is "Course $UNIQ_TOKEN Title", not equal to bare token.
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o csv --exact --tables=course
LINE_COUNT=$(echo "$OUT" | wc -l)
assert_output_contains "No exact match in course" "1" "$LINE_COUNT"
echo ""

echo "--- Test: --exact matches whole value ---"
run_moosh content:search "Course $UNIQ_TOKEN Title" -p "$MOODLE_PATH" -o csv --exact --tables=course
assert_output_contains "Exact match found" "fullname" "$OUT"
echo ""

# ── JSON output ───────────────────────────────────────────────────

echo "--- Test: JSON output ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o json --limit 2
assert_output_contains "JSON has table"   '"table"'   "$OUT"
assert_output_contains "JSON has column"  '"column"'  "$OUT"
assert_output_contains "JSON has snippet" '"snippet"' "$OUT"
echo ""

# ── Table output ──────────────────────────────────────────────────

echo "--- Test: Table output ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" --limit 2
assert_output_contains "Table header table"   "table"   "$OUT"
assert_output_contains "Table header column"  "column"  "$OUT"
assert_output_contains "Table header snippet" "snippet" "$OUT"
echo ""

# ── --limit caps per-table ────────────────────────────────────────

echo "--- Test: --limit caps results per table ---"
run_moosh content:search "$UNIQ_TOKEN" -p "$MOODLE_PATH" -o csv --limit 1 --tables=course
DATA_LINES=$(echo "$OUT" | tail -n +2 | wc -l)
[ "$DATA_LINES" -le 2 ] && echo "  PASS: limited rows ($DATA_LINES <= 2)" && ((PASS++)) || { echo "  FAIL: too many rows ($DATA_LINES)"; ((FAIL++)); }
echo ""

# ── No matches ────────────────────────────────────────────────────

echo "--- Test: No matches returns header only ---"
run_moosh content:search "ZZZ_NOPE_$$_NOMATCH" -p "$MOODLE_PATH" -o csv
LINE_COUNT=$(echo "$OUT" | wc -l)
assert_output_contains "Only header line" "1" "$LINE_COUNT"
echo ""

# ── Validation: missing argument ──────────────────────────────────

echo "--- Test: Missing pattern argument ---"
run_moosh content:search -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit nonzero for missing arg" 1 $EC
echo ""

# ── Help output ───────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh content:search -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Search text in user-visible content" "$OUT"
assert_output_contains "Help shows --exact"          "--exact"          "$OUT"
assert_output_contains "Help shows --case-sensitive" "--case-sensitive" "$OUT"
assert_output_contains "Help shows --tables"         "--tables"         "$OUT"
assert_output_contains "Help shows --skip-tables"    "--skip-tables"    "$OUT"
assert_output_contains "Help shows --limit"          "--limit"          "$OUT"
assert_output_contains "Help shows --snippet-length" "--snippet-length" "$OUT"
echo ""

print_summary
