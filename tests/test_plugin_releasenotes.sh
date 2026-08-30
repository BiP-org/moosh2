#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:releasenotes
#
# This command needs no Moodle installation (BootstrapLevel::None), but
# still runs through the same test harness as the other plugin: commands
# for consistency - see common.sh.
#
# Usage: bash tests/test_plugin_releasenotes.sh

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:releasenotes integration tests ==="
echo "moosh path: $MOOSH"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:releasenotes --help
assert_output_contains "Help mentions Marketplace" "Moodle Marketplace" "$OUT"
assert_output_contains "Help shows --format" "--format" "$OUT"
assert_output_contains "Help shows --token" "--token" "$OUT"
echo ""

echo "--- Test: Missing arguments fails cleanly ---"
run_moosh plugin:releasenotes
EC=$?
assert_exit_code "Exit code non-zero for missing arguments" 1 "$EC"
echo ""

echo "--- Test: Invalid --format rejected ---"
run_moosh plugin:releasenotes atto_wiris 2025041400 --format=xml
EC=$?
assert_exit_code "Exit code non-zero for invalid format" 1 "$EC"
assert_output_contains "Reports invalid format" "Invalid --format" "$OUT"
echo ""

echo "--- Test: --since >= version is a clean no-op, not an error ---"
run_moosh plugin:releasenotes atto_wiris 2024110400 --since=2025041400
EC=$?
assert_exit_code "Exit code 0 when --since is not older than version" 0 "$EC"
assert_output_contains "Explains nothing to show" "nothing newer than --since" "$OUT"
echo ""

# The remaining tests hit the live marketplace.moodle.com site and are
# best-effort: they're skipped (not failed) if the site is unreachable,
# since CI runners may not have outbound access to it, and Moodle
# Marketplace has no SLA or documented rate limits for this endpoint.
echo "--- Test: Known plugin/version (live) ---"
if ! curl -fsS -o /dev/null --max-time 10 "https://marketplace.moodle.com/plugins/atto_wiris/versions?show=all"; then
    echo "  SKIP: marketplace.moodle.com unreachable from this environment"
else
    run_moosh plugin:releasenotes atto_wiris 2025041400
    EC=$?
    assert_exit_code "Exit code 0 for known plugin/version" 0 "$EC"
    assert_output_contains "Shows the release name" "8.9.0" "$OUT"
    assert_output_contains "Shows Moodle 5.0 compatibility note" "Moodle 5.0" "$OUT"
    echo ""

    echo "--- Test: --format=json produces valid JSON ---"
    run_moosh plugin:releasenotes atto_wiris 2025041400 --format=json
    EC=$?
    assert_exit_code "Exit code 0 for JSON output" 0 "$EC"
    if echo "$OUT" | "$PHP" -r 'json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);' 2>/dev/null; then
        echo "  PASS: output is valid JSON"
        ((PASS++))
    else
        echo "  FAIL: output is not valid JSON"
        echo "$OUT"
        ((FAIL++))
    fi
    echo ""

    echo "--- Test: Unknown version fails with a helpful message ---"
    run_moosh plugin:releasenotes atto_wiris 1900010100
    EC=$?
    assert_exit_code "Exit code non-zero for unknown version" 1 "$EC"
    assert_output_contains "Names the missing version" "1900010100" "$OUT"
    assert_output_contains "Lists versions that were found" "Versions found" "$OUT"
    echo ""

    echo "--- Test: Unknown plugin fails with a helpful message ---"
    run_moosh plugin:releasenotes this_plugin_does_not_exist_xyz 2025041400
    EC=$?
    assert_exit_code "Exit code non-zero for unknown plugin" 1 "$EC"
    echo ""

    echo "--- Test: --since range spans multiple versions ---"
    run_moosh plugin:releasenotes atto_wiris 2025041400 --since=2023010100
    EC=$?
    assert_exit_code "Exit code 0 for a multi-version range" 0 "$EC"
    assert_output_contains "Shows a version count summary" "version" "$OUT"
    assert_output_contains "Includes the target release" "8.9.0" "$OUT"
    echo ""

    echo "--- Test: --since range as JSON ---"
    run_moosh plugin:releasenotes atto_wiris 2025041400 --since=2023010100 --format=json
    EC=$?
    assert_exit_code "Exit code 0 for range JSON" 0 "$EC"
    if echo "$OUT" | "$PHP" -r 'json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);' 2>/dev/null; then
        echo "  PASS: output is valid JSON"
        ((PASS++))
    else
        echo "  FAIL: output is not valid JSON"
        echo "$OUT"
        ((FAIL++))
    fi
    assert_output_contains "JSON range includes a versions array" "\"versions\"" "$OUT"
    echo ""
fi

print_summary
