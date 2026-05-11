#!/usr/bin/env bash
#
# Integration test for moosh2 course:backup, course:restore
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_course_backup_restore.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 course:backup/restore integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

BACKUP_DIR=$(mktemp -d)

# ═══════════════════════════════════════════════════════════════════
# course:backup
# ═══════════════════════════════════════════════════════════════════

echo "========== course:backup =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:backup -p "$MOODLE_PATH" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows course" "algebrafundamentals" "$OUT"
assert_output_contains "Shows output path" ".mbz" "$OUT"
echo ""

echo "--- Test: Backup with --run ---"
run_moosh course:backup -p "$MOODLE_PATH" --run --path "$BACKUP_DIR" 2
assert_output_contains "Shows mbz path" ".mbz" "$OUT"
BACKUP_FILE=$(ls "$BACKUP_DIR"/backup_2_*.mbz 2>/dev/null | head -1)
if [ -n "$BACKUP_FILE" ] && [ -f "$BACKUP_FILE" ]; then
    FILE_SIZE=$(stat -c%s "$BACKUP_FILE")
    if [ "$FILE_SIZE" -gt 1000 ]; then
        echo "  PASS: Backup file created ($FILE_SIZE bytes)"
        ((PASS++))
    else
        echo "  FAIL: Backup file too small ($FILE_SIZE bytes)"
        ((FAIL++))
    fi
else
    echo "  FAIL: Backup file not created"
    ((FAIL++))
fi
echo ""

echo "--- Test: Template backup ---"
run_moosh course:backup -p "$MOODLE_PATH" --template 2
assert_output_contains "Template mode" "template" "$OUT"
echo ""

echo "--- Test: Nonexistent course ---"
run_moosh course:backup -p "$MOODLE_PATH" 99999
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad course" 1 "$EXIT_CODE"
assert_output_contains "Not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:backup -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Create a backup" "$OUT"
assert_output_contains "Help shows --template" "--template" "$OUT"
assert_output_contains "Help shows --exclude-cmids" "--exclude-cmids" "$OUT"
echo ""

echo "--- Test: --exclude-cmids dry run lists cmids ---"
run_moosh course:backup -p "$MOODLE_PATH" --exclude-cmids=16,17 18
assert_output_contains "Dry run header" "Dry run" "$OUT"
assert_output_contains "Excluded cmids listed" "Excluding cmids: 16, 17" "$OUT"
echo ""

echo "--- Test: --exclude-cmids rejects non-numeric ---"
run_moosh course:backup -p "$MOODLE_PATH" --exclude-cmids=foo 18
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad cmid" 1 "$EXIT_CODE"
assert_output_contains "Invalid cmid error" "Invalid --exclude-cmids" "$OUT"
echo ""

echo "--- Test: --exclude-cmids rejects cmid from another course ---"
run_moosh course:backup -p "$MOODLE_PATH" --exclude-cmids=1 18
EXIT_CODE=$?
assert_exit_code "Exit code 1 for wrong-course cmid" 1 "$EXIT_CODE"
assert_output_contains "Wrong-course error" "does not belong to course" "$OUT"
echo ""

echo "--- Test: --exclude-cmids actually excludes activities from .mbz ---"
EXCLUDE_BACKUP_DIR=$(mktemp -d)
run_moosh course:backup -p "$MOODLE_PATH" --run --path "$EXCLUDE_BACKUP_DIR" --exclude-cmids=16,17 18
EXCLUDE_FILE=$(ls "$EXCLUDE_BACKUP_DIR"/backup_18_*.mbz 2>/dev/null | head -1)
if [ -n "$EXCLUDE_FILE" ] && [ -f "$EXCLUDE_FILE" ]; then
    # Restore into a new course and count activities — should be 8 of 10.
    run_moosh course:restore -p "$MOODLE_PATH" --run "$EXCLUDE_FILE" 5
    RESTORED_ID=$(echo "$OUT" | grep -oP 'Restored course ID=\K[0-9]+' | tail -1)
    if [ -n "$RESTORED_ID" ]; then
        run_moosh activity:list -p "$MOODLE_PATH" -c "$RESTORED_ID" -o csv
        ACTIVITY_COUNT=$(echo "$OUT" | tail -n +2 | grep -c '^[0-9]')
        if [ "$ACTIVITY_COUNT" -eq 8 ]; then
            echo "  PASS: Restored course has 8 activities (2 excluded)"
            ((PASS++))
        else
            echo "  FAIL: Expected 8 activities in restored course, got $ACTIVITY_COUNT"
            ((FAIL++))
        fi
        assert_output_not_contains "Resource Pack 1 was excluded" '"Resource Pack 1",' "$OUT"
        assert_output_not_contains "Resource Pack 2 was excluded" '"Resource Pack 2",' "$OUT"
        assert_output_contains "Resource Pack 3 still present" '"Resource Pack 3",' "$OUT"
    else
        echo "  FAIL: Could not extract restored course ID"
        ((FAIL++))
    fi
else
    echo "  FAIL: --exclude-cmids backup file not created"
    ((FAIL++))
fi
rm -rf "$EXCLUDE_BACKUP_DIR"
echo ""


# ═══════════════════════════════════════════════════════════════════
# course:restore
# ═══════════════════════════════════════════════════════════════════

echo "========== course:restore =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh course:restore -p "$MOODLE_PATH" "$BACKUP_FILE" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows source" "$BACKUP_FILE" "$OUT"
assert_output_contains "Shows course name" "Algebra Fundamentals" "$OUT"
echo ""

echo "--- Test: Restore new course ---"
run_moosh course:restore -p "$MOODLE_PATH" --run "$BACKUP_FILE" 2
assert_output_contains "Shows restored" "Restored course" "$OUT"
assert_output_contains "Shows category" "category 2" "$OUT"
echo ""

echo "--- Test: Restore into existing course ---"
run_moosh course:restore -p "$MOODLE_PATH" --existing "$BACKUP_FILE" 3
assert_output_contains "Existing dry run" "add to existing" "$OUT"
echo ""

echo "--- Test: Dry run shows new start date ---"
run_moosh course:restore -p "$MOODLE_PATH" --course-startdate=2030-06-01T00:00:00Z "$BACKUP_FILE" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows new start date" "New start date: 2030-06-01" "$OUT"
echo ""

echo "--- Test: Invalid ISO-8601 date is rejected ---"
run_moosh course:restore -p "$MOODLE_PATH" --course-startdate=not-a-date "$BACKUP_FILE" 2
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad date" 1 "$EXIT_CODE"
assert_output_contains "Invalid date error" "Invalid --course-startdate" "$OUT"
echo ""

echo "--- Test: Restore with new start date applies offset ---"
NEW_START_ISO="2030-06-01T00:00:00Z"
EXPECTED_TS=$(date -u -d "$NEW_START_ISO" +%s)
run_moosh course:restore -p "$MOODLE_PATH" --run --course-startdate="$NEW_START_ISO" "$BACKUP_FILE" 2
assert_output_contains "Shows restored" "Restored course" "$OUT"
RESTORED_ID=$(echo "$OUT" | grep -oP 'Restored course ID=\K[0-9]+' | tail -1)
if [ -n "$RESTORED_ID" ]; then
    ACTUAL_TS=$(mysql -uroot -pa -N -B moodle52 -e "SELECT startdate FROM mdl_course WHERE id=$RESTORED_ID;" 2>/dev/null)
    if [ "$ACTUAL_TS" = "$EXPECTED_TS" ]; then
        echo "  PASS: Course $RESTORED_ID startdate matches new value ($ACTUAL_TS = $EXPECTED_TS)"
        ((PASS++))
    else
        echo "  FAIL: Course $RESTORED_ID startdate mismatch (got $ACTUAL_TS, expected $EXPECTED_TS)"
        ((FAIL++))
    fi
else
    echo "  FAIL: Could not extract restored course ID"
    ((FAIL++))
fi
echo ""

echo "--- Test: Dry run shows --without-users notice ---"
run_moosh course:restore -p "$MOODLE_PATH" --without-users "$BACKUP_FILE" 2
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Mentions excluding user data" "Excluding user data" "$OUT"
echo ""

echo "--- Test: Restore --without-users produces no user enrolments ---"
# Sanity-check the backup file does contain enrolled users (so the negative result is meaningful).
SRC_ENROLMENTS=$(mysql -uroot -pa -N -B moodle52 -e "SELECT COUNT(*) FROM mdl_user_enrolments ue JOIN mdl_enrol e ON e.id = ue.enrolid WHERE e.courseid = 2;" 2>/dev/null)
if [ "${SRC_ENROLMENTS:-0}" -lt 1 ]; then
    echo "  FAIL: source course 2 has no user_enrolments — backup wouldn't contain users either, skipping"
    ((FAIL++))
else
    run_moosh course:restore -p "$MOODLE_PATH" --run --without-users "$BACKUP_FILE" 2
    assert_output_contains "Shows restored" "Restored course" "$OUT"
    RESTORED_ID=$(echo "$OUT" | grep -oP 'Restored course ID=\K[0-9]+' | tail -1)
    if [ -n "$RESTORED_ID" ]; then
        DST_ENROLMENTS=$(mysql -uroot -pa -N -B moodle52 -e "SELECT COUNT(*) FROM mdl_user_enrolments ue JOIN mdl_enrol e ON e.id = ue.enrolid WHERE e.courseid = $RESTORED_ID;" 2>/dev/null)
        if [ "${DST_ENROLMENTS:-X}" = "0" ]; then
            echo "  PASS: restored course $RESTORED_ID has 0 user enrolments (source had $SRC_ENROLMENTS)"
            ((PASS++))
        else
            echo "  FAIL: expected 0 user enrolments in restored course $RESTORED_ID, got $DST_ENROLMENTS"
            ((FAIL++))
        fi
    else
        echo "  FAIL: Could not extract restored course ID"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: Nonexistent file ---"
run_moosh course:restore -p "$MOODLE_PATH" /tmp/nonexistent.mbz 2
EXIT_CODE=$?
assert_exit_code "Exit code 1 for bad file" 1 "$EXIT_CODE"
assert_output_contains "File not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh course:restore -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Restore a course" "$OUT"
assert_output_contains "Help shows --existing" "--existing" "$OUT"
assert_output_contains "Help shows --overwrite" "--overwrite" "$OUT"
assert_output_contains "Help shows --course-startdate" "--course-startdate" "$OUT"
assert_output_contains "Help shows --without-users" "--without-users" "$OUT"
echo ""


# ── Cleanup ──────────────────────────────────────────────────────

rm -rf "$BACKUP_DIR"

print_summary
