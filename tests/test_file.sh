#!/usr/bin/env bash
#
# Integration tests for moosh2 file commands
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_file.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 file commands integration tests ==="
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
#  file:stats
# ═══════════════════════════════════════════════════════════════════

echo "========== file:stats =========="
echo ""

echo "--- Test: Basic stats ---"
run_moosh file:stats -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Stats exit code 0" 0 $EC
assert_output_contains "Shows total" "Total file records" "$OUT"
assert_output_contains "Shows unique" "Unique content" "$OUT"
echo ""

echo "--- Test: By component ---"
run_moosh file:stats --by-component -p "$MOODLE_PATH"
assert_output_contains "Shows by component" "By Component" "$OUT"
assert_output_contains "Shows a component" "component" "$OUT"
echo ""

echo "--- Test: Top largest ---"
run_moosh file:stats --top 5 -p "$MOODLE_PATH"
assert_output_contains "Shows top files" "Top 5 Largest" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh file:stats -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV has metric" "Metric,Value" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh file:stats -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Show file storage statistics" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  file:list
# ═══════════════════════════════════════════════════════════════════

echo "========== file:list =========="
echo ""

echo "--- Test: List by course ---"
run_moosh file:list --courseid 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "List exit code 0" 0 $EC
assert_output_contains "Shows filename" "coursefile" "$OUT"
echo ""

echo "--- Test: List by component ---"
run_moosh file:list --component mod_resource -p "$MOODLE_PATH"
assert_output_contains "Shows resource files" "mod_resource" "$OUT"
echo ""

echo "--- Test: CSV output ---"
run_moosh file:list --courseid 2 -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "id,filename,component" "$OUT"
echo ""

echo "--- Test: ID-only ---"
run_moosh file:list --courseid 2 -p "$MOODLE_PATH" --id-only
assert_output_not_empty "ID-only not empty" "$OUT"
FILE_ID=$(echo "$OUT" | tr ' ' '\n' | head -1)
echo "  First file ID: $FILE_ID"
echo ""

echo "--- Test: No filter ---"
run_moosh file:list -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no filter" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh file:list -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "List files" "$OUT"
assert_output_contains "Help shows --courseid" "--courseid" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  file:info
# ═══════════════════════════════════════════════════════════════════

echo "========== file:info =========="
echo ""

echo "--- Test: Info by ID ---"
run_moosh file:info $FILE_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Info exit code 0" 0 $EC
assert_output_contains "Shows file ID" "File ID" "$OUT"
assert_output_contains "Shows content hash" "Content hash" "$OUT"
assert_output_contains "Shows physical path" "Physical path" "$OUT"
assert_output_contains "Shows exists" "Exists on disk" "$OUT"
assert_output_contains "Shows component" "Component" "$OUT"
echo ""

# Get the hash for hash lookup test
run_moosh file:info $FILE_ID -p "$MOODLE_PATH" -o csv
HASH=$(echo "$OUT" | grep "Content hash" | cut -d, -f2)
echo "  File hash: $HASH"

echo "--- Test: Info by hash ---"
run_moosh file:info --hash "$HASH" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Hash info exit code 0" 0 $EC
assert_output_contains "Shows file info" "File ID" "$OUT"
echo ""

echo "--- Test: Invalid ID ---"
run_moosh file:info 99999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
echo ""

echo "--- Test: No args ---"
run_moosh file:info -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no args" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh file:info -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Show detailed file information" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  file:check
# ═══════════════════════════════════════════════════════════════════

echo "========== file:check =========="
echo ""

echo "--- Test: Check missing ---"
run_moosh file:check --missing -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Check missing exit code 0" 0 $EC
assert_output_contains "Shows checked" "Checked" "$OUT"
assert_output_contains "Shows missing result" "missing" "$OUT"
echo ""

echo "--- Test: Check orphaned ---"
run_moosh file:check --orphaned -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Check orphaned exit code 0" 0 $EC
assert_output_contains "Shows orphaned result" "Checked" "$OUT"
echo ""

echo "--- Test: Default (missing) ---"
run_moosh file:check -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Default check exit code 0" 0 $EC
assert_output_contains "Shows missing section" "Missing Files" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh file:check -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Check file storage consistency" "$OUT"
assert_output_contains "Help shows --missing" "--missing" "$OUT"
assert_output_contains "Help shows --orphaned" "--orphaned" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  file:delete
# ═══════════════════════════════════════════════════════════════════

echo "========== file:delete =========="
echo ""

# Get context ID for course 2
run_moosh sql:select -p "$MOODLE_PATH" "SELECT id FROM mdl_context WHERE contextlevel=50 AND instanceid=2" -o csv
CTX_ID=$(echo "$OUT" | tail -1)
echo "  Course 2 context ID: $CTX_ID"

# Upload a file to delete
echo "delete me" > "$TMPDIR/deleteme.txt"
run_moosh file:upload "$TMPDIR/deleteme.txt" --contextid $CTX_ID --component course --filearea summary --itemid 999 -p "$MOODLE_PATH" --run -o csv
DEL_OUT="$OUT"
DEL_FILE_ID=$(echo "$DEL_OUT" | tail -1 | cut -d, -f1)
echo "  File to delete ID: $DEL_FILE_ID"

echo "--- Test: Delete dry run ---"
run_moosh file:delete $DEL_FILE_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Delete by ID ---"
run_moosh file:delete $DEL_FILE_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: No args ---"
run_moosh file:delete -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no args" 1 $EC
echo ""

echo "--- Test: Invalid ID ---"
run_moosh file:delete 99999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh file:delete -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Delete files" "$OUT"
assert_output_contains "Help shows --hash" "--hash" "$OUT"
echo ""


print_summary
