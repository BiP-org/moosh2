#!/usr/bin/env bash
#
# Integration test for moosh2 theme:info, theme:settings-export, theme:settings-import
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_theme.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 theme commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

EXPORT_DIR=$(mktemp -d)

# ═══════════════════════════════════════════════════════════════════
# theme:info
# ═══════════════════════════════════════════════════════════════════

echo "========== theme:info =========="
echo ""

echo "--- Test: Overview (table) ---"
run_moosh theme:info -p "$MOODLE_PATH"
assert_output_contains "Shows site theme" "boost" "$OUT"
assert_output_contains "Shows course overrides" "Course theme overrides" "$OUT"
assert_output_contains "Shows category overrides" "Category theme overrides" "$OUT"
assert_output_contains "Shows user overrides" "User theme overrides" "$OUT"
echo ""

echo "--- Test: Overview (CSV) ---"
run_moosh theme:info -p "$MOODLE_PATH" -o csv
assert_output_contains "CSV has site theme" "boost" "$OUT"
assert_output_contains "CSV has header" "Site theme" "$OUT"
echo ""

echo "--- Test: Overview (JSON) ---"
run_moosh theme:info -p "$MOODLE_PATH" -o json
assert_output_contains "JSON has site theme" '"Site theme"' "$OUT"
assert_output_contains "JSON has boost" "boost" "$OUT"
echo ""

echo "--- Test: Detailed info for boost ---"
run_moosh theme:info -p "$MOODLE_PATH" boost
assert_output_contains "Shows name" "boost" "$OUT"
assert_output_contains "Shows component" "theme_boost" "$OUT"
assert_output_contains "Shows version disk" "Version (disk)" "$OUT"
assert_output_contains "Shows status" "uptodate" "$OUT"
assert_output_contains "Shows active site theme" "Active site theme" "$OUT"
assert_output_contains "Shows settings count" "Configuration settings" "$OUT"
echo ""

echo "--- Test: Detailed info (CSV) ---"
run_moosh theme:info -p "$MOODLE_PATH" boost -o csv
assert_output_contains "CSV detail has component" "theme_boost" "$OUT"
echo ""

echo "--- Test: Nonexistent theme ---"
run_moosh theme:info -p "$MOODLE_PATH" nonexistent
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent theme" 1 "$EXIT_CODE"
assert_output_contains "Shows not found" "not found" "$OUT"
assert_output_contains "Shows available themes" "Available themes" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh theme:info -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Show theme usage information" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# theme:settings-export
# ═══════════════════════════════════════════════════════════════════

echo "========== theme:settings-export =========="
echo ""

echo "--- Test: Export boost settings ---"
run_moosh theme:settings-export -p "$MOODLE_PATH" boost --outputdir "$EXPORT_DIR"
assert_output_contains "Shows exported" "exported" "$OUT"
EXPORT_FILE=$(ls -1 "$EXPORT_DIR"/boost_settings_*.tar.gz 2>/dev/null | head -1)
if [ -n "$EXPORT_FILE" ] && [ -f "$EXPORT_FILE" ]; then
    echo "  PASS: Archive file created"
    ((PASS++))
else
    echo "  FAIL: Archive file not created"
    ((FAIL++))
fi
echo ""

echo "--- Test: Nonexistent theme ---"
run_moosh theme:settings-export -p "$MOODLE_PATH" nonexistent --outputdir "$EXPORT_DIR"
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent theme" 1 "$EXIT_CODE"
assert_output_contains "Shows not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh theme:settings-export -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Export theme settings" "$OUT"
assert_output_contains "Help shows --outputdir" "--outputdir" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# theme:settings-import
# ═══════════════════════════════════════════════════════════════════

echo "========== theme:settings-import =========="
echo ""

# Use the first export file
EXPORT_FILE=$(ls -1 "$EXPORT_DIR"/boost_settings_*.tar.gz 2>/dev/null | head -1)

echo "--- Test: Dry run ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" "$EXPORT_FILE"
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows theme name" "boost" "$OUT"
assert_output_contains "Shows component" "theme_boost" "$OUT"
assert_output_contains "Shows settings count" "Settings:" "$OUT"
echo ""

echo "--- Test: Import with --run ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" --run "$EXPORT_FILE"
assert_output_contains "Shows imported" "imported" "$OUT"
assert_output_contains "Shows component" "theme_boost" "$OUT"
echo ""

echo "--- Test: Import to different theme ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" --target-theme classic "$EXPORT_FILE"
assert_output_contains "Target theme" "classic" "$OUT"
assert_output_contains "Target component" "theme_classic" "$OUT"
echo ""

echo "--- Test: Nonexistent target theme ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" --run --target-theme nonexistent "$EXPORT_FILE"
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent target" 1 "$EXIT_CODE"
assert_output_contains "Shows not installed" "not installed" "$OUT"
echo ""

echo "--- Test: Nonexistent file ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" /tmp/nonexistent.tar.gz
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent file" 1 "$EXIT_CODE"
assert_output_contains "Shows not found" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh theme:settings-import -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Import theme settings" "$OUT"
assert_output_contains "Help shows --target-theme" "--target-theme" "$OUT"
echo ""


# ── Cleanup ──────────────────────────────────────────────────────

rm -rf "$EXPORT_DIR"

print_summary
