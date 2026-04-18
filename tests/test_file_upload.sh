#!/usr/bin/env bash
#
# Integration tests for moosh2 file:upload command
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_file_upload.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 file:upload integration tests ==="
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
#  file:upload
# ═══════════════════════════════════════════════════════════════════

echo "========== file:upload =========="
echo ""

# Create a test file
echo "Hello from moosh2 test" > "$TMPDIR/testfile.txt"

# Get context ID for course 2
run_moosh sql:select -p "$MOODLE_PATH" "SELECT id FROM mdl_context WHERE contextlevel=50 AND instanceid=2" -o csv
CTX_ID=$(echo "$OUT" | tail -1)
echo "  Course 2 context ID: $CTX_ID"

echo "--- Test: Dry run ---"
run_moosh file:upload "$TMPDIR/testfile.txt" --contextid $CTX_ID --component course --filearea summary -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
echo ""

echo "--- Test: Upload file (absolute path) ---"
run_moosh file:upload "$TMPDIR/testfile.txt" --contextid $CTX_ID --component course --filearea summary -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Upload exit code 0" 0 $EC
assert_output_contains "Shows filename" "testfile.txt" "$OUT"
assert_output_contains "Shows component" "course" "$OUT"
echo ""

echo "--- Test: Upload file (relative path from cwd) ---"
echo "Relative upload test" > "$TMPDIR/relativefile.txt"
ORIG_PWD="$PWD"
cd "$TMPDIR"
run_moosh file:upload relativefile.txt --contextid $CTX_ID --component course --filearea summary -p "$MOODLE_PATH" --run
EC=$?
cd "$ORIG_PWD"
assert_exit_code "Relative upload exit code 0" 0 $EC
assert_output_contains "Shows uploaded filename" "relativefile.txt" "$OUT"
assert_output_contains "Shows component" "course" "$OUT"
echo ""

echo "--- Test: Missing file ---"
run_moosh file:upload /nonexistent.txt --contextid $CTX_ID --component course --filearea summary -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for missing file" 1 $EC
echo ""

echo "--- Test: Missing options ---"
run_moosh file:upload "$TMPDIR/testfile.txt" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for missing options" 1 $EC
echo ""

echo "--- Test: Help ---"
run_moosh file:upload -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Upload a file" "$OUT"
assert_output_contains "Help shows --contextid" "--contextid" "$OUT"
echo ""


print_summary
