#!/usr/bin/env bash
#
# Run all test_*.sh test files and display summary
#
# Usage: MOODLE_DIR=/path/to/test/moodle/ bash tests/run_all_tests.sh
#
# Selecting which tests run (both accept a space- and/or comma-separated
# list; entries may be given as the full filename ("test_plugin_list.sh"),
# without the .sh extension ("test_plugin_list"), or without the leading
# "test_" ("plugin_list"):
#
#   ONLY_TESTS="plugin_list plugin_clamscan" bash tests/run_all_tests.sh
#       Runs only the listed tests, skipping everything else.
#
#   SKIP_TESTS="plugin_list_apply" bash tests/run_all_tests.sh
#       Runs everything except the listed tests.
#
# ONLY_TESTS takes precedence if both are set. Unknown names in either
# variable are reported as an error (nothing silently ignored).
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_TESTS=0
FILES_PASS=0
FILES_FAIL=0
FAILED_FILES=()

# Normalize a test name/filename to a bare key for comparison, e.g.
# "tests/test_plugin_list.sh", "test_plugin_list.sh", "test_plugin_list",
# and "plugin_list" all normalize to "plugin_list".
normalize_test_name() {
    local name
    name=$(basename "$1")
    name="${name%.sh}"
    name="${name#test_}"
    echo "$name"
}

# Build the full list of available test files, keyed by their normalized name.
ALL_TEST_FILES=()
ALL_TEST_KEYS=()
for test_file in "$SCRIPT_DIR"/test_*.sh; do
    [ -e "$test_file" ] || continue
    ALL_TEST_FILES+=("$test_file")
    ALL_TEST_KEYS+=("$(normalize_test_name "$test_file")")
done

# Split a space-and/or-comma-separated variable into normalized keys.
split_test_names() {
    local raw="$1"
    raw="${raw//,/ }"
    local name
    for name in $raw; do
        normalize_test_name "$name"
    done
}

TEST_FILES=()

if [ -n "${ONLY_TESTS:-}" ]; then
    echo "ONLY_TESTS set - running only: $ONLY_TESTS"
    while IFS= read -r wanted; do
        [ -n "$wanted" ] || continue
        found=""
        for i in "${!ALL_TEST_KEYS[@]}"; do
            if [ "${ALL_TEST_KEYS[$i]}" = "$wanted" ]; then
                TEST_FILES+=("${ALL_TEST_FILES[$i]}")
                found="1"
                break
            fi
        done
        if [ -z "$found" ]; then
            echo "ERROR: ONLY_TESTS references unknown test '$wanted' (no test_${wanted}.sh in $SCRIPT_DIR)"
            exit 1
        fi
    done < <(split_test_names "$ONLY_TESTS")
elif [ -n "${SKIP_TESTS:-}" ]; then
    echo "SKIP_TESTS set - skipping: $SKIP_TESTS"
    SKIP_KEYS=()
    while IFS= read -r skipped; do
        [ -n "$skipped" ] || continue
        found=""
        for key in "${ALL_TEST_KEYS[@]}"; do
            if [ "$key" = "$skipped" ]; then
                found="1"
                break
            fi
        done
        if [ -z "$found" ]; then
            echo "ERROR: SKIP_TESTS references unknown test '$skipped' (no test_${skipped}.sh in $SCRIPT_DIR)"
            exit 1
        fi
        SKIP_KEYS+=("$skipped")
    done < <(split_test_names "$SKIP_TESTS")

    for i in "${!ALL_TEST_KEYS[@]}"; do
        skip_this=""
        for key in "${SKIP_KEYS[@]}"; do
            if [ "${ALL_TEST_KEYS[$i]}" = "$key" ]; then
                skip_this="1"
                break
            fi
        done
        if [ -z "$skip_this" ]; then
            TEST_FILES+=("${ALL_TEST_FILES[$i]}")
        fi
    done
else
    TEST_FILES=("${ALL_TEST_FILES[@]}")
fi

if [ "${#TEST_FILES[@]}" -eq 0 ]; then
    echo "ERROR: no test files selected to run."
    exit 1
fi

START_TIME=$(date +%s)

for test_file in "${TEST_FILES[@]}"; do
    filename=$(basename "$test_file")
    echo ""
    echo "################################################################"
    echo "# Running: $filename"
    echo "################################################################"
    echo ""

    output=$(bash "$test_file" 2>&1)
    exit_code=$?
    pass_count=$(echo "$output" | grep -oP 'Results: \K[0-9]+(?= passed)' || echo 0)
    fail_count=$(echo "$output" | grep -oP 'Results: [0-9]+ passed, \K[0-9]+(?= failed)' || echo 0)

    # Retry once on errors — the test DB occasionally returns transient errors
    # (orphan InnoDB tablespaces after restore). Discard the first run's output.
    if [ "$exit_code" -ne 0 ] || [ "${fail_count:-0}" -gt 0 ]; then
        echo "# First run had errors (exit=$exit_code, fail=${fail_count:-0}); retrying after 1s..."
        sleep 1
        output=$(bash "$test_file" 2>&1)
        exit_code=$?
        pass_count=$(echo "$output" | grep -oP 'Results: \K[0-9]+(?= passed)' || echo 0)
        fail_count=$(echo "$output" | grep -oP 'Results: [0-9]+ passed, \K[0-9]+(?= failed)' || echo 0)
    fi

    echo "$output"

    TOTAL_PASS=$((TOTAL_PASS + pass_count))
    TOTAL_FAIL=$((TOTAL_FAIL + fail_count))
    TOTAL_TESTS=$((TOTAL_TESTS + pass_count + fail_count))

    if [ "$exit_code" -eq 0 ]; then
        ((FILES_PASS++))
    else
        ((FILES_FAIL++))
        FAILED_FILES+=("$filename")
    fi
done

END_TIME=$(date +%s)
ELAPSED=$((END_TIME - START_TIME))
MINUTES=$((ELAPSED / 60))
SECONDS=$((ELAPSED % 60))

echo ""
echo "================================================================"
echo "                        TEST SUMMARY"
echo "================================================================"
echo "Test files run:  $((FILES_PASS + FILES_FAIL))"
echo "  Passed files:  $FILES_PASS"
echo "  Failed files:  $FILES_FAIL"
echo ""
echo "Total tests:     $TOTAL_TESTS"
echo "  PASS:          $TOTAL_PASS"
echo "  FAIL:          $TOTAL_FAIL"
echo ""
echo "Total time:      ${MINUTES}m ${SECONDS}s"

if [ "${#FAILED_FILES[@]}" -gt 0 ]; then
    echo ""
    echo "Failed test files:"
    for f in "${FAILED_FILES[@]}"; do
        echo "  - $f"
    done
fi

echo "================================================================"

if [ "$TOTAL_FAIL" -gt 0 ]; then
    exit 1
fi
