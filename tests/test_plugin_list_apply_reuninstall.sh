#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-apply --reuninstall: recovery of a
# component requested as uninstall (0) whose plugin files and/or database
# version row are already gone, ported from install_plugins.php's
# plugins_reuninstall_all(), get_uninstall_version() and
# get_last_managed_version().
#
# Requires a working Moodle 5.2 installation at $MOODLE_DIR (see common.sh)
# and network access to moodle.org (mod_attendance is really downloaded).
# git must be installed (the git-history fallback is tested with a scratch
# repository).
#
# Usage: bash tests/test_plugin_list_apply_reuninstall.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-apply --reuninstall integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

DATAROOT="${DATAROOT:-$MOOSH_TEST_LOCK_DATAROOT}"

# Same reason as in test_plugin_list_apply.sh: repeated install/uninstall
# cycles of one plugin can leave a stale compiled cache definition behind.
reset_cache_definitions() {
    if [ -z "${DATAROOT:-}" ] || [ ! -d "$DATAROOT" ]; then
        echo "  WARNING: DATAROOT not set; skipping cache-definition reset"
        return 0
    fi
    sudo rm -f  "$DATAROOT/cache/core_component.php" 2>/dev/null
    sudo rm -rf "$DATAROOT/muc" 2>/dev/null
}

# Prints the version row of a component from {config_plugins}, or nothing.
plugin_db_version() {
    run_moosh sql:run -p "$MOODLE_PATH" "SELECT value FROM {config_plugins} WHERE plugin='$1' AND name='version'" -o csv
    echo "$OUT" | sed -n '2p' | tr -d '" \r'
}

# Prints how many tables have "attendance" in their name (information_schema
# exists on MySQL/MariaDB and PostgreSQL, which is what the test sites use).
attendance_table_count() {
    run_moosh sql:run -p "$MOODLE_PATH" "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_name LIKE '%attendance%'" -o csv
    echo "$OUT" | sed -n '2p' | tr -d '" \r'
}

# Clean up any leftovers from a previous, interrupted run of this file.
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null

LISTDIR=$(mktemp -d)
GITROOT=$(mktemp -d)
trap 'rm -rf "$LISTDIR" "$GITROOT"; _moosh_test_release_lock' EXIT
mkdir -p "$LISTDIR/mod_attendance"

echo "--- Test: Help mentions --reuninstall ---"
run_moosh plugin:list-apply --help
assert_output_contains "Help shows --reuninstall" "--reuninstall" "$OUT"
assert_output_contains "Help explains version_uninstall" "version_uninstall" "$OUT"
echo ""

echo "--- Setup: resolve a real version via plugin:list-update and install it ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
assert_output_not_empty "list-update produced output" "$OUT"
VERSION=$(tr -d '\r\n ' < "$LISTDIR/mod_attendance/version")
echo "  pinned mod_attendance version: $VERSION"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
assert_output_contains "Installed" "INSTALLED mod_attendance" "$OUT"
assert_output_contains "Version row registered" "$VERSION" "$(plugin_db_version mod_attendance)"
TABLES_INSTALLED=$(attendance_table_count)
echo "  attendance tables while installed: ${TABLES_INSTALLED:-?}"
echo ""

# ── usage errors ─────────────────────────────────────────────────

echo "--- Test: --reuninstall cannot be combined with an orphan flag ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --prune-orphans
EC=$?
assert_exit_code "Non-zero exit for --reuninstall --prune-orphans" 1 "$EC"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --warn-orphans
EC=$?
assert_exit_code "Non-zero exit for --reuninstall --warn-orphans" 1 "$EC"
echo ""

echo "--- Test: a named component that is not requested as uninstall is an error ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run mod_attendance
EC=$?
assert_exit_code "Non-zero exit when the named component is not requested as uninstall" 1 "$EC"
assert_output_contains "Explains that only uninstall components apply" "only applies to components requested as uninstall" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ] && [ "$(plugin_db_version mod_attendance)" = "$VERSION" ]; then
    echo "  PASS: nothing was touched"
    ((PASS++))
else
    echo "  FAIL: a component not requested as uninstall was modified"
    ((FAIL++))
fi
echo ""

# ── dry run ──────────────────────────────────────────────────────

echo "0" > "$LISTDIR/mod_attendance/version"

echo "--- Test: dry run previews the plan and changes nothing ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall mod_attendance
EC=$?
assert_exit_code "Dry run succeeds" 0 "$EC"
assert_output_contains "Previews the reuninstall" "WOULD REUNINSTALL mod_attendance: restore version $VERSION" "$OUT"
assert_output_contains "Names the database as the source" "from the database" "$OUT"
assert_output_contains "Announces it will write version_uninstall" "write mod_attendance/version_uninstall" "$OUT"
if [ ! -e "$LISTDIR/mod_attendance/version_uninstall" ] \
    && [ -d "$MOODLE_PATH/mod/attendance" ] \
    && [ "$(plugin_db_version mod_attendance)" = "$VERSION" ]; then
    echo "  PASS: dry run wrote no version_uninstall and left files and database alone"
    ((PASS++))
else
    echo "  FAIL: dry run changed something (version_uninstall / files / database)"
    ((FAIL++))
fi
echo ""

# ── a git-managed directory is left alone ────────────────────────

echo "--- Test: a git-managed plugin directory is skipped ---"
sudo mkdir "$MOODLE_PATH/mod/attendance/.git"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run mod_attendance
assert_output_contains "Leaves it alone" "managed by git - leaving as is" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ] && [ "$(plugin_db_version mod_attendance)" = "$VERSION" ]; then
    echo "  PASS: git-managed plugin still installed"
    ((PASS++))
else
    echo "  FAIL: a git-managed plugin was uninstalled or deleted"
    ((FAIL++))
fi
sudo rmdir "$MOODLE_PATH/mod/attendance/.git"
echo ""

# ── database and version_uninstall disagree ──────────────────────

echo "--- Test: database and version_uninstall disagree - stops before changing anything ---"
echo "2020010100" > "$LISTDIR/mod_attendance/version_uninstall"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run mod_attendance
EC=$?
assert_exit_code "Non-zero exit on a version conflict" 1 "$EC"
assert_output_contains "Reports both versions" "installed with version $VERSION, but mod_attendance/version_uninstall says 2020010100" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ] && [ "$(plugin_db_version mod_attendance)" = "$VERSION" ] \
    && [ "$(tr -d '\r\n ' < "$LISTDIR/mod_attendance/version_uninstall")" = "2020010100" ]; then
    echo "  PASS: nothing changed, version_uninstall left as it was"
    ((PASS++))
else
    echo "  FAIL: the run changed something despite the conflict"
    ((FAIL++))
fi
rm -f "$LISTDIR/mod_attendance/version_uninstall"
echo ""

# ── files gone, version row still in the database ────────────────

echo "--- Test: files gone but still registered - restored from the database version, then uninstalled ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance"
reset_cache_definitions
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run mod_attendance
EC=$?
assert_exit_code "Reuninstall succeeds" 0 "$EC"
assert_output_contains "Restores the files" "Restoring mod_attendance $VERSION" "$OUT"
assert_output_contains "Reports the result" "REMOVED mod_attendance: reuninstalled" "$OUT"
assert_output_contains "Tells the user to commit version_uninstall" "Wrote version $VERSION to mod_attendance/version_uninstall, please commit it" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin files deleted again"
    ((PASS++))
else
    echo "  FAIL: plugin files still present after reuninstall"
    ((FAIL++))
fi
if [ -z "$(plugin_db_version mod_attendance)" ]; then
    echo "  PASS: version row removed from the database"
    ((PASS++))
else
    echo "  FAIL: version row still in the database"
    ((FAIL++))
fi
if [ "$(tr -d '\r\n ' < "$LISTDIR/mod_attendance/version_uninstall" 2>/dev/null)" = "$VERSION" ]; then
    echo "  PASS: version_uninstall written with the restored version"
    ((PASS++))
else
    echo "  FAIL: version_uninstall missing or wrong"
    ((FAIL++))
fi
echo ""

# ── files gone AND version row gone (the unregistered case) ──────

echo "--- Setup: install mod_attendance again, then lose both its files and its version row ---"
echo "$VERSION" > "$LISTDIR/mod_attendance/version"
reset_cache_definitions
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run mod_attendance
assert_output_contains "Installed again" "INSTALLED mod_attendance" "$OUT"
TABLES_BEFORE=$(attendance_table_count)
run_moosh sql:run -p "$MOODLE_PATH" "DELETE FROM {config_plugins} WHERE plugin='mod_attendance' AND name='version'" --run
sudo rm -rf "$MOODLE_PATH/mod/attendance"
reset_cache_definitions
echo "0" > "$LISTDIR/mod_attendance/version"
echo "  attendance tables left behind: ${TABLES_BEFORE:-?}"
echo ""

echo "--- Test: not registered anymore - restored from version_uninstall, registered, uninstalled properly ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall mod_attendance
assert_output_contains "Dry run names version_uninstall as the source" "from mod_attendance/version_uninstall" "$OUT"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run mod_attendance
EC=$?
assert_exit_code "Reuninstall succeeds" 0 "$EC"
assert_output_contains "Registers the missing version row" "Registering mod_attendance with version $VERSION" "$OUT"
assert_output_contains "Reports the result" "REMOVED mod_attendance: reuninstalled" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ] && [ -z "$(plugin_db_version mod_attendance)" ]; then
    echo "  PASS: no files and no version row left"
    ((PASS++))
else
    echo "  FAIL: files or version row still there"
    ((FAIL++))
fi
if [ -n "$TABLES_BEFORE" ] && [ "$TABLES_BEFORE" != "0" ]; then
    TABLES_AFTER=$(attendance_table_count)
    if [ "$TABLES_AFTER" = "0" ]; then
        echo "  PASS: the plugin's tables were dropped too (the point of this mode)"
        ((PASS++))
    else
        echo "  FAIL: $TABLES_AFTER attendance table(s) still there after reuninstall"
        ((FAIL++))
    fi
else
    echo "  SKIP: could not count attendance tables on this database, table check not run"
fi
echo ""

# ── no version known anywhere ────────────────────────────────────

echo "--- Test: nothing knows a version - fails before changing anything ---"
mkdir -p "$LISTDIR/local_neverinstalled"
echo "0" > "$LISTDIR/local_neverinstalled/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --reuninstall --run local_neverinstalled
EC=$?
assert_exit_code "Non-zero exit when no version can be found" 1 "$EC"
assert_output_contains "Says where it looked" "no version found in the database, in version_uninstall or in the git history" "$OUT"
if [ ! -e "$LISTDIR/local_neverinstalled/version_uninstall" ]; then
    echo "  PASS: no version_uninstall written for a component without a known version"
    ((PASS++))
else
    echo "  FAIL: version_uninstall written although no version was found"
    ((FAIL++))
fi
rm -rf "$LISTDIR/local_neverinstalled"
echo ""

# ── git history fallback ─────────────────────────────────────────
# The plugin list lives in a subdirectory of the repository on purpose: the
# original script sat at the repository root, this one must work relative to
# --directory.

echo "--- Test: version found in the git history of the plugin list ---"
GITLIST="$GITROOT/plugins"
mkdir -p "$GITLIST/local_gitonly"
git -C "$GITROOT" init -q
git -C "$GITROOT" config user.email "test@example.com"
git -C "$GITROOT" config user.name "moosh2 test"
echo "2024010100" > "$GITLIST/local_gitonly/version"
git -C "$GITROOT" add -A && git -C "$GITROOT" commit -q -m "v1"
echo "2024020100" > "$GITLIST/local_gitonly/version"
git -C "$GITROOT" add -A && git -C "$GITROOT" commit -q -m "v2"
echo "uninstall" > "$GITLIST/local_gitonly/version"
git -C "$GITROOT" add -A && git -C "$GITROOT" commit -q -m "uninstall"
echo "0" > "$GITLIST/local_gitonly/version"
git -C "$GITROOT" add -A && git -C "$GITROOT" commit -q -m "uninstall (0)"

run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$GITLIST" --reuninstall local_gitonly
EC=$?
assert_exit_code "Dry run succeeds" 0 "$EC"
assert_output_contains "Skips the sentinel commits and takes the newest positive version" "WOULD REUNINSTALL local_gitonly: restore version 2024020100 (from the git history)" "$OUT"
echo ""

echo "--- Test: a full-list run only looks at components requested as uninstall ---"
mkdir -p "$GITLIST/mod_attendance"
echo "$VERSION" > "$GITLIST/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$GITLIST" --reuninstall
EC=$?
assert_exit_code "Full-list dry run succeeds" 0 "$EC"
assert_output_contains "Plans the uninstall-requested component" "WOULD REUNINSTALL local_gitonly" "$OUT"
assert_output_not_contains "Ignores a component that is requested at a version" "mod_attendance" "$OUT"
assert_output_not_contains "Runs no orphan detection" "orphan" "$OUT"
echo ""

echo "--- Cleaning up ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
reset_cache_definitions
echo ""

bash "$SCRIPT_DIR/clear.sh"

print_summary
