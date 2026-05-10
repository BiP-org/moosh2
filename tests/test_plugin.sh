#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list, plugin:download, plugin:install, plugin:uninstall
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin commands integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# Clean up any leftover test plugins from previous runs
sudo rm -rf "$MOODLE_PATH/blocks/progress" 2>/dev/null

# ═══════════════════════════════════════════════════════════════════
# plugin:list
# ═══════════════════════════════════════════════════════════════════

echo "========== plugin:list =========="
echo ""

echo "--- Test: CSV output ---"
LINE_COUNT=$($PHP $MOOSH plugin:list -o csv 2>&1 | wc -l)
if [ "$LINE_COUNT" -gt 100 ]; then
    echo "  PASS: Has many plugins ($LINE_COUNT)"
    ((PASS++))
else
    echo "  FAIL: Expected >100 plugins, got $LINE_COUNT"
    ((FAIL++))
fi
FIRST_LINE=$($PHP $MOOSH plugin:list --type aiplacement -o csv 2>&1 | head -1)
assert_output_contains "Header row" "component,versions,url" "$FIRST_LINE"
echo ""

echo "--- Test: JSON output ---"
run_moosh plugin:list --type aiplacement -o json
assert_output_contains "JSON has component" '"component"' "$OUT"
assert_output_contains "JSON has versions" '"versions"' "$OUT"
assert_output_contains "JSON has url" '"url"' "$OUT"
echo ""

echo "--- Test: Type filter ---"
run_moosh plugin:list --type aiplacement -o csv
assert_output_contains "Has aiplacement plugins" "aiplacement_" "$OUT"
assert_output_not_contains "No block plugins" "block_" "$OUT"
echo ""

echo "--- Test: Name only ---"
run_moosh plugin:list --type aiplacement --name-only
FIRST_LINE=$(echo "$OUT" | head -1)
# Name-only should not have CSV headers or commas
assert_output_not_contains "No CSV header" "component,versions" "$OUT"
# Should have underscore-separated plugin names
assert_output_contains "Has plugin names" "aiplacement_" "$FIRST_LINE"
echo ""

echo "--- Test: --ensure-cache (preflight) ---"
# Force a refresh first so we have a known-fresh cache.
run_moosh plugin:list --ensure-cache --refresh
assert_output_contains "Refresh message" "Plugin cache refreshed" "$OUT"
assert_output_not_contains "No table header" "component,versions" "$OUT"
# Second call: cache is fresh, should report up-to-date without re-downloading.
run_moosh plugin:list --ensure-cache
assert_output_contains "Up-to-date message" "Plugin cache is up to date" "$OUT"
assert_output_not_contains "No table header" "component,versions" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:list --help
assert_output_contains "Help description" "List available plugins" "$OUT"
assert_output_contains "Help shows --type" "--type" "$OUT"
assert_output_contains "Help shows --name-only" "--name-only" "$OUT"
assert_output_contains "Help shows --ensure-cache" "--ensure-cache" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# plugin:download
# ═══════════════════════════════════════════════════════════════════

echo "========== plugin:download =========="
echo ""

DOWNLOAD_DIR=$(mktemp -d)

echo "--- Test: URL only ---"
run_moosh plugin:download --moodle-version 4.5 --url mod_attendance
assert_output_contains "Shows download URL" "moodle.org/plugins/download" "$OUT"
assert_output_not_contains "No Downloaded message" "Downloaded:" "$OUT"
echo ""

echo "--- Test: Download file ---"
OUT=$(cd "$DOWNLOAD_DIR" && $PHP $MOOSH plugin:download --moodle-version 4.5 mod_attendance 2>&1)
assert_output_contains "Shows plugin name" "mod_attendance" "$OUT"
assert_output_contains "Shows Downloaded" "Downloaded:" "$OUT"
if [ -f "$DOWNLOAD_DIR/mod_attendance.zip" ]; then
    FILE_SIZE=$(stat -c%s "$DOWNLOAD_DIR/mod_attendance.zip")
    if [ "$FILE_SIZE" -gt 1000 ]; then
        echo "  PASS: ZIP file downloaded ($FILE_SIZE bytes)"
        ((PASS++))
    else
        echo "  FAIL: ZIP file too small ($FILE_SIZE bytes)"
        ((FAIL++))
    fi
else
    echo "  FAIL: ZIP file not created"
    ((FAIL++))
fi
rm -f "$DOWNLOAD_DIR/mod_attendance.zip"
echo ""

echo "--- Test: Nonexistent plugin ---"
run_moosh plugin:download --moodle-version 4.5 --url xyznonexistent_plugin123
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent plugin" 1 "$EXIT_CODE"
assert_output_contains "Error message" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:download --help
assert_output_contains "Help description" "Download a plugin ZIP" "$OUT"
assert_output_contains "Help shows --moodle-version" "--moodle-version" "$OUT"
assert_output_contains "Help shows --url" "--url" "$OUT"
echo ""


rm -rf "$DOWNLOAD_DIR"

# ═══════════════════════════════════════════════════════════════════
# plugin:install
# ═══════════════════════════════════════════════════════════════════

echo "========== plugin:install =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh plugin:install -p "$MOODLE_PATH" --force block_progress
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows plugin name" "block_progress" "$OUT"
assert_output_contains "Shows target path" "blocks/progress" "$OUT"
echo ""

echo "--- Test: Install with --run ---"
run_moosh plugin:install -p "$MOODLE_PATH" --run --force block_progress
assert_output_contains "Shows installed" "Installed block_progress" "$OUT"
assert_output_contains "Shows target" "blocks/progress" "$OUT"
if [ -f "$MOODLE_PATH/blocks/progress/version.php" ]; then
    echo "  PASS: Plugin directory created with version.php"
    ((PASS++))
else
    echo "  FAIL: Plugin directory not created"
    ((FAIL++))
fi
echo ""

echo "--- Test: Already exists ---"
run_moosh plugin:install -p "$MOODLE_PATH" --run --force block_progress
EXIT_CODE=$?
assert_exit_code "Exit code 1 for existing plugin" 1 "$EXIT_CODE"
assert_output_contains "Already exists error" "already exists" "$OUT"
echo ""

echo "--- Test: Reinstall with --delete ---"
run_moosh plugin:install -p "$MOODLE_PATH" --run --force --delete block_progress
assert_output_contains "Reinstalled" "Installed block_progress" "$OUT"
echo ""

echo "--- Test: --from-file dry run with auto-detected plugin name ---"
FROMFILE_DIR=$(mktemp -d)
OUT=$(cd "$FROMFILE_DIR" && $PHP $MOOSH plugin:download --moodle-version 5.1 mod_attendance 2>&1)
ZIP_PATH="$FROMFILE_DIR/mod_attendance.zip"
if [ ! -f "$ZIP_PATH" ]; then
    echo "  FAIL: Could not stage plugin ZIP for --from-file tests"
    ((FAIL++))
fi
run_moosh plugin:install -p "$MOODLE_PATH" --from-file="$ZIP_PATH"
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows detected plugin" "mod_attendance" "$OUT"
assert_output_contains "Shows local source" "(local file)" "$OUT"
echo ""

echo "--- Test: --from-file install with --run ---"
run_moosh plugin:install -p "$MOODLE_PATH" --from-file="$ZIP_PATH" --run
assert_output_contains "Shows installed" "Installed mod_attendance" "$OUT"
assert_output_contains "Shows source path" "$ZIP_PATH" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/version.php" ]; then
    echo "  PASS: Plugin installed from local ZIP"
    ((PASS++))
else
    echo "  FAIL: Plugin not installed from local ZIP"
    ((FAIL++))
fi
echo ""

echo "--- Test: --from-file with mismatched plugin name ---"
run_moosh plugin:install -p "$MOODLE_PATH" --from-file="$ZIP_PATH" mod_wrong
EC=$?
assert_exit_code "Exit code 1 for mismatch" 1 $EC
assert_output_contains "Shows mismatch error" "Plugin name mismatch" "$OUT"
echo ""

echo "--- Test: --from-file with nonexistent file ---"
run_moosh plugin:install -p "$MOODLE_PATH" --from-file=/tmp/does_not_exist_$$.zip
EC=$?
assert_exit_code "Exit code 1 for missing file" 1 $EC
assert_output_contains "Shows file not found" "File not found" "$OUT"
echo ""

echo "--- Test: --from-file rejects --release ---"
run_moosh plugin:install -p "$MOODLE_PATH" --from-file="$ZIP_PATH" --release=2024010700
EC=$?
assert_exit_code "Exit code 1 for combo" 1 $EC
assert_output_contains "Shows combo error" "cannot be combined" "$OUT"
echo ""

# Cleanup mod_attendance + ZIP staging dir before the no-write-permission test
# so its strip/restore on mod/ runs against a clean state.
rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
rm -rf "$FROMFILE_DIR"

echo "--- Test: No write permission on plugin directory ---"
# Strip current user's write access from mod/ to simulate a locked-down filesystem.
ORIG_MOD_PERMS=$(stat -c '%a' "$MOODLE_PATH/mod")
chmod u-w "$MOODLE_PATH/mod"
run_moosh plugin:install -p "$MOODLE_PATH" --run --force mod_attendance
EC=$?
# Restore before asserting so a failing assert doesn't leave mod/ locked.
chmod "$ORIG_MOD_PERMS" "$MOODLE_PATH/mod"
assert_exit_code "Exit code 1 for no write perms" 1 $EC
assert_output_contains "No write permission error" "No write permission" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: Plugin not installed when permission denied"
    ((PASS++))
else
    echo "  FAIL: Plugin was installed despite permission denial"
    ((FAIL++))
    rm -rf "$MOODLE_PATH/mod/attendance"
fi
echo ""

echo "--- Test: Help ---"
run_moosh plugin:install --help
assert_output_contains "Help description" "Install a plugin" "$OUT"
assert_output_contains "Help shows --force" "--force" "$OUT"
assert_output_contains "Help shows --delete" "--delete" "$OUT"
assert_output_contains "Help shows --from-file" "--from-file" "$OUT"
echo ""


# ═══════════════════════════════════════════════════════════════════
# plugin:uninstall
# ═══════════════════════════════════════════════════════════════════

echo "========== plugin:uninstall =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh plugin:uninstall -p "$MOODLE_PATH" block_progress
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows plugin name" "block_progress" "$OUT"
assert_output_contains "Shows directory" "blocks/progress" "$OUT"
echo ""

echo "--- Test: Uninstall with --run ---"
run_moosh plugin:uninstall -p "$MOODLE_PATH" --run block_progress
assert_output_contains "Shows uninstalled" "Uninstalled block_progress" "$OUT"
if [ ! -d "$MOODLE_PATH/blocks/progress" ]; then
    echo "  PASS: Plugin directory removed"
    ((PASS++))
else
    echo "  FAIL: Plugin directory still exists"
    ((FAIL++))
fi
echo ""

echo "--- Test: Nonexistent plugin ---"
run_moosh plugin:uninstall -p "$MOODLE_PATH" xyznonexistent_plugin123
EXIT_CODE=$?
assert_exit_code "Exit code 1 for nonexistent plugin" 1 "$EXIT_CODE"
assert_output_contains "Not found error" "not found" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:uninstall --help
assert_output_contains "Help description" "Uninstall a plugin" "$OUT"
echo ""


# ── Cleanup ──────────────────────────────────────────────────────

echo "--- Cleaning up ---"
sudo rm -rf "$MOODLE_PATH/blocks/progress" 2>/dev/null
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  plugin:reinstall
# ═══════════════════════════════════════════════════════════════════

echo "========== plugin:reinstall =========="
echo ""

echo "--- Test: Dry run ---"
run_moosh plugin:reinstall mod_forum -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Dry run exit code 0" 0 $EC
assert_output_contains "Shows dry run" "Dry run" "$OUT"
assert_output_contains "Shows plugin name" "mod_forum" "$OUT"
assert_output_contains "Shows directory" "mod/forum" "$OUT"
echo ""

echo "--- Test: Invalid plugin ---"
run_moosh plugin:reinstall nonexistent_plugin -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for invalid" 1 $EC
assert_output_contains "Not found error" "not found" "$OUT"
echo ""

echo "--- Test: Bad format ---"
run_moosh plugin:reinstall badformat -p "$MOODLE_PATH"
EC=$?
assert_exit_code "Exit code 1 for bad format" 1 $EC
assert_output_contains "Invalid name error" "Invalid plugin name" "$OUT"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:reinstall -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Uninstall and reinstall" "$OUT"
echo ""

print_summary
