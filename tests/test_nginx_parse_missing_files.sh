#!/usr/bin/env bash
#
# Integration test for moosh2 nginx:parse-missing-files
#
# This command does not bootstrap Moodle (BootstrapLevel::None), but we still
# source common.sh for the test-run lock, the run_moosh helper, and the standard
# assertions. The Moodle install is not touched.
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_nginx_parse_missing_files.sh
#

source "$(dirname "$0")/common.sh"

FIXTURE=$(mktemp /tmp/moosh-nginx-XXXXXX.log)
EMPTY_FIXTURE=$(mktemp /tmp/moosh-nginx-empty-XXXXXX.log)
CUSTOM_FIXTURE=$(mktemp /tmp/moosh-nginx-custom-XXXXXX.log)
MISSING="/tmp/moosh-nginx-does-not-exist-$$.log"

cleanup() {
    rm -f "$FIXTURE" "$EMPTY_FIXTURE" "$CUSTOM_FIXTURE"
    _moosh_test_release_lock
}
trap cleanup EXIT

# Default Nginx combined-format fixture.
cat > "$FIXTURE" <<'EOF'
127.0.0.1 - - [10/Oct/2000:13:55:36 +0000] "GET /file.php/1234/missing.pdf HTTP/1.1" 404 0 "-" "bot"
127.0.0.1 - - [10/Oct/2000:13:55:37 +0000] "GET /file.php/1234/missing.pdf HTTP/1.1" 404 0 "-" "bot"
192.168.0.1 - alice [10/Oct/2000:14:00:00 +0000] "GET /file.php/5678/other.pdf HTTP/2.0" 404 0 "-" "x"
10.0.0.1 - - [10/Oct/2000:14:30:00 +0000] "GET /file.php/9999/found.pdf HTTP/1.1" 200 1024 "-" "y"
10.0.0.2 - - [10/Oct/2000:14:35:00 +0000] "GET /index.php HTTP/1.1" 404 0 "-" "z"
10.0.0.3 - - [11/Oct/2000:09:00:00 +0000] "GET /file.php/7777/late.pdf HTTP/1.1" 404 0 "-" "w"
unparseable garbage line that should be skipped silently
10.0.0.4 - - [11/Oct/2000:10:00:00 +0000] "POST /file.php/8888/post.pdf HTTP/1.1" 404 0 "-" "v"
EOF

# Custom-format fixture: $remote_addr [$time_local] "$request" $status $request_time
cat > "$CUSTOM_FIXTURE" <<'EOF'
10.0.0.1 [10/Oct/2000:13:55:36 +0000] "GET /file.php/4242/custom.pdf HTTP/1.1" 404 0.001
10.0.0.1 [10/Oct/2000:14:00:00 +0000] "GET /file.php/4242/custom.pdf HTTP/1.1" 404 0.002
10.0.0.2 [10/Oct/2000:14:01:00 +0000] "GET /file.php/3333/onlyone.pdf HTTP/1.1" 404 0.003
10.0.0.3 [10/Oct/2000:14:02:00 +0000] "GET /file.php/3333/onlyone.pdf HTTP/1.1" 200 0.005
EOF

echo "=== moosh2 nginx:parse-missing-files integration tests ==="
echo "Fixture:        $FIXTURE"
echo "Custom fixture: $CUSTOM_FIXTURE"
echo ""

# ── Happy path ────────────────────────────────────────────────────

echo "--- Test: Counts 404s on /file.php/ URLs (combined format) ---"
run_moosh nginx:parse-missing-files "$FIXTURE"
assert_output_contains "/1234 reported with count 2" "2,1234/missing.pdf" "$OUT"
assert_output_contains "/5678 reported with count 1" "1,5678/other.pdf" "$OUT"
assert_output_contains "/7777 reported with count 1" "1,7777/late.pdf" "$OUT"
echo ""

echo "--- Test: Skips 200 responses ---"
assert_output_not_contains "200-status URL excluded" "9999/found.pdf" "$OUT"
echo ""

echo "--- Test: Skips non-file.php 404s ---"
assert_output_not_contains "non-file.php URL excluded" "/index.php" "$OUT"
echo ""

echo "--- Test: Skips non-GET 404s on /file.php ---"
assert_output_not_contains "POST 404 excluded" "8888/post.pdf" "$OUT"
echo ""

# ── --after filter ────────────────────────────────────────────────

echo "--- Test: --after filters out earlier entries ---"
run_moosh nginx:parse-missing-files --after="2000-10-11 00:00:00 +0000" "$FIXTURE"
assert_output_contains "Late entry kept" "1,7777/late.pdf" "$OUT"
assert_output_not_contains "Earlier /1234 dropped" "1234/missing.pdf" "$OUT"
assert_output_not_contains "Earlier /5678 dropped" "5678/other.pdf" "$OUT"
echo ""

echo "--- Test: -a short option works ---"
run_moosh nginx:parse-missing-files -a "2000-10-11 00:00:00 +0000" "$FIXTURE"
assert_output_contains "-a accepted as alias for --after" "1,7777/late.pdf" "$OUT"
echo ""

# ── Custom log_format ─────────────────────────────────────────────

echo "--- Test: --log-format with custom Nginx format ---"
run_moosh nginx:parse-missing-files \
    --log-format='$remote_addr [$time_local] "$request" $status $request_time' \
    "$CUSTOM_FIXTURE"
assert_output_contains "Custom-format /4242 reported with count 2" "2,4242/custom.pdf" "$OUT"
assert_output_contains "Custom-format /3333 reported once (200 hit excluded)" "1,3333/onlyone.pdf" "$OUT"
echo ""

echo "--- Test: Invalid --log-format fails with helpful message ---"
run_moosh nginx:parse-missing-files --log-format='no variables here' "$FIXTURE"
EXIT=$?
assert_exit_code "Invalid log-format exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions --log-format" "Invalid --log-format" "$OUT"
echo ""

# ── Empty input ───────────────────────────────────────────────────

echo "--- Test: Empty log produces no output and succeeds ---"
run_moosh nginx:parse-missing-files "$EMPTY_FIXTURE"
EXIT=$?
assert_exit_code "Empty log exits 0" 0 "$EXIT"
echo ""

# ── Error paths ───────────────────────────────────────────────────

echo "--- Test: Missing logfile fails with helpful message ---"
run_moosh nginx:parse-missing-files "$MISSING"
EXIT=$?
assert_exit_code "Missing file exits non-zero" 1 "$EXIT"
assert_output_contains "Error message names the missing path" "$MISSING" "$OUT"
echo ""

echo "--- Test: Invalid --after fails with helpful message ---"
run_moosh nginx:parse-missing-files --after="not a date" "$FIXTURE"
EXIT=$?
assert_exit_code "Invalid date exits non-zero" 1 "$EXIT"
assert_output_contains "Error message mentions --after" "Invalid date for --after" "$OUT"
echo ""

# ── Help ──────────────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh nginx:parse-missing-files --help
assert_output_contains "Help shows description" "404" "$OUT"
assert_output_contains "Help shows logfile argument" "logfile" "$OUT"
assert_output_contains "Help shows --after option" "--after" "$OUT"
assert_output_contains "Help shows -a alias" "-a, --after" "$OUT"
assert_output_contains "Help shows --log-format option" "--log-format" "$OUT"
assert_output_contains "Help shows usage example" "/var/log/nginx/access.log" "$OUT"
echo ""

print_summary
