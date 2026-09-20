#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-apply's orphan/drift detection
# (.downloaded-non-core-plugin marker, --warn-orphans/--prune-orphans) and
# its git-management guard (isGitManaged()), ported from install_plugins.php.
#
# Requires a working Moodle 5.2 installation at $MOODLE_DIR (see common.sh).
#
# Usage: bash tests/test_plugin_list_apply_orphans.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-apply orphan/git-guard integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

MARKER=".downloaded-non-core-plugin"

# Clean up any leftovers from a previous, interrupted run of this file.
sudo rm -rf "$MOODLE_PATH/mod/attendance" "$MOODLE_PATH/mod/board" 2>/dev/null

echo "--- Test: Help mentions the new options ---"
run_moosh plugin:list-apply --help
assert_output_contains "Help shows --prune-orphans" "--prune-orphans" "$OUT"
assert_output_contains "Help shows --warn-orphans" "--warn-orphans" "$OUT"
echo ""

LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/mod_attendance"

echo "--- Setup: resolve a real version via plugin:list-update ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
assert_output_not_empty "list-update produced output" "$OUT"
echo ""

# ── #1: marker written on a fresh install ─────────────────────────

echo "--- Test: a fresh install writes the .downloaded-non-core-plugin marker ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
assert_output_contains "Reports INSTALLED" "INSTALLED mod_attendance" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/$MARKER" ]; then
    echo "  PASS: marker file present after fresh install"
    ((PASS++))
else
    echo "  FAIL: marker file missing after fresh install"
    ((FAIL++))
fi
echo ""

# ── #1: backfill on an already-correct, previously-untracked install ──

echo "--- Test: backfill - marker is (re)created even when nothing needed installing ---"
rm -f "$MOODLE_PATH/mod/attendance/$MARKER"
if [ -f "$MOODLE_PATH/mod/attendance/$MARKER" ]; then
    echo "  FAIL: setup - could not remove marker to simulate a pre-existing install"
    ((FAIL++))
fi
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
assert_output_contains "Reports already at requested version" "OK      mod_attendance: already at" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/$MARKER" ]; then
    echo "  PASS: marker backfilled on a run that found it already correct"
    ((PASS++))
else
    echo "  FAIL: marker was NOT backfilled - a pre-existing plugin would look orphaned forever"
    ((FAIL++))
fi
echo ""

echo "--- Test: dry run does not write the marker ---"
rm -f "$MOODLE_PATH/mod/attendance/$MARKER"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" mod_attendance
if [ ! -f "$MOODLE_PATH/mod/attendance/$MARKER" ]; then
    echo "  PASS: dry run made no disk write"
    ((PASS++))
else
    echo "  FAIL: dry run wrote the marker file"
    ((FAIL++))
fi
# Restore it for the tests below, as if a --run had happened.
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
echo ""

# ── #1: orphan detection - warn by default, prune with --prune-orphans ──

echo "--- Test: component removed from the declarative list is reported as an orphan (--warn-orphans default) ---"
mkdir -p "$LISTDIR/removed" # placeholder dir kept out of the scan on purpose
mv "$LISTDIR/mod_attendance" "$LISTDIR/removed/mod_attendance"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
assert_output_contains "Warns about the orphan by component name" "WARN    mod_attendance is installed in" "$OUT"
assert_output_contains "Warn message mentions --prune-orphans" "--prune-orphans" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: orphan directory left in place under the default (warn-only) mode"
    ((PASS++))
else
    echo "  FAIL: orphan directory was deleted despite --warn-orphans being the default"
    ((FAIL++))
fi
echo ""

echo "--- Test: --prune-orphans without --run only previews the deletion ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --prune-orphans
assert_output_contains "Previews the deletion" "WOULD DELETE orphan mod_attendance" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: dry-run --prune-orphans did not delete anything"
    ((PASS++))
else
    echo "  FAIL: dry-run --prune-orphans deleted the directory - --run should be required"
    ((FAIL++))
fi
echo ""

echo "--- Test: --prune-orphans --run deletes the orphaned directory ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --prune-orphans --run
assert_output_contains "Reports the deletion" "Deleting orphaned plugin directory" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: orphaned directory deleted"
    ((PASS++))
else
    echo "  FAIL: orphaned directory still present after --prune-orphans --run"
    ((FAIL++))
fi
echo ""

echo "--- Test: --prune-orphans and --warn-orphans together is a usage error ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --prune-orphans --warn-orphans
EC=$?
assert_exit_code "Non-zero exit for conflicting flags" 1 "$EC"
echo ""

echo "--- Test: targeting a specific component by name never runs orphan detection ---"
mv "$LISTDIR/removed/mod_attendance" "$LISTDIR/mod_attendance"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
mkdir -p "$LISTDIR/removed/mod_attendance_stray"
echo "9999999999" > "$LISTDIR/removed/mod_attendance_stray/version"
# Simulate a stray marker from an unrelated, no-longer-listed directory so
# a full-list run WOULD warn about it - but this run names mod_attendance
# explicitly, so it must stay silent about anything else.
mkdir -p "$MOODLE_PATH/local/moosh_stray_test"
touch "$MOODLE_PATH/local/moosh_stray_test/$MARKER"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
assert_output_not_contains "No orphan warning when a subset of components was requested" "WARN " "$OUT"
rm -rf "$MOODLE_PATH/local/moosh_stray_test" "$LISTDIR/removed"
echo ""

echo "--- Cleaning up orphan-detection fixtures ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
rm -rf "$LISTDIR"
echo ""

# ── #2: git-managed directories (plain clone AND submodule) are protected on install too ──

echo "--- Test: a plain git clone (.git directory) at the target path is left alone on install ---"
GITLISTDIR=$(mktemp -d)
mkdir -p "$GITLISTDIR/mod_board"
run_moosh plugin:list-update --directory="$GITLISTDIR" --moodle-version=5.1 --run mod_board
rm -rf "$MOODLE_PATH/mod/board"
mkdir -p "$MOODLE_PATH/mod/board"
mkdir "$MOODLE_PATH/mod/board/.git" # not a real repo - the checker only needs the path to exist
echo "<?php // sentinel - must survive the run untouched" > "$MOODLE_PATH/mod/board/version.php"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$GITLISTDIR" --run mod_board
assert_output_contains "Leaves a plain-clone git directory alone" "managed by git - leaving as is" "$OUT"
if grep -q "sentinel - must survive" "$MOODLE_PATH/mod/board/version.php" 2>/dev/null; then
    echo "  PASS: git-managed (directory .git) plugin directory was not overwritten"
    ((PASS++))
else
    echo "  FAIL: git-managed (directory .git) plugin directory WAS overwritten by the installer"
    ((FAIL++))
fi
if [ -f "$MOODLE_PATH/mod/board/$MARKER" ]; then
    echo "  FAIL: a marker file was written into a git-managed directory"
    ((FAIL++))
else
    echo "  PASS: no marker file written into a git-managed directory"
    ((PASS++))
fi
echo ""

echo "--- Test: a submodule (.git FILE, not directory) at the target path is equally left alone on install ---"
rm -rf "$MOODLE_PATH/mod/board"
mkdir -p "$MOODLE_PATH/mod/board"
echo "gitdir: ../../.git/modules/mod/board" > "$MOODLE_PATH/mod/board/.git" # submodule form: .git is a file
echo "<?php // sentinel - must survive the run untouched" > "$MOODLE_PATH/mod/board/version.php"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$GITLISTDIR" --run mod_board
assert_output_contains "Leaves a submodule (.git file) directory alone" "managed by git - leaving as is" "$OUT"
if grep -q "sentinel - must survive" "$MOODLE_PATH/mod/board/version.php" 2>/dev/null; then
    echo "  PASS: git-managed (file .git, submodule) plugin directory was not overwritten"
    ((PASS++))
else
    echo "  FAIL: git-managed (file .git, submodule) plugin directory WAS overwritten by the installer"
    ((FAIL++))
fi
echo ""

echo "--- Cleaning up git-guard fixtures ---"
rm -rf "$MOODLE_PATH/mod/board" "$GITLISTDIR" 2>/dev/null
echo ""

bash "$SCRIPT_DIR/clear.sh"

print_summary
