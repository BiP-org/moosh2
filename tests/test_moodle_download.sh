#!/usr/bin/env bash
#
# Integration test for moosh2 moodle:download
#
# Exercises URL resolution with --url for a few known-shape inputs and
# the input-validation paths. Avoids real downloads to keep the test
# offline and fast — except for the no-argument case, which has to hit
# download.moodle.org/releases/latest/ to discover the current branch.
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_moodle_download.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 moodle:download integration tests ==="
echo ""

# ── X.Y → latest-of-branch URL ────────────────────────────────────

echo "--- Test: X.Y resolves to moodle-latest-<collapsed>.tgz ---"
run_moosh moodle:download 5.2 --url
EXIT=$?
assert_exit_code "5.2 --url exits 0" 0 "$EXIT"
assert_output_contains "5.2 collapses to stable502" \
    "https://download.moodle.org/download.php/direct/stable502/moodle-latest-502.tgz" "$OUT"

run_moosh moodle:download 4.4 --url
EXIT=$?
assert_exit_code "4.4 --url exits 0" 0 "$EXIT"
assert_output_contains "4.4 collapses to stable404 (v4 naming)" \
    "https://download.moodle.org/download.php/direct/stable404/moodle-latest-404.tgz" "$OUT"

run_moosh moodle:download 3.10 --url
EXIT=$?
assert_exit_code "3.10 --url exits 0" 0 "$EXIT"
assert_output_contains "3.10 collapses to stable310 (pre-v4 naming)" \
    "https://download.moodle.org/download.php/direct/stable310/moodle-latest-310.tgz" "$OUT"
echo ""

# ── X.Y.Z → exact-release URL ─────────────────────────────────────

echo "--- Test: X.Y.Z resolves to moodle-X.Y.Z.tgz ---"
run_moosh moodle:download 5.2.1 --url
EXIT=$?
assert_exit_code "5.2.1 --url exits 0" 0 "$EXIT"
assert_output_contains "5.2.1 builds exact-release URL" \
    "https://download.moodle.org/download.php/direct/stable502/moodle-5.2.1.tgz" "$OUT"

run_moosh moodle:download 3.11.5 --url
EXIT=$?
assert_exit_code "3.11.5 --url exits 0" 0 "$EXIT"
assert_output_contains "3.11.5 builds exact-release URL on stable311" \
    "https://download.moodle.org/download.php/direct/stable311/moodle-3.11.5.tgz" "$OUT"
echo ""

# ── Input validation ──────────────────────────────────────────────

echo "--- Test: Invalid version strings fail cleanly ---"
run_moosh moodle:download foo --url
EXIT=$?
assert_exit_code "Non-numeric version exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions format" "X.Y or X.Y.Z" "$OUT"

run_moosh moodle:download 5 --url
EXIT=$?
assert_exit_code "Single-component version exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions format" "X.Y or X.Y.Z" "$OUT"

run_moosh moodle:download 5.2.1.0 --url
EXIT=$?
assert_exit_code "Four-component version exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions format" "X.Y or X.Y.Z" "$OUT"
echo ""

# ── Latest stable (network) ───────────────────────────────────────
#
# This is the only case that requires download.moodle.org reachability.
# Skip if the host is not resolvable so the suite stays useful offline.

echo "--- Test: No argument resolves the latest stable URL (network) ---"
if curl -sSf -o /dev/null --max-time 5 "https://download.moodle.org/releases/latest/" 2>/dev/null; then
    run_moosh moodle:download --url
    EXIT=$?
    assert_exit_code "No-arg --url exits 0" 0 "$EXIT"
    assert_output_contains "Latest URL points at download.moodle.org" \
        "https://download.moodle.org/download.php/direct/stable" "$OUT"
    assert_output_contains "Latest URL is a moodle-latest-* tarball" \
        "moodle-latest-" "$OUT"
else
    echo "  SKIP: download.moodle.org unreachable; skipping latest-stable test"
fi
echo ""

print_summary
