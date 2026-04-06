#!/usr/bin/env bash
#
# Integration test for --no-user-check flag
# Tests that moosh warns when Moodle data directory ownership differs
# from the current user, and that --no-user-check skips the warning.
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_no_user_check.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 --no-user-check integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Test 1: Normal run should succeed (current user owns the data directory)
echo "--- Test: Normal run succeeds with matching ownership ---"
run_moosh -p "$MOODLE_PATH" course:list -o csv
RC=$?
assert_exit_code "course:list succeeds with correct ownership" 0 $RC
echo ""

# Test 2: --no-user-check flag is accepted and command succeeds
echo "--- Test: --no-user-check flag is accepted ---"
run_moosh -p "$MOODLE_PATH" --no-user-check course:list -o csv
RC=$?
assert_exit_code "course:list with --no-user-check succeeds" 0 $RC
echo ""

# Test 3: Create a temp directory owned by a different user to simulate mismatch
# This requires the dataroot to have a subdirectory owned by another user.
# We create one temporarily if running as root, otherwise skip.
echo "--- Test: Ownership mismatch detection ---"
DATAROOT=$($PHP -r "
    define('MOODLE_INTERNAL', true);
    define('ABORT_AFTER_CONFIG', true);
    define('CLI_SCRIPT', true);
    require_once('$MOODLE_PATH/config.php');
    global \$CFG;
    echo \$CFG->dataroot;
")

if [ -z "$DATAROOT" ] || [ ! -d "$DATAROOT" ]; then
    echo "  SKIP: Could not determine dataroot"
else
    echo "  Dataroot: $DATAROOT"
    TESTDIR="$DATAROOT/_moosh_test_ownership_$$"
    mkdir -p "$TESTDIR"

    if [ "$(id -u)" -eq 0 ]; then
        # Running as root: create dir owned by nobody to trigger mismatch
        chown nobody "$TESTDIR"

        run_moosh -p "$MOODLE_PATH" course:list -o csv
        RC=$?
        assert_exit_code "Fails with ownership mismatch" 1 $RC
        assert_output_contains "Error mentions ownership" "owned by" "$OUT"
        assert_output_contains "Error mentions --no-user-check" "--no-user-check" "$OUT"

        # Test 4: --no-user-check bypasses the check
        echo ""
        echo "--- Test: --no-user-check bypasses mismatch ---"
        run_moosh -p "$MOODLE_PATH" --no-user-check course:list -o csv
        RC=$?
        assert_exit_code "Succeeds with --no-user-check despite mismatch" 0 $RC

        chown "$(id -u)" "$TESTDIR"
    else
        echo "  SKIP: Not running as root, cannot change directory ownership"
        echo "  (Run as root to test ownership mismatch detection)"
    fi

    rmdir "$TESTDIR" 2>/dev/null
fi
echo ""

print_summary
