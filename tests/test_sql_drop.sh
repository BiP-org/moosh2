#!/usr/bin/env bash
#
# Integration test for moosh2 sql:drop
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_sql_drop.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 sql:drop integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── Help ─────────────────────────────────────────────────────────

echo "--- Test: sql:drop help ---"
run_moosh sql:drop -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Drop every table" "$OUT"
assert_output_contains "Help shows --exclude" "--exclude" "$OUT"
assert_output_contains "Help shows --run" "--run" "$OUT"
echo ""

# ── Dry run lists every table ────────────────────────────────────

echo "--- Test: sql:drop dry run lists tables ---"
run_moosh sql:drop -p "$MOODLE_PATH"
assert_output_contains "Dry run message" "Dry run" "$OUT"
assert_output_contains "Lists mdl_user" "mdl_user" "$OUT"
assert_output_contains "Lists mdl_config" "mdl_config" "$OUT"
assert_output_contains "Lists mdl_course" "mdl_course" "$OUT"
echo ""

# Confirm dry run did not actually drop anything.
echo "--- Test: dry run did not drop anything ---"
run_moosh sql:run -p "$MOODLE_PATH" "SELECT count(*) AS c FROM {user}"
assert_output_contains "users table still queryable" " c " "$OUT"
echo ""

# ── --exclude removes a table from the dry-run plan ──────────────

echo "--- Test: sql:drop --exclude omits excluded table ---"
run_moosh sql:drop -p "$MOODLE_PATH" --exclude=mdl_config
assert_output_contains "Lists mdl_user" "mdl_user" "$OUT"
if echo "$OUT" | grep -qE '^  mdl_config$'; then
    echo "  FAIL: Omits mdl_config from list (line '  mdl_config' was present)"
    ((FAIL++))
else
    echo "  PASS: Omits mdl_config from list"
    ((PASS++))
fi
echo ""

echo "--- Test: sql:drop --exclude with multiple tables ---"
run_moosh sql:drop -p "$MOODLE_PATH" --exclude=mdl_config,mdl_user
assert_output_contains "Lists mdl_course" "mdl_course" "$OUT"
if echo "$OUT" | grep -qE '^  mdl_user$'; then
    echo "  FAIL: Omits mdl_user (line '  mdl_user' was present)"
    ((FAIL++))
else
    echo "  PASS: Omits mdl_user"
    ((PASS++))
fi
if echo "$OUT" | grep -qE '^  mdl_config$'; then
    echo "  FAIL: Omits mdl_config (line '  mdl_config' was present)"
    ((FAIL++))
else
    echo "  PASS: Omits mdl_config"
    ((PASS++))
fi
echo ""

echo "--- Test: sql:drop --exclude warns about unknown table ---"
run_moosh sql:drop -p "$MOODLE_PATH" --exclude=mdl_no_such_table_xyz
assert_output_contains "Warns about missing excluded table" "not present" "$OUT"
echo ""

# ── --run actually drops every table ─────────────────────────────

echo "--- Test: sql:drop --run drops all tables ---"
run_moosh sql:drop -p "$MOODLE_PATH" --run
assert_output_contains "Reports dropped count" "Dropped" "$OUT"

# Count remaining tables directly via the DB.
DB_NAME=$(grep -oP "\\\$CFG->dbname\s*=\s*'\K[^']+" "$MOODLE_DIR/config.php")
DB_USER=$(grep -oP "\\\$CFG->dbuser\s*=\s*'\K[^']+" "$MOODLE_DIR/config.php")
DB_PASS=$(grep -oP "\\\$CFG->dbpass\s*=\s*'\K[^']+" "$MOODLE_DIR/config.php")
DB_HOST=$(grep -oP "\\\$CFG->dbhost\s*=\s*'\K[^']+" "$MOODLE_DIR/config.php")

REMAINING=$(mysql -N -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "SHOW TABLES" "$DB_NAME" 2>/dev/null | wc -l)
if [ "$REMAINING" -eq 0 ]; then
    echo "  PASS: Database has no tables after sql:drop --run"
    ((PASS++))
else
    echo "  FAIL: Database still has $REMAINING table(s) after sql:drop --run"
    ((FAIL++))
fi
echo ""

# ── sql:drop with no tables left is a no-op ──────────────────────

echo "--- Test: sql:drop on empty database is a no-op ---"
run_moosh sql:drop -p "$MOODLE_PATH"
assert_output_contains "Reports no tables to drop" "No tables to drop" "$OUT"
echo ""

# ── Restore the test DB so subsequent runs/tests are not broken ──

echo "--- Restoring Moodle to known state for next run ---"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── --exclude actually keeps the excluded table after --run ──────

echo "--- Test: sql:drop --exclude --run keeps excluded table ---"
run_moosh sql:drop -p "$MOODLE_PATH" --exclude=mdl_config --run
assert_output_contains "Reports dropped count" "Dropped" "$OUT"

KEPT=$(mysql -N -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "SHOW TABLES LIKE 'mdl_config'" "$DB_NAME" 2>/dev/null | wc -l)
if [ "$KEPT" -eq 1 ]; then
    echo "  PASS: mdl_config survived sql:drop --exclude=mdl_config --run"
    ((PASS++))
else
    echo "  FAIL: mdl_config was dropped despite --exclude"
    ((FAIL++))
fi

OTHER=$(mysql -N -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "SHOW TABLES" "$DB_NAME" 2>/dev/null | wc -l)
if [ "$OTHER" -eq 1 ]; then
    echo "  PASS: Only the excluded table remains ($OTHER table left)"
    ((PASS++))
else
    echo "  FAIL: Expected exactly 1 table to remain, got $OTHER"
    ((FAIL++))
fi
echo ""

# Restore for any following test in run_all_tests.sh.
echo "--- Final restore ---"
bash "$SCRIPT_DIR/clear.sh"
echo ""

print_summary
