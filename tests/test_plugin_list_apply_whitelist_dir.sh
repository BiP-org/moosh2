#!/usr/bin/env bash
#
# Integration test for plugin:list-apply's per-plugin malware-scan
# whitelist lookup directory.
#
# PluginListApply52Handler::scanWithClamav()/scanWithPhpMussel() must read
# the per-plugin clamscan-whitelist/phpmuslescan-whitelist file from the
# declarative plugin list's component directory ($componentdir - the same
# directory as that component's version/checksum/archive/), NOT from the
# installed Moodle plugin directory ($componentpath). $componentpath is
# replaced wholesale by every (re)install - a downloaded zip is extracted
# over it, and a package_* component's bin/install_requested_version.sh
# is contractually required to do the same (see the class docblock of
# PluginListApply52Handler, "Package patching") - so a whitelist file kept
# there would silently vanish on the very next reinstall. $componentdir is
# the user's git-managed declarative list and is never touched by an
# install, so that's where the whitelist must live to survive.
#
# Uses a self-contained fake package (no network, same technique as
# test_plugin_list_apply_package_patches.sh): package_mooshwl's
# bin/install_requested_version.sh writes local/mooshwl from scratch
# (replacing it) including a file containing a marker string, and a
# custom ClamAV .ndb rule (same "plant a rule, plant a matching marker"
# technique as test_plugin_clamscan.sh) flags that marker deterministically
# - no dependency on real-world virus signatures or real plugin content.
#
# Requires a working Moodle 5.2 installation at $MOODLE_DIR (see common.sh)
# and clamscan in PATH; skips (not fails) if clamscan is unavailable.
#
# Usage: bash tests/test_plugin_list_apply_whitelist_dir.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-apply per-plugin whitelist directory integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

DATAROOT="${DATAROOT:-$MOOSH_TEST_LOCK_DATAROOT}"

reset_cache_definitions() {
    if [ -z "${DATAROOT:-}" ] || [ ! -d "$DATAROOT" ]; then
        echo "  WARNING: DATAROOT not set; skipping cache-definition reset"
        return 0
    fi
    sudo rm -f  "$DATAROOT/cache/core_component.php" 2>/dev/null
    sudo rm -rf "$DATAROOT/muc" 2>/dev/null
}

if ! command -v clamscan >/dev/null 2>&1; then
    echo "  SKIP: clamscan not found in PATH - skipping this entire suite"
    print_summary
    exit 0
fi

PKGDIR=$(mktemp -d)
PK="$PKGDIR/package_mooshwl"
A="$MOODLE_PATH/local/mooshwl"
trap 'sudo rm -rf "$A" 2>/dev/null; rm -rf "$PKGDIR"; _moosh_test_release_lock' EXIT

sudo rm -rf "$A" 2>/dev/null
mkdir -p "$PK/bin"
echo "1" > "$PK/version"

cat > "$PK/bin/get_requested_version.sh" <<'SH'
#!/bin/bash
cat "$(dirname "$0")/../version"
SH
cat > "$PK/bin/get_component_path.sh" <<'SH'
#!/bin/bash
echo "local/mooshwl"
SH
cat > "$PK/bin/get_component_ignore_path.sh" <<'SH'
#!/bin/bash
echo "local/mooshwl"
SH
cat > "$PK/bin/get_installed_version.sh" <<'SH'
#!/bin/bash
if [ -f local/mooshwl/.pkgver ]; then cat local/mooshwl/.pkgver; else echo -1; fi
SH
cat > "$PK/bin/uninstall_requested_version.sh" <<'SH'
#!/bin/bash
rm -rf local/mooshwl
SH
# Replaces (never merges into) the plugin directory - the package_*
# contract - so anything placed inside local/mooshwl by hand (e.g. a
# whitelist file, to prove the point this test exists to make) is gone
# before the next scan ever runs.
cat > "$PK/bin/install_requested_version.sh" <<'SH'
#!/bin/bash
set -e
rm -rf local/mooshwl
mkdir -p local/mooshwl/lang/en
printf '<?php\ndefined("MOODLE_INTERNAL") || die();\n$plugin->component = "local_mooshwl";\n$plugin->version = %s;\n$plugin->requires = 2020110900;\n$plugin->maturity = MATURITY_STABLE;\n$plugin->release = "1.0";\n' "$2" > local/mooshwl/version.php
printf '<?php\ndefined("MOODLE_INTERNAL") || die();\n$string["pluginname"] = "Moosh whitelist-dir test";\n' > local/mooshwl/lang/en/local_mooshwl.php
printf '<?php\n// MOOSH2_TEST_WLDIR_MARKER\necho "vendored, expected to trip the planted rule";\n' > local/mooshwl/marker.php
echo "$2" > local/mooshwl/.pkgver
SH
chmod +x "$PK"/bin/*.sh

# Custom ClamAV rule matching a marker WE define - deterministic, no
# guessing about real-world signatures (same technique as
# test_plugin_clamscan.sh). Lives under the declarative list's own
# .clamav/exceptions/, which is where PluginListApply52Handler::
# scanWithClamav() looks for extra .ndb/.hdb databases (despite the
# directory's name, it's -d-loaded alongside .clamav/rules, which is
# YARA-only: .yar/.yara).
MARKER_HEX=$(printf 'MOOSH2_TEST_WLDIR_MARKER' | od -An -tx1 | tr -d ' \n')
mkdir -p "$PKGDIR/.clamav/exceptions"
echo "Test.Moosh2.WlDirMarker:0:*:${MARKER_HEX}" > "$PKGDIR/.clamav/exceptions/custom.ndb"

apply_pkg() { run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$PKGDIR" "$@" package_mooshwl; }

echo "--- Test: fresh install with no whitelist anywhere - malware is detected ---"
reset_cache_definitions
apply_pkg --run --scanner=clamav
EC=$?
assert_exit_code "Nonzero exit: malware scan found the planted marker" 1 "$EC"
assert_output_contains "Reports the malware scan failure" "malware scan found malware" "$OUT"
assert_output_contains "Names the matched rule" "Test.Moosh2.WlDirMarker" "$OUT"
if [ -f "$A/marker.php" ]; then
    echo "  PASS: package files are on disk (install runs before the scan)"
    ((PASS++))
else
    echo "  FAIL: expected local/mooshwl/marker.php to exist after the install step"
    ((FAIL++))
fi
echo ""

echo "--- Test: per-plugin whitelist in the declarative list directory suppresses the match on reinstall ---"
# componentdir = $PK itself, the same directory as version/checksum/archive/.
echo 'marker.php | Test.Moosh2.WlDirMarker' > "$PK/clamscan-whitelist"
echo "2" > "$PK/version"
reset_cache_definitions
apply_pkg --run --scanner=clamav
EC=$?
assert_exit_code "Exit code 0: whitelist in componentdir is honoured" 0 "$EC"
assert_output_contains "Plugin installed" "INSTALLED package_mooshwl" "$OUT"
assert_output_contains "Reports the marker file as WHITELISTED" "WHITELISTED: marker.php" "$OUT"
assert_output_contains "Names the per-plugin whitelist source" "via clamscan-whitelist" "$OUT"
if [ "$(cat "$A/.pkgver" 2>/dev/null)" = "2" ]; then
    echo "  PASS: reinstall actually ran (version bumped to 2)"
    ((PASS++))
else
    echo "  FAIL: expected local/mooshwl/.pkgver to read 2 after the reinstall"
    ((FAIL++))
fi
echo ""

echo "--- Test: the same whitelist file placed in the installed plugin directory instead does NOT survive a reinstall ---"
# This is the bug this fix addresses, demonstrated directly: writing the
# per-plugin whitelist into componentpath (rather than componentdir) looks
# like it should work right up until the next install replaces the whole
# directory - exactly like a real downloaded plugin zip would - and wipes
# it before the scan ever sees it.
rm -f "$PK/clamscan-whitelist"
echo 'marker.php | Test.Moosh2.WlDirMarker' > "$A/clamscan-whitelist"
echo "3" > "$PK/version"
reset_cache_definitions
apply_pkg --run --scanner=clamav
EC=$?
assert_exit_code "Nonzero exit: componentpath-only whitelist did not survive the reinstall" 1 "$EC"
assert_output_contains "Malware is detected again" "malware scan found malware" "$OUT"
assert_output_not_contains "Not reported as whitelisted this time" "WHITELISTED: marker.php" "$OUT"
echo ""

echo "--- Cleaning up ---"
sudo rm -rf "$A" 2>/dev/null
reset_cache_definitions
echo ""

bash "$SCRIPT_DIR/clear.sh"

print_summary
