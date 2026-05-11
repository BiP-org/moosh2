#!/usr/bin/env bash
#
# Integration test for moosh2 instance:info
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_instance_info.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 instance:info integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""


# ── Setup ─────────────────────────────────────────────────────────

echo "========== Setup =========="

echo "--- Create a forum activity in course 2 ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "TestForum" forum 2 -o csv
FORUM_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
FORUM_INSTANCE=$(echo "$OUT" | tail -1 | cut -d, -f3)
echo "  forum cmid: $FORUM_CMID, instance: $FORUM_INSTANCE"
echo ""

echo "--- Create a quiz activity in course 2 ---"
run_moosh activity:create -p "$MOODLE_PATH" --run --name "TestQuiz" quiz 2 -o csv
QUIZ_CMID=$(echo "$OUT" | tail -1 | cut -d, -f1)
QUIZ_INSTANCE=$(echo "$OUT" | tail -1 | cut -d, -f3)
echo "  quiz cmid: $QUIZ_CMID, instance: $QUIZ_INSTANCE"
echo ""


# ── Lookup with modulename ────────────────────────────────────────

echo "========== With modulename =========="
echo ""

echo "--- Look up forum by instance + module name (CSV) ---"
run_moosh instance:info $FORUM_INSTANCE forum -o csv -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
assert_output_contains "Header row present" "modulename,instanceid,cmid,course,name" "$OUT"
assert_output_contains "Reports forum module" "forum," "$OUT"
assert_output_contains "Reports correct cmid" ",$FORUM_CMID," "$OUT"
assert_output_contains "Reports correct instance" ",$FORUM_INSTANCE," "$OUT"
assert_output_contains "Reports instance name" "TestForum" "$OUT"
echo ""

echo "--- Look up quiz by instance + module name (CSV) ---"
run_moosh instance:info $QUIZ_INSTANCE quiz -o csv -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
assert_output_contains "Reports quiz module" "quiz," "$OUT"
assert_output_contains "Reports correct cmid" ",$QUIZ_CMID," "$OUT"
assert_output_contains "Reports instance name" "TestQuiz" "$OUT"
echo ""


# ── Lookup without modulename ─────────────────────────────────────

echo "========== Without modulename =========="
echo ""

echo "--- Search all module types for forum instance ID ---"
run_moosh instance:info $FORUM_INSTANCE -o csv -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
# At minimum, the forum row should be present.
assert_output_contains "Includes forum row with TestForum" "TestForum" "$OUT"
echo ""

echo "--- Search all module types for quiz instance ID ---"
run_moosh instance:info $QUIZ_INSTANCE -o csv -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
assert_output_contains "Includes quiz row with TestQuiz" "TestQuiz" "$OUT"
echo ""


# ── Output formats ────────────────────────────────────────────────

echo "========== Output formats =========="
echo ""

echo "--- Table format ---"
run_moosh instance:info $QUIZ_INSTANCE quiz -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
assert_output_contains "Has table separator" "+---" "$OUT"
assert_output_contains "Has TestQuiz" "TestQuiz" "$OUT"
echo ""

echo "--- JSON format ---"
run_moosh instance:info $QUIZ_INSTANCE quiz -o json -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 0" 0 $EC
assert_output_contains "Has JSON object" '"modulename"' "$OUT"
assert_output_contains "Has TestQuiz" "TestQuiz" "$OUT"
echo ""


# ── Error paths ───────────────────────────────────────────────────

echo "========== Error paths =========="
echo ""

echo "--- Invalid instance ID (zero) ---"
run_moosh instance:info 0 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for instanceid=0" 1 $EC
assert_output_contains "Reports invalid id" "Invalid instance ID" "$OUT"
echo ""

echo "--- Nonexistent instance + modulename ---"
run_moosh instance:info 99999 quiz -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for missing instance" 1 $EC
assert_output_contains "Reports not found" "No instance with ID 99999 found" "$OUT"
echo ""

echo "--- Nonexistent instance (search all) ---"
run_moosh instance:info 99999 -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for missing instance" 1 $EC
assert_output_contains "Reports not found" "No instance with ID 99999 found" "$OUT"
echo ""

echo "--- Invalid module name ---"
run_moosh instance:info $QUIZ_INSTANCE "not-a-real-module!" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit 1 for bad modulename" 1 $EC
assert_output_contains "Reports invalid module name" "Invalid module name" "$OUT"
echo ""

echo "--- Modulename mismatch (quiz instance with 'forum' modulename) ---"
run_moosh instance:info $QUIZ_INSTANCE forum -p "$MOODLE_PATH"
EC=$?
# Quiz instance may not match a forum — but if a forum with the same instance ID also
# exists (unlikely in a fresh setup), this would succeed. We tolerate both outcomes by
# only asserting the message is consistent when it fails.
if [ "$EC" -eq 1 ]; then
    assert_output_contains "Reports scoped miss" "module 'forum'" "$OUT"
fi
echo ""


# ── Help ──────────────────────────────────────────────────────────

echo "========== Help =========="
echo ""

echo "--- Help text ---"
run_moosh instance:info --help -p "$MOODLE_PATH"
assert_output_contains "Help shows description" "Look up the course module ID for an activity instance" "$OUT"
assert_output_contains "Help mentions get_coursemodule_from_instance" "get_coursemodule_from_instance" "$OUT"
assert_output_contains "Help mentions modulename optional behavior" "every installed module type is searched" "$OUT"
echo ""


print_summary
