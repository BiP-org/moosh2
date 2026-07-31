#!/usr/bin/env bash
#
# Integration test for moosh2 course:create command
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_course_create.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 course:create integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: Dry run (no --run) ---"
run_moosh course:create -p "$MOODLE_PATH" newcourse1
assert_output_contains "Shows dry run message" "Dry run" "$OUT"
assert_output_contains "Shows shortname" "newcourse1" "$OUT"
assert_output_contains "Shows category" "category: 1" "$OUT"
# Verify course was NOT created
run_moosh course:list -p "$MOODLE_PATH" --sql "shortname = 'newcourse1'" -o csv
VERIFY="$OUT"
assert_output_not_contains "Course not created without --run" "newcourse1" "$VERIFY"
echo ""

# ── Create single course ──────────────────────────────────────────

echo "--- Test: Create single course ---"
run_moosh course:create -p "$MOODLE_PATH" --run --category 2 newcourse1 -o csv
assert_output_contains "Output has header" "id,shortname,fullname,category" "$OUT"
assert_output_contains "Output has shortname" "newcourse1" "$OUT"
assert_output_contains "Category is 2" ",2" "$OUT"
# Verify course exists
run_moosh course:list -p "$MOODLE_PATH" --sql "shortname = 'newcourse1'" -o csv
VERIFY="$OUT"
assert_output_contains "Course exists after create" "newcourse1" "$VERIFY"
echo ""

# ── Create multiple courses ───────────────────────────────────────

echo "--- Test: Create multiple courses ---"
run_moosh course:create -p "$MOODLE_PATH" --run --category 3 multi1 multi2 multi3 -o csv
assert_output_contains "First course created" "multi1" "$OUT"
assert_output_contains "Second course created" "multi2" "$OUT"
assert_output_contains "Third course created" "multi3" "$OUT"
echo ""

# ── Create course with options ────────────────────────────────────

echo "--- Test: Create course with all options ---"
run_moosh course:create -p "$MOODLE_PATH" --run \
    --category 4 \
    --fullname "Advanced Mathematics" \
    --idnumber "MATH301" \
    --visible 1 \
    --numsections 10 \
    --newsitems 7 \
    advmath -o csv
assert_output_contains "Created advmath" "advmath" "$OUT"
assert_output_contains "Full name" "Advanced Mathematics" "$OUT"
# Verify newsitems was persisted (course:list does not expose newsitems, query DB directly).
NEWSITEMS=$(mysql $MYSQL_OPTS -uroot -pa -N -B moodle52 -e "SELECT newsitems FROM mdl_course WHERE shortname='advmath';" 2>/dev/null)
assert_output_contains "newsitems persisted as 7" "7" "$NEWSITEMS"
echo ""

# ── newsitems option ──────────────────────────────────────────────

echo "--- Test: Create course with --newsitems=0 (hide announcements block) ---"
run_moosh course:create -p "$MOODLE_PATH" --run --category 2 --newsitems 0 quietcourse -o csv
assert_output_contains "Created quietcourse" "quietcourse" "$OUT"
NEWSITEMS=$(mysql $MYSQL_OPTS -uroot -pa -N -B moodle52 -e "SELECT newsitems FROM mdl_course WHERE shortname='quietcourse';" 2>/dev/null)
assert_output_contains "newsitems is 0" "0" "$NEWSITEMS"
echo ""

# ── Create hidden course ──────────────────────────────────────────

echo "--- Test: Create hidden course ---"
run_moosh course:create -p "$MOODLE_PATH" --run --category 2 --visible 0 hiddencourse -o csv
assert_output_contains "Created hidden course" "hiddencourse" "$OUT"
# Verify it's hidden
run_moosh course:list -p "$MOODLE_PATH" --sql "shortname = 'hiddencourse'" -o csv
VERIFY="$OUT"
assert_output_contains "Course shows visible 0" ",0" "$VERIFY"
echo ""

# ── JSON output ───────────────────────────────────────────────────

echo "--- Test: JSON output ---"
run_moosh course:create -p "$MOODLE_PATH" --run --category 2 jsoncourse -o json
assert_output_contains "JSON has shortname" '"shortname"' "$OUT"
assert_output_contains "JSON has jsoncourse" '"jsoncourse"' "$OUT"
echo ""

# ── Help output ───────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh course:create -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Create Moodle courses" "$OUT"
assert_output_contains "Help shows --category" "--category" "$OUT"
assert_output_contains "Help shows --fullname" "--fullname" "$OUT"
assert_output_contains "Help shows --format" "--format" "$OUT"
assert_output_contains "Help shows --visible" "--visible" "$OUT"
assert_output_contains "Help shows --numsections" "--numsections" "$OUT"
assert_output_contains "Help shows --newsitems" "--newsitems" "$OUT"
echo ""

# ── course-create alias ──────────────────────────────────────────


print_summary
