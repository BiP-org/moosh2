#!/usr/bin/env bash
#
# Integration test for moosh2 file:info command
# Requires a working Moodle installation with files in storage.
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_file_info.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 file:info integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Get a known file ID from file:list (first file in the system).
echo "--- Setup: finding a test file ID ---"
run_moosh -p "$MOODLE_PATH" file:list --component=user --limit=1 -i
FILE_ID=$(echo "$OUT" | tr -s ' ' '\n' | head -1)
echo "Using file ID: $FILE_ID"

if [ -z "$FILE_ID" ]; then
    echo "ERROR: Could not find any file. Trying with mod_resource..."
    run_moosh -p "$MOODLE_PATH" file:list --component=mod_resource --limit=1 -i
    FILE_ID=$(echo "$OUT" | tr -s ' ' '\n' | head -1)
fi

if [ -z "$FILE_ID" ]; then
    echo "ERROR: No files found in Moodle. Cannot run tests."
    exit 1
fi

# Get a second file ID for multi-ID tests.
run_moosh -p "$MOODLE_PATH" file:list --component=user --limit=2 -i
SECOND_ID=$(echo "$OUT" | tr -s ' ' '\n' | sed -n '2p')
echo "Second file ID: $SECOND_ID"
echo ""

# Test 1: Basic single file info
echo "--- Test: Single file info ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID"
assert_output_contains "Shows File ID" "File ID" "$OUT"
assert_output_contains "Shows Content hash" "Content hash" "$OUT"
assert_output_contains "Shows Physical path" "Physical path" "$OUT"
assert_output_contains "Shows Exists on disk" "Exists on disk" "$OUT"
assert_output_contains "Shows Filename" "Filename" "$OUT"
echo ""

# Test 2: JSON output
echo "--- Test: JSON output ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" -o json
assert_output_contains "JSON has File ID" "File ID" "$OUT"
assert_output_contains "JSON has Physical path" "Physical path" "$OUT"
echo ""

# Test 3: Multiple file IDs as arguments
if [ -n "$SECOND_ID" ]; then
    echo "--- Test: Multiple file IDs ---"
    run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" "$SECOND_ID"
    assert_output_contains "Separator present for multi-file" "---" "$OUT"
    echo ""
fi

# Test 4: --field path
echo "--- Test: --field path ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field path
assert_output_contains "Path contains filedir" "filedir" "$OUT"
assert_output_not_contains "No table headers in field mode" "Metric" "$OUT"
echo ""

# Test 5: --field filename
echo "--- Test: --field filename ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field filename
assert_output_not_empty "Filename output is not empty" "$OUT"
assert_output_not_contains "No table headers in field mode" "Metric" "$OUT"
echo ""

# Test 6: --field id
echo "--- Test: --field id ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field id
assert_output_contains "ID matches argument" "$FILE_ID" "$OUT"
echo ""

# Test 7: --field contenthash
echo "--- Test: --field contenthash ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field contenthash
assert_output_not_empty "Content hash output is not empty" "$OUT"
echo ""

# Test 8: --field with multiple IDs (one line per file)
if [ -n "$SECOND_ID" ]; then
    echo "--- Test: --field path with multiple IDs ---"
    run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" "$SECOND_ID" --field path
    LINE_COUNT=$(echo "$OUT" | wc -l)
    if [ "$LINE_COUNT" -ge 2 ]; then
        echo "  PASS: Multiple lines for multiple files ($LINE_COUNT lines)"
        ((PASS++))
    else
        echo "  FAIL: Expected at least 2 lines, got $LINE_COUNT"
        ((FAIL++))
    fi
    echo ""
fi

# Test 9: Invalid --field name
echo "--- Test: Invalid field name ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field invalid
RC=$?
assert_output_contains "Error for invalid field" "Unknown field" "$OUT"
assert_output_contains "Lists valid fields" "path" "$OUT"
echo ""

# Test 10: --stdin reading piped IDs
echo "--- Test: --stdin reads piped IDs ---"
OUT=$(echo "$FILE_ID" | $PHP "$MOOSH" -p "$MOODLE_PATH" file:info --stdin --field path 2>&1)
echo "$OUT"
assert_output_contains "Stdin path contains filedir" "filedir" "$OUT"
echo ""

# Test 11: --stdin with multiple piped IDs
if [ -n "$SECOND_ID" ]; then
    echo "--- Test: --stdin with multiple IDs ---"
    OUT=$(echo "$FILE_ID $SECOND_ID" | $PHP "$MOOSH" -p "$MOODLE_PATH" file:info --stdin --field id 2>&1)
    echo "$OUT"
    assert_output_contains "First ID in output" "$FILE_ID" "$OUT"
    assert_output_contains "Second ID in output" "$SECOND_ID" "$OUT"
    echo ""
fi

# Test 12: Pipe from file:list -i into file:info --stdin
echo "--- Test: Pipe file:list -i | file:info --stdin --field path ---"
OUT=$($PHP "$MOOSH" -p "$MOODLE_PATH" file:list --component=user --limit=3 -i 2>&1 \
    | $PHP "$MOOSH" -p "$MOODLE_PATH" file:info --stdin --field path 2>&1)
echo "$OUT"
assert_output_not_empty "Piped output is not empty" "$OUT"
assert_output_contains "Piped path contains filedir" "filedir" "$OUT"
echo ""

# Test 13: --field filesize outputs raw bytes
echo "--- Test: --field filesize outputs raw number ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field filesize
assert_output_not_contains "No human-readable suffix" "KB" "$OUT"
assert_output_not_contains "No human-readable suffix" "MB" "$OUT"
echo ""

# Test 14: No arguments and no stdin
echo "--- Test: No arguments error ---"
run_moosh -p "$MOODLE_PATH" file:info
assert_output_contains "Error without args" "Specify" "$OUT"
echo ""

# Test 15: Help output
echo "--- Test: Help output ---"
run_moosh -p "$MOODLE_PATH" file:info --help
assert_output_contains "Help shows description" "file information" "$OUT"
assert_output_contains "Help shows --field option" "--field" "$OUT"
assert_output_contains "Help shows --stdin option" "--stdin" "$OUT"
assert_output_contains "Help shows --hash option" "--hash" "$OUT"
echo ""

# Test 16: --field with --hash
echo "--- Test: --field with --hash ---"
run_moosh -p "$MOODLE_PATH" file:info "$FILE_ID" --field contenthash
HASH="$OUT"
if [ -n "$HASH" ]; then
    run_moosh -p "$MOODLE_PATH" file:info --hash="$HASH" --field path
    assert_output_contains "Hash lookup path has filedir" "filedir" "$OUT"
fi
echo ""

print_summary
