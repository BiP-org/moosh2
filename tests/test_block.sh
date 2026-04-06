#!/usr/bin/env bash
#
# Integration tests for moosh2 block commands:
#   block:create, block:mod
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_block.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 block commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  block:create
# ═══════════════════════════════════════════════════════════════════

echo "========== block:create =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh block:create calendar_month 2 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows blocktype" "calendar_month" "$OUT"
echo ""

echo "--- Test: Create block in course ---"
run_moosh block:create calendar_month 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "Shows blocktype" "calendar_month" "$OUT"
assert_output_contains "Shows region" "side-pre" "$OUT"
# Extract the block instance ID for later tests
BLOCK_ID=$(echo "$OUT" | grep -oP '^\| \K[0-9]+' | head -1)
echo "  Created block ID: $BLOCK_ID"
echo ""

echo "--- Test: Create with custom region and weight ---"
run_moosh block:create online_users 2 -p "$MOODLE_PATH" --region side-post --weight 5 --run
EC=$?
assert_exit_code "Custom create exit code 0" 0 $EC
assert_output_contains "Shows side-post" "side-post" "$OUT"
assert_output_contains "Shows weight 5" "5" "$OUT"
BLOCK_ID2=$(echo "$OUT" | grep -oP '^\| \K[0-9]+' | head -1)
echo "  Created block ID: $BLOCK_ID2"
echo ""

echo "--- Test: Create with pagetypepattern ---"
run_moosh block:create html 2 -p "$MOODLE_PATH" --pagetypepattern "course-view-*" --showinsubcontexts 1 --run
EC=$?
assert_exit_code "Pattern create exit code 0" 0 $EC
assert_output_contains "Shows pagetypepattern" "course-view-*" "$OUT"
BLOCK_ID3=$(echo "$OUT" | grep -oP '^\| \K[0-9]+' | head -1)
echo "  Created block ID: $BLOCK_ID3"
echo ""

echo "--- Test: Create in category mode ---"
run_moosh block:create calendar_month 1 -p "$MOODLE_PATH" --mode category --run
EC=$?
assert_exit_code "Category create exit code 0" 0 $EC
assert_output_contains "Shows category" "category" "$OUT"
echo ""

echo "--- Test: Create in site mode ---"
run_moosh block:create calendar_month 0 -p "$MOODLE_PATH" --mode site --run
EC=$?
assert_exit_code "Site create exit code 0" 0 $EC
assert_output_contains "Shows site" "site front page" "$OUT"
echo ""

echo "--- Test: Create in categorycourses mode ---"
run_moosh block:create online_users 2 -p "$MOODLE_PATH" --mode categorycourses --run
EC=$?
assert_exit_code "Categorycourses exit code 0" 0 $EC
# Should have multiple rows (one per course in category)
line_count=$(echo "$OUT" | grep -c 'online_users')
if [ "$line_count" -ge 2 ]; then
    echo "  PASS: Multiple blocks created ($line_count courses)"
    ((PASS++))
else
    echo "  FAIL: Expected multiple blocks, got $line_count"
    ((FAIL++))
fi
echo ""

echo "--- Test: CSV output ---"
run_moosh block:create html 2 -p "$MOODLE_PATH" --run -o csv
assert_output_contains "CSV header" "id,blocktype,target,region,weight,pagetypepattern" "$OUT"
assert_output_contains "CSV has html" "html" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh block:create html 2 -p "$MOODLE_PATH" --run -o json
assert_output_contains "JSON has blocktype" '"blocktype": "html"' "$OUT"
echo ""

echo "--- Test: Invalid blocktype ---"
run_moosh block:create nonexistent_block 2 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid blocktype" 1 $EC
assert_output_contains "Error for invalid blocktype" "Unknown block type" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh block:create calendar_month 999 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
assert_output_contains "Error for invalid course" "not found" "$OUT"
echo ""

echo "--- Test: Invalid mode ---"
run_moosh block:create calendar_month 2 -p "$MOODLE_PATH" --mode invalid --run
EC=$?
assert_exit_code "Exit code 1 for invalid mode" 1 $EC
assert_output_contains "Error for invalid mode" "Invalid mode" "$OUT"
echo ""

echo "--- Test: block:create help ---"
run_moosh block:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Add a block instance" "$OUT"
assert_output_contains "Help shows blocktype" "blocktype" "$OUT"
assert_output_contains "Help shows --mode" "--mode" "$OUT"
assert_output_contains "Help shows --region" "--region" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
#  block:mod
# ═══════════════════════════════════════════════════════════════════

echo "========== block:mod =========="
echo ""

# Create a fresh block for mod tests
run_moosh block:create calendar_month 2 -p "$MOODLE_PATH" --run -o csv
MOD_BLOCK_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Test block ID for mod: $MOD_BLOCK_ID"
echo ""

echo "--- Test: Mod dry run ---"
run_moosh block:mod $MOD_BLOCK_ID --region side-post -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows region change" "region" "$OUT"
echo ""

echo "--- Test: Change region ---"
run_moosh block:mod $MOD_BLOCK_ID --region side-post -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Region change exit code 0" 0 $EC
assert_output_contains "Shows side-post" "side-post" "$OUT"
echo ""

echo "--- Test: Change weight ---"
run_moosh block:mod $MOD_BLOCK_ID --weight 10 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Weight change exit code 0" 0 $EC
assert_output_contains "Shows weight 10" "10" "$OUT"
echo ""

echo "--- Test: Change pagetypepattern ---"
run_moosh block:mod $MOD_BLOCK_ID --pagetypepattern "course-view-*" -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Pattern change exit code 0" 0 $EC
assert_output_contains "Shows course-view-*" "course-view-*" "$OUT"
echo ""

echo "--- Test: Change showinsubcontexts ---"
run_moosh block:mod $MOD_BLOCK_ID --showinsubcontexts 1 -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Subcontexts change exit code 0" 0 $EC
assert_output_contains "Shows showinsubcontexts 1" "1" "$OUT"
echo ""

echo "--- Test: Multiple changes at once ---"
run_moosh block:mod $MOD_BLOCK_ID --region side-pre --weight 3 -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Multiple changes exit code 0" 0 $EC
assert_output_contains "CSV has side-pre" "side-pre" "$OUT"
echo ""

echo "--- Test: JSON output ---"
run_moosh block:mod $MOD_BLOCK_ID --weight 7 -p "$MOODLE_PATH" --run -o json
assert_output_contains "JSON has blockname" '"blockname": "calendar_month"' "$OUT"
echo ""

echo "--- Test: block:delete dry run ---"
run_moosh block:delete $MOD_BLOCK_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Delete dry run exit code 0" 0 $EC
assert_output_contains "Shows delete dry run" "Dry run" "$OUT"
assert_output_contains "Shows block name" "calendar_month" "$OUT"
echo ""

echo "--- Test: block:delete ---"
run_moosh block:delete $MOD_BLOCK_ID -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Delete exit code 0" 0 $EC
assert_output_contains "Shows deleted" "Deleted" "$OUT"
echo ""

echo "--- Test: Invalid instance ID ---"
run_moosh block:mod 99999 --region side-post -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid ID" 1 $EC
assert_output_contains "Error for invalid ID" "not found" "$OUT"
echo ""

echo "--- Test: No modification specified ---"
# Create another block for this test
run_moosh block:create html 2 -p "$MOODLE_PATH" --run -o csv
TEMP_BLOCK_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh block:mod $TEMP_BLOCK_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for no mod" 1 $EC
assert_output_contains "Error for no modification" "No modifications specified" "$OUT"
echo ""

echo "--- Test: Multiple instance IDs ---"
OUT1=$($PHP $MOOSH block:create html 2 -p "$MOODLE_PATH" --run -o csv 2>&1)
ID_A=$(echo "$OUT1" | tail -1 | cut -d, -f1)
OUT2=$($PHP $MOOSH block:create html 2 -p "$MOODLE_PATH" --run -o csv 2>&1)
ID_B=$(echo "$OUT2" | tail -1 | cut -d, -f1)
run_moosh block:mod $ID_A $ID_B --region side-post -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Multi-mod exit code 0" 0 $EC
line_count=$(echo "$OUT" | grep -c 'side-post')
if [ "$line_count" -ge 2 ]; then
    echo "  PASS: Both blocks modified"
    ((PASS++))
else
    echo "  FAIL: Expected 2 modifications, got $line_count"
    ((FAIL++))
fi
echo ""

echo "--- Test: block:mod help ---"
run_moosh block:mod -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Modify or move" "$OUT"
assert_output_contains "Help shows instanceid" "instanceid" "$OUT"
assert_output_contains "Help shows --region" "--region" "$OUT"
echo ""


print_summary
