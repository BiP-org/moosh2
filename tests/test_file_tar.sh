#!/usr/bin/env bash
#
# Integration test: file:list + file:info pipeline with tar archive
# Verifies that piping file:list IDs to file:info paths and creating
# a tar archive produces the correct number of files.
#
# Uses the "File-Rich Course" (10 resources × 10 files = 100 files).
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_file_tar.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 file tar archive integration test ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# Look up the File-Rich Course ID
echo "--- Getting File-Rich Course ID ---"
run_moosh -p "$MOODLE_PATH" sql:select "SELECT id FROM mdl_course WHERE fullname='File-Rich Course'" -o csv
COURSE_ID=$(echo "$OUT" | tail -1)
echo "  Course ID: $COURSE_ID"
echo ""

# Get file IDs for the course
echo "--- Test: file:list returns files for File-Rich Course ---"
run_moosh -p "$MOODLE_PATH" file:list --courseid "$COURSE_ID" -i --limit 500
EC=$?
assert_exit_code "file:list exit code 0" 0 $EC
assert_output_not_empty "file:list returns IDs" "$OUT"
FILE_IDS="$OUT"
FILE_COUNT=$(echo "$FILE_IDS" | tr ' ' '\n' | grep -c .)
echo "  Files found: $FILE_COUNT"
echo ""

# Run the piped command: file:list | file:info --stdin --field path
echo "--- Test: file:list -i | file:info --stdin --field path ---"
FILE_PATHS=$($PHP "$MOOSH" -p "$MOODLE_PATH" file:list --courseid "$COURSE_ID" -i --limit 500 2>&1 \
    | $PHP "$MOOSH" -p "$MOODLE_PATH" file:info --stdin --field path 2>&1)
assert_output_not_empty "Piped output not empty" "$FILE_PATHS"
PATH_COUNT=$(echo "$FILE_PATHS" | wc -l)
echo "  Paths returned: $PATH_COUNT"
echo ""

# Create tar archive from the paths
echo "--- Test: create tar archive from file paths ---"
echo "$FILE_PATHS" | tar czf "$TMPDIR/course-files.tar.gz" -T - 2>&1
EC=$?
assert_exit_code "tar creation exit code 0" 0 $EC
echo ""

# Count files in tar and compare with file:list count
echo "--- Test: tar file count matches file:list count ---"
TAR_COUNT=$(tar tzf "$TMPDIR/course-files.tar.gz" | wc -l)
echo "  Files in tar: $TAR_COUNT"
echo "  Files from file:list: $FILE_COUNT"

if [ "$TAR_COUNT" -eq "$FILE_COUNT" ]; then
    echo "  PASS: Tar file count matches file:list count ($TAR_COUNT)"
    ((PASS++))
else
    echo "  FAIL: Tar count ($TAR_COUNT) != file:list count ($FILE_COUNT)"
    ((FAIL++))
fi
echo ""

print_summary
