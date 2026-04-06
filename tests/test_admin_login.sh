#!/usr/bin/env bash
#
# Integration test for moosh2 admin:login command
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_admin_login.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 admin:login integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── Default output (cookie:value format) ──────────────────────────

echo "--- Test: Default output ---"
run_moosh admin:login -p "$MOODLE_PATH"
assert_output_contains "Contains MoodleSession" "MoodleSession" "$OUT"
assert_output_contains "Contains colon separator" ":" "$OUT"
# Session ID should be non-empty alphanumeric
SESSION_ID=$(echo "$OUT" | cut -d: -f2)
assert_output_not_empty "Session ID not empty" "$SESSION_ID"
echo ""

# ── CSV output ────────────────────────────────────────────────────

echo "--- Test: CSV output ---"
run_moosh admin:login -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV header" "cookie_name,cookie_value" "$OUT"
assert_output_contains "CSV has MoodleSession" "MoodleSession" "$OUT"
echo ""

# ── JSON output ───────────────────────────────────────────────────

echo "--- Test: JSON output ---"
run_moosh admin:login -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has cookie_name" '"cookie_name"' "$OUT"
assert_output_contains "JSON has cookie_value" '"cookie_value"' "$OUT"
assert_output_contains "JSON has MoodleSession" '"MoodleSession"' "$OUT"
echo ""

# ── Each call produces different session ──────────────────────────

echo "--- Test: Different sessions per call ---"
OUT1=$($PHP $MOOSH admin:login -p "$MOODLE_PATH")
OUT2=$($PHP $MOOSH admin:login -p "$MOODLE_PATH")
if [ "$OUT1" != "$OUT2" ]; then
    echo "  PASS: Different sessions per call"
    ((PASS++))
else
    echo "  FAIL: Sessions should be different per call"
    ((FAIL++))
fi
echo ""

# ── Help output ───────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh admin:login -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Create an admin login session" "$OUT"
echo ""

# ── Verify session works in browser via curl ──────────────────────

echo "--- Test: Session works via curl (--web-login) ---"
run_moosh admin:login -p "$MOODLE_PATH" --web-login
SESSION_COOKIE=$(echo "$OUT" | grep MoodleSession | head -1)
COOKIE_VALUE=$(echo "$SESSION_COOKIE" | cut -d: -f2)
WWWROOT=$($PHP -r "define('CLI_SCRIPT', true); require('$MOODLE_PATH/config.php'); echo \$CFG->wwwroot;" 2>/dev/null)
CURL_OUT=$(curl -s -L -b "MoodleSession=$COOKIE_VALUE" "$WWWROOT/user/profile.php" 2>&1)
ADMIN_CHECK=$(echo "$CURL_OUT" | grep -c 'Admin User')
if [ "$ADMIN_CHECK" -gt 0 ]; then
    echo "  PASS: Logged in as admin via curl"
    ((PASS++))
else
    echo "  FAIL: Session not valid in browser"
    echo "    Expected: 'Admin User' in page (found $ADMIN_CHECK times)"
    ((FAIL++))
fi
echo ""

# ── uid mismatch error ───────────────────────────────────────────

echo "--- Test: uid mismatch error ---"
# Temporarily change dataroot owner to trigger the check
DATAROOT=$($PHP -r "define('CLI_SCRIPT', true); require('$MOODLE_PATH/config.php'); echo \$CFG->dataroot;" 2>/dev/null)
ORIG_OWNER=$(stat -c '%u' "$DATAROOT")
sudo chown www-data "$DATAROOT"
run_moosh admin:login -p "$MOODLE_PATH"
EXIT_CODE=$?
assert_exit_code "Exit code 1 for uid mismatch" 1 "$EXIT_CODE"
assert_output_contains "Shows owner mismatch" "owned by" "$OUT"
assert_output_contains "Shows --web-login hint" "--web-login" "$OUT"
sudo chown "$ORIG_OWNER" "$DATAROOT"
echo ""

echo "--- Test: --web-login bypasses uid mismatch ---"
sudo chown www-data "$DATAROOT"
run_moosh admin:login -p "$MOODLE_PATH" --web-login
EXIT_CODE=$?
assert_exit_code "Exit code 0 with --web-login" 0 "$EXIT_CODE"
assert_output_contains "Returns MoodleSession" "MoodleSession" "$OUT"
sudo chown "$ORIG_OWNER" "$DATAROOT"
echo ""

# ── Help shows --web-login ────────────────────────────────────────

echo "--- Test: Help shows --web-login ---"
run_moosh admin:login -p "$MOODLE_PATH" --help
assert_output_contains "Help shows --web-login" "--web-login" "$OUT"
echo ""

# ── admin-login alias ─────────────────────────────────────────────


print_summary
