#!/usr/bin/env bash
#
# Integration test for moosh2 user:create command
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_user_create.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 user:create integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Step 1: Reset Moodle to known state
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: Dry run (no --run) ---"
run_moosh user:create -p "$MOODLE_PATH" newuser1
assert_output_contains "Shows dry run message" "Dry run" "$OUT"
assert_output_contains "Shows username" "newuser1" "$OUT"
# Verify user was NOT created
run_moosh user:list -p "$MOODLE_PATH" --sql "u.username = 'newuser1'" -o csv
VERIFY="$OUT"
assert_output_not_contains "User not created without --run" "newuser1" "$VERIFY"
echo ""

# ── Create single user ────────────────────────────────────────────

echo "--- Test: Create single user ---"
run_moosh user:create -p "$MOODLE_PATH" --run newuser1 -o csv
assert_output_contains "Output has header" "id,username,email" "$OUT"
assert_output_contains "Output has username" "newuser1" "$OUT"
assert_output_contains "Default email" "newuser1@example.com" "$OUT"
# Verify user exists
run_moosh user:list -p "$MOODLE_PATH" --sql "u.username = 'newuser1'" -o csv
VERIFY="$OUT"
assert_output_contains "User exists after create" "newuser1" "$VERIFY"
echo ""

# ── Create multiple users ─────────────────────────────────────────

echo "--- Test: Create multiple users ---"
run_moosh user:create -p "$MOODLE_PATH" --run multiuser1 multiuser2 multiuser3 -o csv
assert_output_contains "First user created" "multiuser1" "$OUT"
assert_output_contains "Second user created" "multiuser2" "$OUT"
assert_output_contains "Third user created" "multiuser3" "$OUT"
echo ""

# ── Create user with options ──────────────────────────────────────

echo "--- Test: Create user with all options ---"
run_moosh user:create -p "$MOODLE_PATH" --run \
    --email "john@example.com" \
    --firstname "John" \
    --lastname "Doe" \
    --city "London" \
    --country "GB" \
    --idnumber "EMP001" \
    johndoe -o csv
assert_output_contains "Created johndoe" "johndoe" "$OUT"
assert_output_contains "Custom email" "john@example.com" "$OUT"
# Verify with user:info
USER_ID=$(echo "$OUT" | tail -1 | cut -d, -f1)
run_moosh user:info -p "$MOODLE_PATH" "$USER_ID" -o json
VERIFY="$OUT"
assert_output_contains "Firstname is John" '"John"' "$VERIFY"
assert_output_contains "Lastname is Doe" '"Doe"' "$VERIFY"
echo ""

# ── JSON output ───────────────────────────────────────────────────

echo "--- Test: JSON output ---"
run_moosh user:create -p "$MOODLE_PATH" --run jsonuser1 -o json
assert_output_contains "JSON has username" '"username"' "$OUT"
assert_output_contains "JSON has jsonuser1" '"jsonuser1"' "$OUT"
echo ""

# ── Table output ──────────────────────────────────────────────────

echo "--- Test: Table output ---"
run_moosh user:create -p "$MOODLE_PATH" --run tableuser1
assert_output_contains "Table has id" "id" "$OUT"
assert_output_contains "Table has username" "username" "$OUT"
assert_output_contains "Table has tableuser1" "tableuser1" "$OUT"
echo ""

# ── Help output ───────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh user:create -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Create Moodle users" "$OUT"
assert_output_contains "Help shows --password" "--password" "$OUT"
assert_output_contains "Help shows --email" "--email" "$OUT"
assert_output_contains "Help shows --firstname" "--firstname" "$OUT"
assert_output_contains "Help shows --lastname" "--lastname" "$OUT"
assert_output_contains "Help shows --auth" "--auth" "$OUT"
echo ""

# ── user-create alias ────────────────────────────────────────────


print_summary
