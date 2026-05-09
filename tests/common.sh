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

run_moosh() {
    local cmd="  > $PHP $MOOSH"
    for arg in "$@"; do
        if [[ "$arg" == *' '* || "$arg" == *'"'* ]]; then
            cmd+=" \"$arg\""
        else
            cmd+=" $arg"
        fi
    done
    echo "$cmd"
    OUT=$($PHP $MOOSH "$@" 2>&1)
    local rc=$?
    echo "$OUT"
    return $rc
}

assert_output_contains() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if grep -qF -- "$expected" <<< "$actual"; then
        echo "  PASS: $description"
        ((PASS++))
    else
        echo "  FAIL: $description"
        echo "    Expected to contain: $expected"
        echo "    Got: $actual"
        ((FAIL++))
    fi
}

assert_output_not_contains() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if grep -qF -- "$expected" <<< "$actual"; then
        echo "  FAIL: $description"
        echo "    Expected NOT to contain: $expected"
        echo "    Got: $actual"
        ((FAIL++))
    else
        echo "  PASS: $description"
        ((PASS++))
    fi
}

assert_output_not_empty() {
    local description="$1"
    local actual="$2"
    if [ -n "$actual" ]; then
        echo "  PASS: $description"
        ((PASS++))
    else
        echo "  FAIL: $description (output was empty)"
        ((FAIL++))
    fi
}

assert_exit_code() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" -eq "$expected" ]; then
        echo "  PASS: $description"
        ((PASS++))
    else
        echo "  FAIL: $description"
        echo "    Expected exit code: $expected"
        echo "    Got: $actual"
        ((FAIL++))
    fi
}

print_summary() {
    echo ""
    echo "================================"
    echo "Results: $PASS passed, $FAIL failed"
    echo "================================"

    _moosh_test_release_lock

    if [ "$FAIL" -gt 0 ]; then
        exit 1
    fi
}
