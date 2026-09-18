#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-apply
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_list_apply.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-apply integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# Clean up any leftover test plugins from previous runs
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null

# ── Helper: reset compiled cache definitions ──────────────────────
# Moodle caches its compiled cache definitions in dataroot. After
# repeated install/uninstall cycles of the same plugin (this suite
# installs and removes mod_attendance and theme_boost_union many
# times), a stale definition can survive that references an
# overrideclass from a since-removed plugin. cache_helper::purge_all(),
# triggered by upgrade_noncore() inside plugin:list-apply, then fatals
# with "The override class does not exist." Wiping the compiled
# definitions forces Moodle to rebuild them from the current
# filesystem on the next invocation.
reset_cache_definitions() {
    if [ -z "${DATAROOT:-}" ] || [ ! -d "$DATAROOT" ]; then
        echo "  WARNING: DATAROOT not set; skipping cache-definition reset"
        return 0
    fi
    sudo rm -f  "$DATAROOT/cache/core_component.php" 2>/dev/null
    sudo rm -rf "$DATAROOT/muc" 2>/dev/null
}

echo "--- Test: Help ---"
run_moosh plugin:list-apply --help
assert_output_contains "Help description" "declarative plugin list" "$OUT"
assert_output_contains "Help shows --directory" "--directory" "$OUT"
assert_output_contains "Help shows --keep-going" "--keep-going" "$OUT"
assert_output_contains "Help shows --run" "--run" "$OUT"
assert_output_contains "Help shows --token" "--token" "$OUT"
assert_output_contains "Help shows --scanner" "--scanner" "$OUT"
assert_output_contains "Help shows --archive-fallback" "--archive-fallback" "$OUT"
assert_output_contains "Help shows --archive-annotation-level" "--archive-annotation-level" "$OUT"
assert_output_contains "Help shows --suppress-lifecycle-warnings" "--suppress-lifecycle-warnings" "$OUT"
echo ""

LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/mod_attendance"

echo "--- Setup: resolve a real version via plugin:list-update ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
if [ ! -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  FAIL: could not stage a real version for mod_attendance - aborting remaining tests"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
    print_summary
fi
REAL_VERSION=$(cat "$LISTDIR/mod_attendance/version")
echo "Resolved version: $REAL_VERSION"
echo ""

# ═══════════════════════════════════════════════════════════════════
# Install (requested > 1)
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: Dry run previews install without applying ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR"
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Shows dry run banner" "Dry run" "$OUT"
assert_output_contains "Shows would-install" "WOULD INSTALL" "$OUT"
assert_output_contains "Shows component" "mod_attendance" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: nothing installed during dry run"
    ((PASS++))
else
    echo "  FAIL: plugin directory exists after a dry run"
    ((FAIL++))
fi
echo ""

echo "--- Test: --token doesn't affect a normal (non-Marketplace) dry run ---"
# The token is only ever sent as a Bearer header to marketplace.moodle.com
# (see PluginApiClient::isMarketplaceHost()) - download.moodle.org, which
# is all this test actually talks to, should behave identically whether or
# not one is supplied. This guards against the option breaking normal
# usage, e.g. via a parsing mistake or the host check being backwards.
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --token=dummy-test-token
EC=$?
assert_exit_code "Exit code 0 for dry run with --token" 0 "$EC"
assert_output_contains "Still shows would-install with --token" "WOULD INSTALL" "$OUT"
echo ""

echo "--- Test: MOODLE_MARKETPLACE_TOKEN env var behaves the same way ---"
export MOODLE_MARKETPLACE_TOKEN="dummy-env-token"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR"
EC=$?
unset MOODLE_MARKETPLACE_TOKEN
assert_exit_code "Exit code 0 for dry run with MOODLE_MARKETPLACE_TOKEN" 0 "$EC"
assert_output_contains "Still shows would-install with env token" "WOULD INSTALL" "$OUT"
echo ""

echo "--- Test: --run actually installs ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Exit code 0 for --run" 0 "$EC"
assert_output_contains "Shows installed" "INSTALLED mod_attendance" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/version.php" ]; then
    echo "  PASS: plugin installed with version.php present"
    ((PASS++))
else
    echo "  FAIL: mod/attendance/version.php not found after install"
    ((FAIL++))
fi
echo ""

echo "--- Test: Re-running --run is a no-op (already at requested version) ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows already-at" "already at $REAL_VERSION" "$OUT"
assert_output_not_contains "Does not reinstall" "INSTALLED mod_attendance" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
# Uninstall (requested == 0 or "uninstall")
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: Sentinel 0 dry-run previews uninstall ---"
echo 0 > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR"
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows would-uninstall" "WOULD UNINSTALL" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin still present during dry run"
    ((PASS++))
else
    echo "  FAIL: plugin was removed during a dry run"
    ((FAIL++))
fi
echo ""

echo "--- Test: Sentinel 0 --run actually uninstalls ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows uninstalled" "REMOVED mod_attendance: uninstalled" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin directory removed"
    ((PASS++))
else
    echo "  FAIL: plugin directory still exists after uninstall"
    ((FAIL++))
fi
echo ""

echo "--- Test: String sentinel 'uninstall' dry-run previews uninstall ---"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
# Reinstall for test
echo "uninstall" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR"
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows would-uninstall with string sentinel" "WOULD UNINSTALL" "$OUT"
assert_output_contains "Shows uninstall display name" "requested: uninstall" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin still present during dry run with string sentinel"
    ((PASS++))
else
    echo "  FAIL: plugin was removed during a dry run with string sentinel"
    ((FAIL++))
fi
echo ""

echo "--- Test: String sentinel 'uninstall' --run actually uninstalls ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows uninstalled with string sentinel" "REMOVED mod_attendance: uninstalled" "$OUT"
assert_output_contains "Shows uninstall display name in output" "requested: uninstall" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin directory removed with string sentinel"
    ((PASS++))
else
    echo "  FAIL: plugin directory still exists after uninstall with string sentinel"
    ((FAIL++))
fi
echo ""

echo "--- Test: String sentinel 'UNINSTALL' (uppercase) works ---"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
# Reinstall for test
echo "UNINSTALL" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows uninstalled with uppercase sentinel" "REMOVED mod_attendance: uninstalled" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: uppercase sentinel works"
    ((PASS++))
else
    echo "  FAIL: uppercase sentinel did not work"
    ((FAIL++))
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
# Unknown plugin type (e.g. atto_* after the Atto editor was removed
# from core) requested for uninstall
# ═══════════════════════════════════════════════════════════════════
#
# core_component::get_plugin_types() only returns plugin types this
# Moodle currently ships. A stale declarative-list entry for a type
# Moodle no longer knows about (or never did) has no install path to
# resolve. If its version file says "uninstall", there's nothing
# installed to check and nothing to remove - it must be skipped rather
# than treated as a hard failure.

UNKNOWNDIR=$(mktemp -d)
mkdir -p "$UNKNOWNDIR/zzznosuchtype_thing"

echo "--- Test: Sentinel 0 for an unknown plugin type is skipped, not an error ---"
echo 0 > "$UNKNOWNDIR/zzznosuchtype_thing/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$UNKNOWNDIR"
EC=$?
assert_exit_code "Exit code 0 - unknown type + uninstall is not a failure" 0 "$EC"
assert_output_contains "Shows SKIP for the unknown component" "SKIP    zzznosuchtype_thing" "$OUT"
assert_output_contains "Explains why it was skipped" "requested is uninstall" "$OUT"
assert_output_not_contains "Does not report it as an ERROR" "ERROR   zzznosuchtype_thing" "$OUT"
echo ""

echo "--- Test: String sentinel 'uninstall' for an unknown plugin type is skipped too ---"
echo "uninstall" > "$UNKNOWNDIR/zzznosuchtype_thing/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$UNKNOWNDIR"
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Shows SKIP for the unknown component with string sentinel" "SKIP    zzznosuchtype_thing" "$OUT"
echo ""

echo "--- Test: --run also skips cleanly instead of crashing on the missing path ---"
echo 0 > "$UNKNOWNDIR/zzznosuchtype_thing/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$UNKNOWNDIR" --run
EC=$?
assert_exit_code "Exit code 0 with --run" 0 "$EC"
assert_output_contains "Shows SKIP for the unknown component with --run" "SKIP    zzznosuchtype_thing" "$OUT"
echo ""

echo "--- Test: An unknown plugin type with a real requested version still errors ---"
echo 1 > "$UNKNOWNDIR/zzznosuchtype_thing/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$UNKNOWNDIR"
EC=$?
assert_exit_code "Nonzero exit - unresolvable component with a real install request" 1 "$EC"
assert_output_contains "Still reports unknown component as an error" "ERROR   zzznosuchtype_thing: unknown component" "$OUT"
echo ""

rm -rf "$UNKNOWNDIR"

# ═══════════════════════════════════════════════════════════════════
# Remove files only (requested == -1 or "remove-files"), database left untouched
# ═══════════════════════════════════════════════════════════════════

echo "--- Setup: reinstall mod_attendance for the remove-files test ---"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  FAIL: could not reinstall mod_attendance for the remove-files test - skipping it"
    echo "  --- plugin:list-apply output ---"
    echo "$OUT"
    ((FAIL++))
else
    echo "--- Test: Sentinel -1 --run removes files only ---"
    echo -1 > "$LISTDIR/mod_attendance/version"
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
    EC=$?
    assert_exit_code "Exit code 0" 0 "$EC"
    assert_output_contains "Shows files removed" "REMOVED mod_attendance: files removed" "$OUT"
    assert_output_contains "Notes database untouched" "database left untouched" "$OUT"
    if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
        echo "  PASS: plugin files removed"
        ((PASS++))
    else
        echo "  FAIL: plugin directory still exists"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: String sentinel 'remove-files' --run removes files only ---"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  FAIL: could not reinstall mod_attendance for the remove-files string test - skipping it"
    ((FAIL++))
else
    echo "remove-files" > "$LISTDIR/mod_attendance/version"
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
    EC=$?
    assert_exit_code "Exit code 0" 0 "$EC"
    assert_output_contains "Shows files removed with string sentinel" "REMOVED mod_attendance: files removed" "$OUT"
    assert_output_contains "Shows remove-files display name" "requested: remove-files" "$OUT"
    assert_output_contains "Notes database untouched" "database left untouched" "$OUT"
    if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
        echo "  PASS: plugin files removed with string sentinel"
        ((PASS++))
    else
        echo "  FAIL: plugin directory still exists with string sentinel"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: String sentinel 'REMOVE-FILES' (uppercase) works ---"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  FAIL: could not reinstall mod_attendance for the uppercase remove-files test - skipping it"
    ((FAIL++))
else
    echo "REMOVE-FILES" > "$LISTDIR/mod_attendance/version"
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
    EC=$?
    assert_exit_code "Exit code 0" 0 "$EC"
    assert_output_contains "Shows files removed with uppercase sentinel" "REMOVED mod_attendance: files removed" "$OUT"
    if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
        echo "  PASS: uppercase sentinel works"
        ((PASS++))
    else
        echo "  FAIL: uppercase sentinel did not work"
        ((FAIL++))
    fi
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
# --keep-going / abort-on-first-error
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: Without --keep-going, a failing component aborts before later ones ---"
rm -rf "$LISTDIR"
mkdir -p "$LISTDIR/aaa_bad_component" "$LISTDIR/mod_attendance"
# A version number that will never resolve to a real download - forces a
# real failure inside the install flow rather than a directory/name error.
echo 1 > "$LISTDIR/aaa_bad_component/version"
echo "$REAL_VERSION" > "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run
EC=$?
assert_exit_code "Nonzero exit on failure" 1 "$EC"
assert_output_contains "Reports the failing component" "aaa_bad_component" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: mod_attendance was not applied after the earlier failure (abort-on-first-error)"
    ((PASS++))
else
    echo "  FAIL: mod_attendance was applied despite abort-on-first-error semantics"
    ((FAIL++))
fi
echo ""

echo "--- Test: --keep-going processes every component despite a failure ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$LISTDIR" --run --keep-going
EC=$?
assert_exit_code "Nonzero exit - one component still failed" 1 "$EC"
assert_output_contains "Shows installed despite earlier failure" "INSTALLED mod_attendance" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/version.php" ]; then
    echo "  PASS: mod_attendance was applied even though aaa_bad_component failed"
    ((PASS++))
else
    echo "  FAIL: mod_attendance was not applied under --keep-going"
    ((FAIL++))
fi
echo ""

echo "--- Test: Nonexistent --directory ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory=/tmp/does_not_exist_$$_listapply
EC=$?
assert_exit_code "Exit code nonzero" 1 "$EC"
assert_output_contains "Directory not found error" "Directory not found" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
# Malware scanner selection (--scanner)
# ═══════════════════════════════════════════════════════════════════
#
# plugin:list-apply runs a malware scanner after every successful install.
# The scanner is selected via --scanner, defaulting to "clamscan" so the
# pre-existing behaviour is unchanged. This section covers the four
# supported values, the invalid-value error path, and the graceful-skip
# behaviour when a scanner's signatures are unavailable.

SCANDIR=$(mktemp -d)
mkdir -p "$SCANDIR/mod_attendance"
echo "$REAL_VERSION" > "$SCANDIR/mod_attendance/version"

PHP_SIGDIR="${HOME}/.moosh2/phpmussel-signatures"

# Force a real install so the post-install scan path actually fires. If
# the plugin is already at the requested version, applyComponent()
# returns early and never reaches runScanners().
reset_mod_attendance() {
    sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
}

echo "--- Test: --scanner=none skips scanning entirely ---"
reset_mod_attendance
rm -rf "$SCANDIR/.clamav" "$SCANDIR/.phpmussel"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --run --scanner=none
EC=$?
assert_exit_code "Exit code 0 with --scanner=none" 0 "$EC"
assert_output_contains "Plugin still installed" "INSTALLED mod_attendance" "$OUT"
assert_output_not_contains "No clamscan invocation" "Starting malware scan" "$OUT"
assert_output_not_contains "No phpMussel invocation" "Starting phpMussel scan" "$OUT"
if [ ! -d "$SCANDIR/.clamav/report" ] && [ ! -d "$SCANDIR/.phpmussel/report" ]; then
    echo "  PASS: no scanner report directories were created"
    ((PASS++))
else
    echo "  FAIL: scanner report directories exist despite --scanner=none"
    ((FAIL++))
fi
echo ""

echo "--- Test: --scanner=bogus is rejected before anything is applied ---"
reset_mod_attendance
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --run --scanner=bogus
EC=$?
assert_exit_code "Nonzero exit for invalid scanner value" 1 "$EC"
assert_output_contains "Names the invalid value" "Unknown --scanner value" "$OUT"
assert_output_contains "Lists valid values" "clamscan" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: nothing was installed when scanner validation failed"
    ((PASS++))
else
    echo "  FAIL: plugin was installed despite invalid --scanner value"
    ((FAIL++))
fi
echo ""

echo "--- Test: --scanner=bogus is also rejected during a dry run ---"
# Scanner validation happens before the dry-run check, so even a preview
# invocation must reject a bad value rather than silently defaulting.
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --scanner=bogus
EC=$?
assert_exit_code "Nonzero exit for invalid scanner value on dry run" 1 "$EC"
assert_output_contains "Names the invalid value on dry run" "Unknown --scanner value" "$OUT"
echo ""

echo "--- Test: --directory validation runs before --scanner validation ---"
# With both a bad --directory and a bad --scanner, the directory error must
# be reported first: there's no point validating a scanner against a
# directory that can't be scanned at all, and pointing the user at a
# scanner typo when the real problem is the path would be misleading.
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory=/tmp/does_not_exist_$$_order --scanner=bogus
EC=$?
assert_exit_code "Nonzero exit for bad directory + bad scanner" 1 "$EC"
assert_output_contains "Reports the directory error" "Directory not found" "$OUT"
assert_output_not_contains "Does not report the scanner error" "Unknown --scanner value" "$OUT"
echo ""

echo "--- Test: --scanner=phpmussel without signatures warns and installs anyway ---"
# Move the signature directory aside so scanWithPhpMussel() takes its
# "no signatures available" branch. A missing scanner is a WARN, not a
# failure, matching the existing ClamAV behaviour when clamscan itself
# is absent.
PHP_SIGBACKUP=""
if [ -d "$PHP_SIGDIR" ]; then
    PHP_SIGBACKUP=$(mktemp -d)
    mv "$PHP_SIGDIR" "$PHP_SIGBACKUP/phpmussel-signatures"
fi
reset_mod_attendance
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --run --scanner=phpmussel
EC=$?
assert_exit_code "Exit code 0 - missing signatures is a WARN, not a failure" 0 "$EC"
assert_output_contains "Warns about missing phpMussel signatures" "no phpMussel signatures" "$OUT"
assert_output_contains "Plugin still installed" "INSTALLED mod_attendance" "$OUT"
if [ -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: plugin was installed despite the skipped phpMussel scan"
    ((PASS++))
else
    echo "  FAIL: plugin was not installed when phpMussel scan was skipped"
    ((FAIL++))
fi
if [ -n "$PHP_SIGBACKUP" ] && [ -d "$PHP_SIGBACKUP/phpmussel-signatures" ]; then
    mv "$PHP_SIGBACKUP/phpmussel-signatures" "$PHP_SIGDIR"
fi
rm -rf "$PHP_SIGBACKUP"
echo ""

echo "--- Test: --scanner=phpmussel with signatures present runs cleanly ---"
# Only runs when plugin:phpmuslescan:update-signatures has already been
# executed (e.g. by test_plugin_phpmuslescan.sh, which this suite assumes
# may run alongside this one).
if [ -d "$PHP_SIGDIR" ] && [ -f "$PHP_SIGDIR/phpmussel.ini" ]; then
    reset_mod_attendance
    rm -rf "$SCANDIR/.phpmussel"
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --run --scanner=phpmussel
    EC=$?
    assert_exit_code "Exit code 0 for a clean plugin under phpMussel" 0 "$EC"
    assert_output_contains "Invokes phpMussel" "Starting phpMussel scan" "$OUT"
    assert_output_contains "Plugin installed" "INSTALLED mod_attendance" "$OUT"
    if [ -s "$SCANDIR/.phpmussel/report/phpmussel.log" ]; then
        echo "  PASS: phpMussel report log was written"
        ((PASS++))
    else
        echo "  FAIL: expected a non-empty report at $SCANDIR/.phpmussel/report/phpmussel.log"
        ((FAIL++))
    fi
else
    echo "  SKIP: no phpMussel signatures present (run test_plugin_phpmuslescan.sh first)"
fi
echo ""

echo "--- Test: --scanner=both runs clamscan and phpMussel ---"
if [ -d "$PHP_SIGDIR" ] && [ -f "$PHP_SIGDIR/phpmussel.ini" ] && command -v clamscan >/dev/null 2>&1; then
    reset_mod_attendance
    rm -rf "$SCANDIR/.clamav" "$SCANDIR/.phpmussel"
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$SCANDIR" --run --scanner=both
    EC=$?
    assert_exit_code "Exit code 0 for --scanner=both on a clean plugin" 0 "$EC"
    assert_output_contains "Invokes phpMussel" "Starting phpMussel scan" "$OUT"
    assert_output_contains "Plugin installed" "INSTALLED mod_attendance" "$OUT"
    if [ -s "$SCANDIR/.phpmussel/report/phpmussel.log" ]; then
        echo "  PASS: phpMussel report log was written under --scanner=both"
        ((PASS++))
    else
        echo "  FAIL: phpMussel report log missing under --scanner=both"
        ((FAIL++))
    fi
else
    echo "  SKIP: requires both phpMussel signatures and clamscan in PATH"
fi
echo ""

# Restore a clean state so the following sections see mod_attendance at
# the version they expect.
reset_mod_attendance
rm -rf "$SCANDIR"
echo ""

# ═══════════════════════════════════════════════════════════════════
# version.php $plugin->dependencies (theme_boost_union -> theme_boost)
# ═══════════════════════════════════════════════════════════════════
#
# theme_boost_union is a real Moodle theme whose own version.php declares
# $plugin->dependencies = ['theme_boost' => <min version>]. theme_boost
# itself ships with every Moodle install (it's part of core), so this
# exercises the "already installed/bundled - nothing to do" branch of
# resolveSingleDependency() without needing any third-party plugin at all.

DEPDIR=$(mktemp -d)
mkdir -p "$DEPDIR/theme_boost_union"

echo "--- Setup: resolve a real version of theme_boost_union ---"
run_moosh plugin:list-update --directory="$DEPDIR" --moodle-version=5.2 --run theme_boost_union
if [ ! -f "$DEPDIR/theme_boost_union/version" ]; then
    echo "  SKIP: theme_boost_union has no version compatible with Moodle 5.2 on moodle.org right now - skipping dependency-resolution tests"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
else
    echo "--- Test: installing theme_boost_union resolves its theme_boost dependency ---"
    sudo rm -rf "$MOODLE_PATH/theme/boost_union" 2>/dev/null
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$DEPDIR" --run
    EC=$?
    assert_exit_code "Exit code 0" 0 "$EC"
    assert_output_contains "Installs theme_boost_union itself" "INSTALLED theme_boost_union" "$OUT"
    # theme_boost ships with core, so it's satisfied via the "already
    # installed" branch - it must NOT be treated as a third-party plugin
    # that gets added to the plugin list.
    assert_output_not_contains "Does not add theme_boost as a third-party plugin (it's core-bundled)" "ADD     theme_boost:" "$OUT"
    if [ -d "$MOODLE_PATH/theme/boost_union" ]; then
        echo "  PASS: theme_boost_union installed"
        ((PASS++))
    else
        echo "  FAIL: theme/boost_union not found after install"
        ((FAIL++))
    fi
    if [ ! -d "$DEPDIR/theme_boost" ]; then
        echo "  PASS: no plugin-list entry was created for theme_boost (core-bundled, not third-party)"
        ((PASS++))
    else
        echo "  FAIL: a plugin-list entry for theme_boost was created despite it shipping with core"
        ((FAIL++))
    fi
    echo ""

    echo "--- Test: an unmet version.php dependency fails loudly rather than installing silently ---"
    # Force the check to fail by pre-declaring theme_boost in the plugin
    # list at a version far too low to satisfy theme_boost_union's
    # requirement, exercising resolveSingleDependency()'s "declared but
    # insufficient" branch, which must throw rather than silently
    # downgrading/ignoring the requirement.
    mkdir -p "$DEPDIR/theme_boost"
    echo "2000010100" > "$DEPDIR/theme_boost/version"
    sudo rm -rf "$MOODLE_PATH/theme/boost_union" 2>/dev/null
    run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$DEPDIR" --run
    EC=$?
    assert_exit_code "Nonzero exit - dependency can't be satisfied" 1 "$EC"
    assert_output_contains "Explains the unmet dependency" "theme_boost" "$OUT"
    if [ ! -d "$MOODLE_PATH/theme/boost_union" ]; then
        echo "  PASS: theme_boost_union was not installed with an unmet dependency"
        ((PASS++))
    else
        echo "  FAIL: theme_boost_union was installed despite theme_boost being pinned too low"
        ((FAIL++))
    fi
    echo ""

    sudo rm -rf "$MOODLE_PATH/theme/boost_union" 2>/dev/null
fi
rm -rf "$DEPDIR"
echo ""

# ═══════════════════════════════════════════════════════════════════
# --archive-fallback / checksum verification (issue #82 §3.2 / §3.4)
# ═══════════════════════════════════════════════════════════════════
#
# Uses a hand-built <component>/original/*.zip - a minimal, valid zip
# whose version.php declares mod_attendance - rather than a real
# plugin:list-update --archive run, so these tests don't depend on
# moodle.org ever actually withdrawing a real version. The "not
# resolvable on moodle.org" side is forced with a version number that
# will never exist in plugins.json, which makes findBestVersion() throw
# its "Could not find '$component' version $label." message - the
# specific-version half of the two strings --archive-fallback catches on.

ARCHDIR=$(mktemp -d)
mkdir -p "$ARCHDIR/mod_attendance/original"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
reset_cache_definitions
FAKE_VERSION="9999999999"
echo "$FAKE_VERSION" > "$ARCHDIR/mod_attendance/version"

build_archive_zip() {
    # $1 = component  $2 = version  $3 = target zip path
    local component="$1"
    local version="$2"
    local target="$3"
    local stage
    stage=$(mktemp -d)
    mkdir -p "$(dirname "$target")"
    mkdir -p "$stage/$component/lang/en"

    # 1. Generate the mandatory English language pack
    local pluginname
    pluginname=$(echo "$component" | cut -d'_' -f2 | ucfirst)
    cat > "$stage/$component/lang/en/${component#*_}.php" <<PHP
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for component '${component}'.
 *
 * @package    ${component}
 * @copyright  2026 Your Test Suite
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
\$string['pluginname'] = '${pluginname}';
PHP

    # 2. Generate version.php
    cat > "$stage/$component/version.php" <<PHP
<?php
\$plugin->component = '${component}';
\$plugin->version   = ${version};
\$plugin->requires  = 2024100700;
\$plugin->release   = '${version}';
\$plugin->maturity  = MATURITY_STABLE;
PHP

    # 3. Add both version.php and the lang pack to the archive
    php -r '
        $stage = $argv[1];
        $component = $argv[2];
        $target = $argv[3];
        $plugin_short = substr($component, strpos($component, "_") + 1);
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "could not open zip $target\n");
            exit(1);
        }
        $zip->addFile($stage . "/$component/version.php", "$component/version.php");
        $zip->addFile($stage . "/$component/lang/en/{$plugin_short}.php", "$component/lang/en/{$plugin_short}.php");
        $zip->close();
    ' "$stage" "$component" "$target"
    rm -rf "$stage"
}

build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"

echo "--- Test: without --archive-fallback, an unresolvable version fails as before (regression guard) ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run
EC=$?
assert_exit_code "Nonzero exit" 1 "$EC"
assert_output_contains "Error mentions the component" "mod_attendance" "$OUT"
assert_output_contains "Error is the original moodle.org not-found message" "Could not find" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: nothing installed without --archive-fallback"
    ((PASS++))
else
    echo "  FAIL: plugin was installed despite --archive-fallback not being passed"
    ((FAIL++))
fi
echo ""

echo "--- Test: --archive-fallback installs from the archived zip when moodle.org can't resolve the version ---"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Reports the archive fallback" "falling back to archived zip" "$OUT"
assert_output_contains "Reports ARCHIVED, not INSTALLED" "ARCHIVED mod_attendance" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/version.php" ]; then
    echo "  PASS: mod_attendance installed from the archive"
    ((PASS++))
else
    echo "  FAIL: mod/attendance/version.php not found after archive-fallback install"
    ((FAIL++))
fi
echo ""

echo "--- Test: --archive-fallback still runs the malware scanner after an archive-sourced install ---"
assert_output_contains "Malware scanner ran (not the CI-side stopgap's skipped scan)" "Starting malware scan" "$OUT"
echo ""

echo "--- Test: missing checksum warns but still installs (§3.4) ---"
assert_output_contains "Missing-checksum warning" "no checksum pinned" "$OUT"
echo ""

echo "--- Test: missing-checksum warning is annotated as ::warning:: under CI ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
export CI=true
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
unset CI
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "GitHub Actions warning annotation for missing checksum" "::warning" "$OUT"
assert_output_contains "Annotation is about the missing checksum" "Missing plugin checksum" "$OUT"
echo ""

echo "--- Test: end-of-run summary lists the archived component ---"
assert_output_contains "Archived-component summary line" "Archived component(s) in use" "$OUT"
assert_output_contains "Summary names mod_attendance and its version" "mod_attendance ($FAKE_VERSION)" "$OUT"
echo ""

echo "--- Test: a correct checksum lets an archive-sourced install proceed without the missing-checksum warning ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
md5sum "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip" | awk '{print $1}' > "$ARCHDIR/mod_attendance/checksum"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_not_contains "No missing-checksum warning when checksum matches" "no checksum pinned" "$OUT"
assert_output_contains "Still reports ARCHIVED" "ARCHIVED mod_attendance" "$OUT"
echo ""

echo "--- Test: a mismatched checksum is a hard failure, nothing installed ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
echo "0000000000000000000000000000ff" > "$ARCHDIR/mod_attendance/checksum"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
assert_exit_code "Nonzero exit for checksum mismatch" 1 "$EC"
assert_output_contains "Error mentions checksum mismatch" "checksum mismatch" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: nothing installed on checksum mismatch"
    ((PASS++))
else
    echo "  FAIL: plugin was installed despite a checksum mismatch"
    ((FAIL++))
fi
rm -f "$ARCHDIR/mod_attendance/checksum"
echo ""

echo "--- Test: --archive-fallback with no archive present falls through to the original error (not a silent no-op) ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
rm -rf "$ARCHDIR/mod_attendance/original"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
assert_exit_code "Nonzero exit - no archive to fall back to" 1 "$EC"
assert_output_contains "Same original moodle.org error, not a silent success" "Could not find" "$OUT"
if [ ! -d "$MOODLE_PATH/mod/attendance" ]; then
    echo "  PASS: nothing installed when no archive exists either"
    ((PASS++))
else
    echo "  FAIL: plugin was installed despite no archive being present"
    ((FAIL++))
fi
echo ""

echo "--- Test: --suppress-lifecycle-warnings silences both the missing-checksum warning and the archive summary ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback --suppress-lifecycle-warnings
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Still installs from the archive" "ARCHIVED mod_attendance" "$OUT"
assert_output_not_contains "No missing-checksum warning" "no checksum pinned" "$OUT"
assert_output_not_contains "No archive-summary line" "Archived component(s) in use" "$OUT"
echo ""

echo "--- Test: omitting --suppress-lifecycle-warnings restores both again ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Missing-checksum warning is back" "no checksum pinned" "$OUT"
assert_output_contains "Archive summary is back" "Archived component(s) in use" "$OUT"
echo ""

echo "--- Test: --archive-annotation-level=notice changes the archive-summary annotation, not the checksum one ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"
export CI=true
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback --archive-annotation-level=notice
EC=$?
unset CI
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Archive summary now uses ::notice::" "::notice title=Archived plugin components in use::" "$OUT"
assert_output_contains "Checksum warning still uses ::warning::" "::warning title=Missing plugin checksum::" "$OUT"
echo ""

echo "--- Test: end-of-run summary shows both Failed and Archived lines when both apply ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"
mkdir -p "$ARCHDIR/zzz_bad_component"
echo 1 > "$ARCHDIR/zzz_bad_component/version"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback --keep-going
EC=$?
assert_exit_code "Nonzero exit - zzz_bad_component still failed" 1 "$EC"
assert_output_contains "Shows the failed component" "Failed component(s):" "$OUT"
assert_output_contains "Also shows the archived component" "Archived component(s) in use" "$OUT"
rm -rf "$ARCHDIR/zzz_bad_component"
echo ""

echo "--- Test: dependency-attribution regression (§2.4) - archive-fallback fires for the actually-failing requires-file dependency, not just the outer component ---"
# local_archtest is a fictitious local_* component - not on moodle.org
# either, so it needs its own archive just to install at all. What this
# test actually checks is that mod_attendance, listed in local_archtest's
# requires file, is independently resolved (and correctly attributed in
# the ARCHIVED/summary output) from ITS OWN archive - not skipped, and
# not misattributed to local_archtest's own fallback.
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
build_archive_zip mod_attendance "$FAKE_VERSION" "$ARCHDIR/mod_attendance/original/mod_attendance-$FAKE_VERSION.zip"
mkdir -p "$ARCHDIR/local_archtest/original"
echo "999999" > "$ARCHDIR/local_archtest/version"
echo "mod_attendance" > "$ARCHDIR/local_archtest/requires"
build_archive_zip local_archtest 999999 "$ARCHDIR/local_archtest/original/local_archtest-999999.zip"
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$ARCHDIR" --run --archive-fallback local_archtest
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Archived-component summary correctly names mod_attendance (the dependency)" "mod_attendance ($FAKE_VERSION)" "$OUT"
assert_output_contains "Archived-component summary also names local_archtest itself" "local_archtest (999999)" "$OUT"
if [ -f "$MOODLE_PATH/mod/attendance/version.php" ]; then
    echo "  PASS: the requires-file dependency (mod_attendance) was installed from its own archive"
    ((PASS++))
else
    echo "  FAIL: mod/attendance/version.php not found - dependency wasn't installed from the archive"
    ((FAIL++))
fi
rm -rf "$ARCHDIR/local_archtest"
echo ""

sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
rm -rf "$ARCHDIR"
echo ""

# ═══════════════════════════════════════════════════════════════════
# Regression: package_* value-checks must survive RUNNER_DEBUG=1
# ═══════════════════════════════════════════════════════════════════
#
# getInstalledVersion()/getRequestedVersion()/getComponentPath() shell out
# to bin/*.sh for package_* components and capture stdout+stderr together
# (runScript() uses `2>&1`). Real-world package_* plugins (e.g. Kaltura's)
# have bin/get_installed_version.sh source a shared library that enables
# `set -x` whenever GitHub Actions debug logging is on (RUNNER_DEBUG=1).
# Before the fix, that trace was captured as part of "the installed
# version" instead of being discarded, so the comparison against the
# requested version never matched and every install under debug logging
# was reported as "could not be upgraded" - regardless of whether the
# file was actually there. This uses a minimal self-contained fixture
# (no real plugin, no network) that reproduces just the one thing that
# matters: a package_* script sourcing a library that does `set -x`
# under RUNNER_DEBUG=1.

PKGDIR=$(mktemp -d)
mkdir -p "$PKGDIR/package_mooshtest/bin"

# Stand-in for the shared library real package_* plugins source (e.g.
# moodle_plugins_lib.rc). Only what matters for this regression: `set -x`
# under RUNNER_DEBUG=1, plus a trivial installed-version lookup.
cat > "$PKGDIR/mini_lib.rc" <<'RC'
#!/bin/bash
if [ "${RUNNER_DEBUG}" = "1" ]; then
    set -x
fi
get_installed_version_mini() {
    if [ -f "local/mooshtest/version.php" ]; then
        grep -oP '\$plugin->version\s*=\s*\K[0-9]+' local/mooshtest/version.php
    else
        echo -1
    fi
}
RC

echo "2024010100" > "$PKGDIR/package_mooshtest/version"

cat > "$PKGDIR/package_mooshtest/bin/get_requested_version.sh" <<'SH'
#!/bin/bash
__componentdir="$( cd -- "$(dirname "$0")/.." >/dev/null 2>&1; pwd -P )"
cat "${__componentdir}/version"
SH

cat > "$PKGDIR/package_mooshtest/bin/get_component_path.sh" <<'SH'
#!/bin/bash
echo "local/mooshtest"
SH

cat > "$PKGDIR/package_mooshtest/bin/get_component_ignore_path.sh" <<'SH'
#!/bin/bash
# no extra ignore paths needed for this fixture
true
SH

cat > "$PKGDIR/package_mooshtest/bin/install_requested_version.sh" <<'SH'
#!/bin/bash
set -e
mkdir -p local/mooshtest
cat > local/mooshtest/version.php <<PHP
<?php
\$plugin->version = ${2};
\$plugin->component = 'local_mooshtest';
PHP
SH

# The one script that matters: sources the shared (mini) library exactly
# the way real package_* plugins source moodle_plugins_lib.rc, so it is
# exposed to the same set -x pollution if runScript()'s value extraction
# regresses.
cat > "$PKGDIR/package_mooshtest/bin/get_installed_version.sh" <<'SH'
#!/bin/bash
__config_plugin_directory="$( cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 ; pwd -P )"
. "${__config_plugin_directory}"/mini_lib.rc
get_installed_version_mini
SH

chmod +x "$PKGDIR"/package_mooshtest/bin/*.sh

rm -rf "$MOODLE_PATH/local/mooshtest" 2>/dev/null

echo "--- Test: package_* install succeeds normally (RUNNER_DEBUG unset) ---"
unset RUNNER_DEBUG
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$PKGDIR" --run
EC=$?
assert_exit_code "Exit code 0 without debug logging" 0 "$EC"
assert_output_contains "Reports installed" "INSTALLED package_mooshtest" "$OUT"
assert_output_not_contains "Does not report a bogus upgrade failure" "could not be upgraded" "$OUT"
echo ""

echo "--- Test: re-checking an already-installed package_* under RUNNER_DEBUG=1 ---"
export RUNNER_DEBUG=1
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$PKGDIR" --run
EC=$?
unset RUNNER_DEBUG
assert_exit_code "Exit code 0 with debug logging on" 0 "$EC"
assert_output_contains "Shows already-at, not a false upgrade failure" "already at" "$OUT"
assert_output_not_contains "Does not report a bogus upgrade failure under debug logging" "could not be upgraded" "$OUT"
echo ""

echo "--- Test: a FRESH install under RUNNER_DEBUG=1 (the reported regression) ---"
# This is the exact scenario from the original bug report: RUNNER_DEBUG=1
# for the whole run (as GitHub Actions sets it whenever debug logging is
# enabled), and package_* being installed for the first time - not just
# re-checked. Before the fix, getInstalledVersion() returned the entire
# `set -x` trace dump instead of "-1"/the real version, so this always
# threw "could not be upgraded" regardless of the actual filesystem state.
rm -rf "$MOODLE_PATH/local/mooshtest" 2>/dev/null
echo "2024010200" > "$PKGDIR/package_mooshtest/version"
export RUNNER_DEBUG=1
run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$PKGDIR" --run
EC=$?
unset RUNNER_DEBUG
assert_exit_code "Exit code 0 for a fresh install under debug logging" 0 "$EC"
assert_output_contains "Reports installed despite debug logging being on" "INSTALLED package_mooshtest" "$OUT"
assert_output_not_contains "Does not falsely report the upgrade as failed" "could not be upgraded" "$OUT"
if [ -f "$MOODLE_PATH/local/mooshtest/version.php" ]; then
    echo "  PASS: version.php present after install under debug logging"
    ((PASS++))
else
    echo "  FAIL: version.php missing after install under debug logging"
    ((FAIL++))
fi
echo ""

echo "--- Cleaning up package_* regression fixture ---"
rm -rf "$MOODLE_PATH/local/mooshtest" 2>/dev/null
rm -rf "$PKGDIR"
echo ""

# ── Cleanup ──────────────────────────────────────────────────────

echo "--- Cleaning up ---"
sudo rm -rf "$MOODLE_PATH/mod/attendance" 2>/dev/null
rm -rf "$LISTDIR"
bash "$SCRIPT_DIR/clear.sh"
echo ""

print_summary