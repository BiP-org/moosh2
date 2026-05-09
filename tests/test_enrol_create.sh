#!/usr/bin/env bash
#
# Integration tests for moosh2 enrol:create command.
# Exercises the generic enrolment-instance creation path against the
# core plugins that opt into is_csv_upload_supported(): meta, cohort,
# self, manual, guest.
#
# Usage: bash tests/test_enrol_create.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 enrol:create integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# meta and cohort are not enabled in the canonical test dump.
echo "--- Enabling meta and cohort enrolment plugins ---"
$PHP $MOOSH config:set enrol_plugins_enabled "manual,guest,self,cohort,meta" -p "$MOODLE_PATH" --run > /dev/null 2>&1
$PHP $MOOSH cache:purge -p "$MOODLE_PATH" > /dev/null 2>&1
echo ""

# Discover course shortnames so the test does not rely on hard-coded fixture content.
run_moosh course:list -o csv -p "$MOODLE_PATH"
PARENT_ID=2
PARENT_SHORT=$(echo "$OUT" | awk -F, '$1==2 {print $3}')
CHILD_ID=3
CHILD_SHORT=$(echo "$OUT" | awk -F, '$1==3 {print $3}')
echo "  Parent course: id=$PARENT_ID shortname=$PARENT_SHORT"
echo "  Child course:  id=$CHILD_ID shortname=$CHILD_SHORT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  Help
# ═══════════════════════════════════════════════════════════════════

echo "========== enrol:create --help =========="
echo ""

echo "--- Test: Help output ---"
run_moosh enrol:create -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Add an enrolment method instance" "$OUT"
assert_output_contains "Help shows --method" "--method" "$OUT"
assert_output_contains "Help shows --field" "--field" "$OUT"
assert_output_contains "Help shows --status" "--status" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  Argument validation
# ═══════════════════════════════════════════════════════════════════

echo "========== argument validation =========="
echo ""

echo "--- Test: Missing --method ---"
run_moosh enrol:create $PARENT_ID -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 without --method" 1 $EC
assert_output_contains "Reports missing method" "method is required" "$OUT"
echo ""

echo "--- Test: Unknown plugin ---"
run_moosh enrol:create $PARENT_ID --method=nopelusion -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for unknown plugin" 1 $EC
assert_output_contains "Reports unknown plugin" "not found" "$OUT"
echo ""

echo "--- Test: Invalid course ---"
run_moosh enrol:create 999999 --method=meta --field "metacoursename=$CHILD_SHORT" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid course" 1 $EC
assert_output_contains "Reports invalid course" "not found" "$OUT"
echo ""

echo "--- Test: Malformed --field ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for malformed field" 1 $EC
assert_output_contains "Reports malformed field" "KEY=VALUE" "$OUT"
echo ""

echo "--- Test: Invalid --status ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename=$CHILD_SHORT" --status=bogus -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for bad status" 1 $EC
assert_output_contains "Reports bad status" "Invalid --status" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  meta plugin (the original feature request)
# ═══════════════════════════════════════════════════════════════════

echo "========== enrol:create --method=meta =========="
echo ""

echo "--- Test: Missing metacoursename ---"
run_moosh enrol:create $PARENT_ID --method=meta -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 without metacoursename" 1 $EC
assert_output_contains "Reports validation failure" "Validation failed" "$OUT"
assert_output_contains "Mentions metacoursename" "metacoursename" "$OUT"
echo ""

echo "--- Test: Unknown child shortname ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename=does_not_exist" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for unknown child" 1 $EC
assert_output_contains "Reports unknown course" "Validation failed" "$OUT"
echo ""

echo "--- Test: Same-course self link rejected ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename=$PARENT_SHORT" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for self link" 1 $EC
assert_output_contains "Reports same course" "Validation failed" "$OUT"
echo ""

echo "--- Test: Meta dry run ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename=$CHILD_SHORT" -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Resolved customint1" "customint1 = $CHILD_ID" "$OUT"
assert_output_contains "Hint to use --run" "Use --run" "$OUT"
echo ""

# Verify dry run did not actually write.
run_moosh enrol:list $PARENT_ID -p "$MOODLE_PATH" -o csv
assert_output_not_contains "Dry run did not create instance" "meta," "$OUT"
echo ""

echo "--- Test: Meta --run creates instance ---"
run_moosh enrol:create $PARENT_ID --method=meta --field "metacoursename=$CHILD_SHORT" -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Create exit code 0" 0 $EC
assert_output_contains "CSV header" "id,enrol,name,status,roleid,courseid,customint1,customint2" "$OUT"
assert_output_contains "Row shows meta" ",meta," "$OUT"
assert_output_contains "Row shows enabled" ",enabled," "$OUT"
META_INSTANCE_ID=$(echo "$OUT" | grep ",meta," | cut -d, -f1)
echo "  New meta instance id: $META_INSTANCE_ID"
echo ""

echo "--- Test: Instance is visible to enrol:list ---"
run_moosh enrol:list $PARENT_ID -p "$MOODLE_PATH" -o csv
assert_output_contains "enrol:list shows meta" "meta" "$OUT"
echo ""

echo "--- Test: Meta link is recorded in DB ---"
run_moosh sql:select "SELECT enrol, courseid, customint1 FROM mdl_enrol WHERE id=$META_INSTANCE_ID" -p "$MOODLE_PATH"
assert_output_contains "DB enrol=meta" "meta" "$OUT"
assert_output_contains "DB customint1=child id" "$CHILD_ID" "$OUT"
echo ""

echo "--- Test: --status=disabled honoured ---"
run_moosh course:list -o csv -p "$MOODLE_PATH"
CHILD5_SHORT=$(echo "$OUT" | awk -F, '$1==5 {print $3}')
run_moosh enrol:create 4 --method=meta --field "metacoursename=$CHILD5_SHORT" --status=disabled -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Disabled-create exit code 0" 0 $EC
assert_output_contains "Row shows disabled" ",disabled," "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  cohort plugin
# ═══════════════════════════════════════════════════════════════════

echo "========== enrol:create --method=cohort =========="
echo ""

echo "--- Setup: Create a cohort ---"
run_moosh cohort:create "Enrol Create Test" --idnumber EC_TEST -p "$MOODLE_PATH" --run -o csv
COHORT_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
echo "  Cohort id: $COHORT_ID, idnumber: EC_TEST"
echo ""

echo "--- Test: Missing cohortidnumber ---"
run_moosh enrol:create $PARENT_ID --method=cohort -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 without cohort idnumber" 1 $EC
assert_output_contains "Reports validation failure" "Validation failed" "$OUT"
echo ""

echo "--- Test: Cohort dry run ---"
# Course 6 to avoid colliding with earlier instances on parent.
run_moosh enrol:create 6 --method=cohort --field cohortidnumber=EC_TEST --field role=student -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Cohort dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Resolved customint1" "customint1 = $COHORT_ID" "$OUT"
echo ""

echo "--- Test: Cohort --run ---"
run_moosh enrol:create 6 --method=cohort --field cohortidnumber=EC_TEST --field role=student -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Cohort create exit code 0" 0 $EC
assert_output_contains "Row shows cohort" ",cohort," "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  self plugin (already exists by default — must use a fresh course)
# ═══════════════════════════════════════════════════════════════════

echo "========== enrol:create --method=self =========="
echo ""

echo "--- Setup: Create a fresh course for self enrolment ---"
run_moosh course:create EnrolCreateSelfTest --fullname "Enrol Create Self Test" -p "$MOODLE_PATH" --run -o csv
SELF_COURSE_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
# Default course already has a 'self' instance from the course-creation path,
# so we delete it to validate add via enrol:create.
run_moosh enrol:list $SELF_COURSE_ID -p "$MOODLE_PATH" -o csv
EXISTING_SELF_ID=$(echo "$OUT" | grep ",self," | head -1 | cut -d, -f1)
if [ -n "$EXISTING_SELF_ID" ]; then
    $PHP $MOOSH enrol:delete $EXISTING_SELF_ID -p "$MOODLE_PATH" --run > /dev/null 2>&1
fi
echo "  Self test course id: $SELF_COURSE_ID"
echo ""

echo "--- Test: Self --run ---"
run_moosh enrol:create $SELF_COURSE_ID --method=self --field "password=letmein" -p "$MOODLE_PATH" --run -o csv
EC=$?
assert_exit_code "Self create exit code 0" 0 $EC
assert_output_contains "Row shows self" ",self," "$OUT"
echo ""

echo "--- Test: Refusing to create when can_add_instance() = false ---"
# manual is a single-instance plugin: the course already has one.
run_moosh enrol:create $SELF_COURSE_ID --method=manual -p "$MOODLE_PATH" --run
EC=$?
assert_exit_code "Exit code 1 for duplicate manual" 1 $EC
assert_output_contains "Reports refusal" "refuses to add" "$OUT"
echo ""

print_summary
