#!/usr/bin/env bash
#
# Run all test_*.sh test files and display summary
#
# Usage: MOODLE_DIR=/path/to/test/moodle/ bash tests/run_all_tests.sh
#
# ONLY_TESTS: space/comma-separated list of test filenames (with or without
#             the .sh extension) to run; everything else is skipped.
# SKIP_TESTS: space/comma-separated list of test filenames to skip. Ignored
#             if ONLY_TESTS is set.
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_TESTS=0
FILES_PASS=0
FILES_FAIL=0
FILES_SKIPPED=0
FAILED_FILES=()

# Normalize a space/comma-separated list into a set of bare filenames
# (test_foo, with or without .sh, both map to "test_foo.sh").
_normalize_list() {
    local raw="$1" name
    for name in ${raw//,/ }; do
        [ -n "$name" ] || continue
        case "$name" in
            *.sh) echo "$name" ;;
            *) echo "$name.sh" ;;
        esac
    done
}

ONLY_LIST=$(_normalize_list "${ONLY_TESTS:-}")
SKIP_LIST=$(_normalize_list "${SKIP_TESTS:-}")

if [ -n "$ONLY_LIST" ]; then
    echo "Running only: $(echo "$ONLY_LIST" | tr '\n' ' ')"
elif [ -n "$SKIP_LIST" ]; then
    echo "Skipping: $(echo "$SKIP_LIST" | tr '\n' ' ')"
fi

START_TIME=$(date +%s)

for test_file in "$SCRIPT_DIR"/test_*.sh; do
    filename=$(basename "$test_file")

    if [ -n "$ONLY_LIST" ]; then
        if ! grep -qxF "$filename" <<< "$ONLY_LIST"; then
            ((FILES_SKIPPED++))
            continue
        fi
    elif [ -n "$SKIP_LIST" ]; then
        if grep -qxF "$filename" <<< "$SKIP_LIST"; then
            echo ""
            echo "# Skipping: $filename"
            ((FILES_SKIPPED++))
            continue
        fi
    fi

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
echo "  Skipped files: $FILES_SKIPPED"
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
