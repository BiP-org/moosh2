#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:clamscan
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_clamscan.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:clamscan integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:clamscan --help
assert_output_contains "Help description" "Scan a plugin for malware" "$OUT"
assert_output_contains "Help shows --database" "--database" "$OUT"
assert_output_contains "Help shows --infected" "--infected" "$OUT"
assert_output_contains "Help shows --log" "--log" "$OUT"
echo ""

if ! command -v clamscan >/dev/null 2>&1; then
    echo "--- Test: clamscan not installed -> exit 2 ---"
    run_moosh plugin:clamscan mod_attendance
    EC=$?
    assert_exit_code "Exit code 2 when clamscan missing" 2 "$EC"
    assert_output_contains "Not found message" "clamscan was not found in PATH" "$OUT"
    echo ""
    print_summary
    exit 0
fi

echo "--- Test: No plugin name, no version.php in cwd -> exit 2 ---"
SCANDIR=$(mktemp -d)
OUT=$(cd "$SCANDIR" && $PHP $MOOSH plugin:clamscan 2>&1)
EC=$?
assert_exit_code "Exit code 2 when no version.php" 2 "$EC"
assert_output_contains "No version.php message" "no version.php found" "$OUT"
rm -rf "$SCANDIR"
echo ""

echo "--- Test: Scan a downloaded plugin (clean) ---"
run_moosh plugin:clamscan mod_attendance
EC=$?
assert_exit_code "Exit code 0 for a clean plugin" 0 "$EC"
echo ""

echo "--- Test: Scan the plugin in the current directory ---"
CWDDIR=$(mktemp -d)
run_moosh plugin:download --moodle-version 5.1 mod_attendance
cd "$CWDDIR"
unzip -q -o "$OLDPWD"/*.zip -d . 2>/dev/null || true
OUT=$(cd mod_attendance 2>/dev/null && $PHP $MOOSH plugin:clamscan 2>&1)
EC=$?
cd - >/dev/null
assert_exit_code "Exit code 0 scanning cwd" 0 "$EC"
rm -rf "$CWDDIR"
echo ""

print_summary
