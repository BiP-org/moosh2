#!/usr/bin/env bash
#
# Common test helper functions for moosh2 integration tests.
#
# Usage: source "$(dirname "$0")/common.sh"
#

set -uo pipefail

MOOSH="$(cd "$(dirname "$0")/.." && pwd)/moosh.php"

if [ -z "${MOODLE_DIR:-}" ]; then
    echo "ERROR: MOODLE_DIR environment variable is not set."
    exit 1
fi

if [ ! -d "$MOODLE_DIR" ]; then
    echo "ERROR: Directory $MOODLE_DIR does not exist."
    exit 1
fi


if [ ! -d "$MOODLE_DIR/public" ]; then
    echo "ERROR: Directory $MOODLE_DIR/public does not exist."
    exit 1
fi

if [ ! -f "$MOODLE_DIR/public/config.php" ]; then
    echo "ERROR: File $MOODLE_DIR/public/config.php not found."
    exit 1
fi

MOODLE_PATH="$MOODLE_DIR/public"
PHP="${PHP:-/usr/bin/php}"
PASS=0
FAIL=0
LAST_CMD=""
LAST_OUT=""
GITHUB_ACTIONS="${GITHUB_ACTIONS:-false}"
GITHUB_STEP_SUMMARY="${GITHUB_STEP_SUMMARY:-}"

# ── Debug detection ──────────────────────────────────────────────────
# Comprehensive debug detection that checks multiple sources
is_debug_enabled() {
    # Check if any debug flag is set
    # shellcheck disable=SC2153
    if [ "${RUNNER_DEBUG:-0}" = "1" ] || \
       [ "${ACTIONS_STEP_DEBUG:-false}" = "true" ] || \
       [ "${ACTIONS_RUNNER_DEBUG:-false}" = "true" ] || \
       [ "${INPUTS_DEBUG:-0}" = "1" ] || \
       [ "${DEBUG:-0}" = "1" ]; then
        return 0
    fi
    return 1
}

# Get debug level (0 = off, 1 = basic, 2 = verbose)
get_debug_level() {
    local level=0
    
    # Check environment variables in order of precedence
    # shellcheck disable=SC2153
    if [ "${RUNNER_DEBUG:-0}" = "1" ] || \
       [ "${DEBUG:-0}" = "1" ] || \
       [ "${INPUTS_DEBUG:-0}" = "1" ]; then
        level=1
    fi
    
    # Runner debug is more verbose
    if [ "${ACTIONS_RUNNER_DEBUG:-false}" = "true" ]; then
        level=2
    fi
    
    # Step debug is also verbose
    if [ "${ACTIONS_STEP_DEBUG:-false}" = "true" ] && [ "$level" -lt 2 ]; then
        level=2
    fi
    
    echo "$level"
}

# ── GitHub Actions helpers ──────────────────────────────────────────
github_annotation() {
    local level="$1"  # error, warning, notice
    local message="$2"
    if [ "$GITHUB_ACTIONS" = "true" ]; then
        echo "::$level::$message"
    fi
}

github_annotate_output_contains() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if ! grep -qF -- "$expected" <<< "$actual"; then
        github_annotation "error" "Test failed: $description - Expected to contain: '$expected'"
        if is_debug_enabled; then
            github_annotation "notice" "Actual output for '$LAST_CMD': $actual"
        fi
    fi
}

github_annotate_exit_code() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" -ne "$expected" ]; then
        github_annotation "error" "Test failed: $description - Expected exit code $expected, got $actual"
        if is_debug_enabled; then
            github_annotation "notice" "Command output for '$LAST_CMD': $LAST_OUT"
        fi
    fi
}

append_to_summary() {
    local content="$1"
    if [ "$GITHUB_ACTIONS" = "true" ] && [ -n "$GITHUB_STEP_SUMMARY" ]; then
        echo "$content" >> "$GITHUB_STEP_SUMMARY"
    fi
}

# ── Test-run lock ──────────────────────────────────────────────────
# Prevent two test runs from racing on the same Moodle dataroot/database.
# The lock lives inside the dataroot so it tracks the instance under test.
# Released by print_summary on normal exit; stale locks (whose PID is no
# longer running) are reclaimed automatically on the next run.

MOOSH_TEST_LOCK_DATAROOT=$(grep -oP "\\\$CFG->dataroot\s*=\s*['\"]\K[^'\"]+" "$MOODLE_DIR/config.php" 2>/dev/null || true)
if [ -z "$MOOSH_TEST_LOCK_DATAROOT" ] || [ ! -d "$MOOSH_TEST_LOCK_DATAROOT" ]; then
    echo "ERROR: Could not resolve \$CFG->dataroot from $MOODLE_DIR/config.php."
    exit 1
fi

MOOSH_TEST_LOCK_FILE="$MOOSH_TEST_LOCK_DATAROOT/.moosh-tests.lock"

if [ -e "$MOOSH_TEST_LOCK_FILE" ]; then
    LOCK_PID=$(awk -F= '$1=="PID"{print $2}' "$MOOSH_TEST_LOCK_FILE" 2>/dev/null || true)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        LOCK_SCRIPT=$(awk -F= '$1=="SCRIPT"{print $2}' "$MOOSH_TEST_LOCK_FILE" 2>/dev/null || true)
        echo "ERROR: another moosh test run is in progress."
        echo "       lock:   $MOOSH_TEST_LOCK_FILE"
        echo "       pid:    $LOCK_PID"
        echo "       script: ${LOCK_SCRIPT:-unknown}"
        echo "Wait for it to finish, or remove the lock file if you are sure no test is running."
        exit 1
    fi
    echo "WARNING: stale lock at $MOOSH_TEST_LOCK_FILE (no live PID); reclaiming."
    rm -f "$MOOSH_TEST_LOCK_FILE"
fi

# noclobber + > makes the create atomic: two concurrent acquirers cannot both win.
if ! ( set -o noclobber; printf 'PID=%s\nSCRIPT=%s\nSTARTED=%s\n' \
        "$$" "${0:-unknown}" "$(date -Iseconds)" > "$MOOSH_TEST_LOCK_FILE" ) 2>/dev/null; then
    echo "ERROR: failed to acquire test lock at $MOOSH_TEST_LOCK_FILE."
    exit 1
fi

_moosh_test_release_lock() {
    [ -n "${MOOSH_TEST_LOCK_FILE:-}" ] && rm -f "$MOOSH_TEST_LOCK_FILE"
}

# Best-effort backstop. Test scripts that set their own EXIT trap will override
# this; that's OK because stale-PID detection reclaims any orphaned lock on the
# next run. Tests that need both can call _moosh_test_release_lock from their
# own trap.
trap _moosh_test_release_lock EXIT

# IMPORTANT: This function sets global LAST_OUT and returns the exit code.
# The test scripts expect OUT to be set globally after calling run_moosh.
# We achieve this by using command substitution to capture output into a global
# variable while preserving the exit code.
run_moosh() {
    # Build the command string for debugging
    LAST_CMD="$PHP $MOOSH"
    for arg in "$@"; do
        if [[ "$arg" == *' '* || "$arg" == *'"'* ]]; then
            LAST_CMD+=" \"$arg\""
        else
            LAST_CMD+=" $arg"
        fi
    done
    
    # IMPORTANT: Use eval with proper escaping to handle arguments with spaces
    # We need to set OUT globally so tests can use it
    # The eval must be in a way that preserves exit code
    local output
    output=$(eval "$PHP $MOOSH" "$@" 2>&1)
    local rc=$?
    
    # Set the global OUT variable (tests expect this)
    # shellcheck disable=SC2034
    OUT="$output"
    # Also set LAST_OUT for debug/annotation functions
    LAST_OUT="$output"
    
    # DEBUG: If debug is enabled, output the command and exit code
    if is_debug_enabled; then
        echo "DEBUG: Command: $LAST_CMD" >&2
        echo "DEBUG: Exit code: $rc" >&2
        if [ -n "$LAST_OUT" ]; then
            echo "DEBUG: Output:" >&2
            echo "$LAST_OUT" | sed 's/^/DEBUG:   /' >&2
        fi
    fi
    
    return $rc
}

# Helper function for tests that need to capture output in a variable
# Usage: OUT=$(run_moosh_capture args...)
# This ensures OUT is always set and the exit code is preserved
run_moosh_capture() {
    run_moosh "$@"
    local rc=$?
    echo "$OUT"
    return $rc
}

assert_output_contains() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if grep -qF -- "$expected" <<< "$actual"; then
        ((PASS++))
    else
        echo "  FAIL: $description"
        echo "    Command: $LAST_CMD"
        echo "    Expected to contain: $expected"
        echo "    Got: $actual"
        ((FAIL++))
        github_annotate_output_contains "$description" "$expected" "$actual"
    fi
}

assert_output_not_contains() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if grep -qF -- "$expected" <<< "$actual"; then
        echo "  FAIL: $description"
        echo "    Command: $LAST_CMD"
        echo "    Expected NOT to contain: $expected"
        echo "    Got: $actual"
        ((FAIL++))
        github_annotation "error" "Test failed: $description - Output should NOT contain: '$expected'"
        if is_debug_enabled; then
            github_annotation "notice" "Actual output for '$LAST_CMD': $actual"
        fi
    else
        ((PASS++))
    fi
}

assert_output_not_empty() {
    local description="$1"
    local actual="$2"
    if [ -n "$actual" ]; then
        ((PASS++))
    else
        echo "  FAIL: $description (output was empty)"
        echo "    Command: $LAST_CMD"
        ((FAIL++))
        github_annotation "error" "Test failed: $description - Output was empty"
        if is_debug_enabled; then
            github_annotation "notice" "Command: $LAST_CMD"
        fi
    fi
}

assert_exit_code() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" -eq "$expected" ]; then
        ((PASS++))
    else
        echo "  FAIL: $description"
        echo "    Command: $LAST_CMD"
        echo "    Expected exit code: $expected"
        echo "    Got: $actual"
        if is_debug_enabled; then
            echo "    Output: $LAST_OUT"
        fi
        ((FAIL++))
        github_annotate_exit_code "$description" "$expected" "$actual"
    fi
}

print_summary() {
    echo ""
    echo "================================"
    echo "Results: $PASS passed, $FAIL failed"
    echo "================================"

    # Add summary to GitHub Actions step summary
    if [ "$GITHUB_ACTIONS" = "true" ] && [ -n "$GITHUB_STEP_SUMMARY" ]; then
        local test_name="${0##*/}"
        echo "## Test Summary: $test_name" >> "$GITHUB_STEP_SUMMARY"
        echo "" >> "$GITHUB_STEP_SUMMARY"
        echo "- ✅ **$PASS** tests passed" >> "$GITHUB_STEP_SUMMARY"
        echo "- ❌ **$FAIL** tests failed" >> "$GITHUB_STEP_SUMMARY"
        echo "" >> "$GITHUB_STEP_SUMMARY"
        
        if [ "$FAIL" -gt 0 ]; then
            echo "### ❌ Test Run Failed" >> "$GITHUB_STEP_SUMMARY"
            echo "Check the workflow logs for details on the failed tests." >> "$GITHUB_STEP_SUMMARY"
            echo "" >> "$GITHUB_STEP_SUMMARY"
            if is_debug_enabled; then
                echo "**Debug mode enabled** - verbose output available in logs." >> "$GITHUB_STEP_SUMMARY"
            else
                echo "> To enable debug mode, re-run with debug enabled." >> "$GITHUB_STEP_SUMMARY"
            fi
        else
            echo "### ✅ All Tests Passed" >> "$GITHUB_STEP_SUMMARY"
        fi
        
        # Add debug status to summary
        local debug_level
        debug_level=$(get_debug_level)
        if [ "$debug_level" -gt 0 ]; then
            echo "" >> "$GITHUB_STEP_SUMMARY"
            echo "**Debug Level:** $debug_level (verbose output enabled)" >> "$GITHUB_STEP_SUMMARY"
        fi
        
        # Add annotation for summary
        if [ "$FAIL" -gt 0 ]; then
            github_annotation "error" "Test run failed with $FAIL failed tests (debug: $([ "$(is_debug_enabled)" = "0" ] && echo "off" || echo "on"))"
        else
            github_annotation "notice" "All $PASS tests passed successfully (debug: $([ "$(is_debug_enabled)" = "0" ] && echo "off" || echo "on"))"
        fi
    fi

    _moosh_test_release_lock

    if [ "$FAIL" -gt 0 ]; then
        exit 1
    fi
}