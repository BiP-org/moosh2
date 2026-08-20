#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-update
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_list_update.sh
#
# Note: only mod_attendance is used as a "real" plugin here. Not every
# plugin on moodle.org has a version explicitly marked compatible with
# every Moodle release - block_progress, for example, does not reliably
# resolve a version for --moodle-version=5.1 the way mod_attendance does,
# even though it installs fine via `plugin:install --force` (a different
# code path that skips compatibility matching entirely). mod_attendance is
# actively maintained and has consistently resolved in CI, so it's the only
# plugin this file relies on actually existing on moodle.org.

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-update integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Test: Help ---"
run_moosh plugin:list-update --help
assert_output_contains "Help description" "declarative plugin list" "$OUT"
assert_output_contains "Help shows --directory" "--directory" "$OUT"
assert_output_contains "Help shows --moodle-version" "--moodle-version" "$OUT"
assert_output_contains "Help shows --run" "--run" "$OUT"
assert_output_contains "Help shows --token" "--token" "$OUT"
echo ""

LISTDIR=$(mktemp -d)
mkdir -p "$LISTDIR/mod_attendance"

echo "--- Test: Dry run does not write a version file ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Shows dry run banner" "Dry run" "$OUT"
if [ ! -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: No version file written without --run"
    ((PASS++))
else
    echo "  FAIL: version file was written despite dry run"
    ((FAIL++))
fi
echo ""

echo "--- Test: --run writes a plausible version number ---"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0 for --run" 0 "$EC"
VERSION_FILE="$LISTDIR/mod_attendance/version"
if [ ! -f "$VERSION_FILE" ]; then
    echo "  FAIL: $VERSION_FILE was not created"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
else
    VERSION=$(cat "$VERSION_FILE")
    # Moodle plugin versions are date-stamped ints, eg. 2024010100 - just
    # confirm it looks like one rather than asserting an exact value, since
    # the actual latest version drifts over time.
    if [[ "$VERSION" =~ ^20[0-9]{8}$ ]]; then
        echo "  PASS: mod_attendance/version looks like a real version ($VERSION)"
        ((PASS++))
    else
        echo "  FAIL: mod_attendance/version content doesn't look like a version: '$VERSION'"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: --run pins an md5 checksum (32 hex chars), not sha256 ---"
# install_plugins.php's own checksum handling (moodle_official::get_plugin_download_info(),
# helper::download_cached()) is md5-based - the pinned checksum file has to
# be too, or a repo shared between the two tools would disagree on what the
# checksum file even means.
CHECKSUM_FILE="$LISTDIR/mod_attendance/checksum"
if [ ! -f "$CHECKSUM_FILE" ]; then
    echo "  FAIL: $CHECKSUM_FILE was not created"
    ((FAIL++))
else
    CHECKSUM=$(cat "$CHECKSUM_FILE")
    if [[ "$CHECKSUM" =~ ^[0-9a-f]{32}$ ]]; then
        echo "  PASS: checksum file holds a 32-char md5 hex digest ($CHECKSUM)"
        ((PASS++))
    else
        echo "  FAIL: checksum file doesn't look like an md5 digest: '$CHECKSUM'"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: pinned checksum matches plugins.json's own downloadmd5 for that version ---"
# Independent check against the live moodle.org API (not moosh2's own cached copy of
# plugins.json - that would just be confirming reconcileChecksum() agrees with itself).
# This is the same value install_plugins.php's helper::download_cached() verifies a
# download against, so it's the real cross-tool compatibility check for the checksum file.
ATTENDANCE_VERSION=$(cat "$LISTDIR/mod_attendance/version" 2>/dev/null || true)
PINNED_CHECKSUM=$(cat "$CHECKSUM_FILE" 2>/dev/null || true)
if [ -z "$ATTENDANCE_VERSION" ] || [ -z "$PINNED_CHECKSUM" ]; then
    echo "  FAIL: mod_attendance/version or /checksum missing from the previous test, cannot verify"
    ((FAIL++))
else
    EXPECTED_MD5=$(curl -fsSL --max-time 30 'https://download.moodle.org/api/1.3/pluglist.php' | php -r '
        $data = json_decode(stream_get_contents(STDIN));
        if (!$data) { exit(1); }
        $version = $argv[1];
        foreach ($data->plugins as $plugin) {
            if ($plugin->component !== "mod_attendance") { continue; }
            foreach ($plugin->versions as $v) {
                if ((string)$v->version === $version) {
                    echo $v->downloadmd5 ?? "";
                    exit(0);
                }
            }
        }
        exit(1);
    ' "$ATTENDANCE_VERSION")
    FETCH_EC=$?
    if [ $FETCH_EC -ne 0 ]; then
        echo "  SKIP: could not fetch/find mod_attendance version $ATTENDANCE_VERSION in the live moodle.org plugin list"
    elif [ -z "$EXPECTED_MD5" ]; then
        echo "  SKIP: plugins.json has no downloadmd5 for mod_attendance version $ATTENDANCE_VERSION, nothing to compare against"
    elif [ "$EXPECTED_MD5" = "$PINNED_CHECKSUM" ]; then
        echo "  PASS: pinned checksum ($PINNED_CHECKSUM) matches plugins.json's downloadmd5 for version $ATTENDANCE_VERSION"
        ((PASS++))
    else
        echo "  FAIL: pinned checksum ($PINNED_CHECKSUM) does not match plugins.json's downloadmd5 ($EXPECTED_MD5) for version $ATTENDANCE_VERSION"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: Re-running --run reports already up to date ---"
FIRST_VERSION=$(cat "$LISTDIR/mod_attendance/version")
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
SECOND_VERSION=$(cat "$LISTDIR/mod_attendance/version")
if [ "$FIRST_VERSION" = "$SECOND_VERSION" ]; then
    echo "  PASS: version file unchanged on a second, back-to-back --run"
    ((PASS++))
else
    echo "  FAIL: version changed between two immediate runs ($FIRST_VERSION -> $SECOND_VERSION)"
    ((FAIL++))
fi
echo ""

echo "--- Test: Filtering by positional plugin name only updates that one ---"
# Uses a pre-seeded sentinel in an unrelated "control" directory rather than
# a second real moodle.org plugin, so this doesn't depend on that second
# plugin also happening to resolve a compatible version (see note above).
rm -f "$LISTDIR/mod_attendance/version"
mkdir -p "$LISTDIR/zzz_filter_control"
echo "9999999999" > "$LISTDIR/zzz_filter_control/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
CONTROL_VALUE=$(cat "$LISTDIR/zzz_filter_control/version")
if [ -f "$LISTDIR/mod_attendance/version" ] && [ "$CONTROL_VALUE" = "9999999999" ]; then
    echo "  PASS: mod_attendance was updated, zzz_filter_control was left untouched"
    ((PASS++))
else
    echo "  FAIL: expected only mod_attendance/version to be written (control now: '$CONTROL_VALUE')"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
fi
rm -rf "$LISTDIR/zzz_filter_control"
echo ""

echo "--- Test: --token doesn't affect a normal (non-Marketplace) update ---"
# The token is only ever sent as a Bearer header to marketplace.moodle.com
# (see PluginApiClient::isMarketplaceHost()) - download.moodle.org, which
# is all this test actually talks to, should behave identically whether or
# not one is supplied. This guards against the option breaking normal
# usage, e.g. via a parsing mistake or the host check being backwards.
rm -f "$LISTDIR/mod_attendance/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run --token=dummy-test-token mod_attendance
EC=$?
assert_exit_code "Exit code 0 with --token set" 0 "$EC"
if [ -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: --token didn't interfere with a normal update"
    ((PASS++))
else
    echo "  FAIL: version file missing when --token was supplied"
    ((FAIL++))
fi
echo ""

echo "--- Test: MOODLE_MARKETPLACE_TOKEN env var behaves the same way ---"
rm -f "$LISTDIR/mod_attendance/version"
export MOODLE_MARKETPLACE_TOKEN="dummy-env-token"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_attendance
EC=$?
unset MOODLE_MARKETPLACE_TOKEN
assert_exit_code "Exit code 0 with MOODLE_MARKETPLACE_TOKEN set" 0 "$EC"
if [ -f "$LISTDIR/mod_attendance/version" ]; then
    echo "  PASS: MOODLE_MARKETPLACE_TOKEN didn't interfere with a normal update"
    ((PASS++))
else
    echo "  FAIL: version file missing when MOODLE_MARKETPLACE_TOKEN was set"
    ((FAIL++))
fi
echo ""

echo "--- Test: Unknown component directory reports an error ---"
mkdir -p "$LISTDIR/totally_not_a_real_plugin_xyz"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run totally_not_a_real_plugin_xyz
EC=$?
assert_exit_code "Nonzero exit for unknown plugin" 1 "$EC"
assert_output_contains "Error mentions the component" "totally_not_a_real_plugin_xyz" "$OUT"
rm -rf "$LISTDIR/totally_not_a_real_plugin_xyz"
echo ""

echo "--- Test: Nonexistent --directory ---"
run_moosh plugin:list-update --directory=/tmp/does_not_exist_$$_listupdate --moodle-version=5.1
EC=$?
assert_exit_code "Exit code nonzero" 1 "$EC"
assert_output_contains "Directory not found error" "Directory not found" "$OUT"
echo ""

# --- Marketplace-subscription-only plugin (HTTP 401) ---
#
# tiny_fontfamily is listed in plugins.json (moodle.org's public plugin
# directory) but its actual zip only lives behind marketplace.moodle.com,
# which returns HTTP 401 "Not privileged to request the resource" without
# a valid subscription token - exactly the case this feature exists for.
# These tests deliberately do NOT pass --token, so every request to
# marketplace.moodle.com here is expected to 401.
unset CI

echo "--- Test: Marketplace 401 does not create a version file ---"
mkdir -p "$LISTDIR/tiny_fontfamily"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run tiny_fontfamily
EC=$?
assert_exit_code "Exit code 0 (a 401 is a WARN, not an ERROR)" 0 "$EC"
assert_output_contains "Explains the Marketplace subscription requirement" "Marketplace subscription" "$OUT"
assert_output_contains "Mentions HTTP 401" "401" "$OUT"
if [ ! -f "$LISTDIR/tiny_fontfamily/version" ]; then
    echo "  PASS: no version file was created for a plugin only reachable via Marketplace"
    ((PASS++))
else
    echo "  FAIL: version file was created despite the download being 401 Unauthorized"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
fi
echo ""

echo "--- Test: Marketplace 401 leaves an existing version file untouched ---"
echo "2020010100" > "$LISTDIR/tiny_fontfamily/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run tiny_fontfamily
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
PINNED_VERSION=$(cat "$LISTDIR/tiny_fontfamily/version")
if [ "$PINNED_VERSION" = "2020010100" ]; then
    echo "  PASS: pre-existing version (2020010100) was left in place, not bumped"
    ((PASS++))
else
    echo "  FAIL: version changed from 2020010100 to '$PINNED_VERSION' despite the 401"
    ((FAIL++))
fi
if [ ! -f "$LISTDIR/tiny_fontfamily/checksum" ]; then
    echo "  PASS: no checksum file was written either"
    ((PASS++))
else
    echo "  FAIL: a checksum file was written despite the download being 401 Unauthorized"
    ((FAIL++))
fi
echo ""

echo "--- Test: Marketplace 401 prints a plain WARNING outside CI ---"
assert_output_contains "Plain WARNING prefix (no \$CI set)" "WARNING" "$OUT"
if [[ "$OUT" != *"::warning"* ]]; then
    echo "  PASS: no GitHub Actions annotation emitted when \$CI is unset"
    ((PASS++))
else
    echo "  FAIL: unexpected ::warning:: annotation emitted without \$CI set"
    ((FAIL++))
fi
echo ""

echo "--- Test: Marketplace 401 emits a GitHub Actions annotation when \$CI is set ---"
rm -f "$LISTDIR/tiny_fontfamily/version"
export CI=true
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run tiny_fontfamily
EC=$?
unset CI
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "GitHub Actions warning annotation" "::warning" "$OUT"
assert_output_contains "Annotation still names the plugin" "tiny_fontfamily" "$OUT"
if [ ! -f "$LISTDIR/tiny_fontfamily/version" ]; then
    echo "  PASS: still no version file written under \$CI"
    ((PASS++))
else
    echo "  FAIL: version file was created under \$CI despite the 401"
    ((FAIL++))
fi
echo ""

rm -rf "$LISTDIR/tiny_fontfamily"

# --- bin/get_latest_plugin_version.sh for non-package_ components ---
#
# updatePackageComponent()'s script-based lookup used to only ever be
# reached for package_* components. It's now reached for ANY component
# that ships bin/get_latest_plugin_version.sh, regardless of its name -
# these tests cover that with a fake local_scripted component so they
# don't depend on anything real on moodle.org.

echo "--- Test: non-package_ component with bin/get_latest_plugin_version.sh uses the script, not plugins.json ---"
mkdir -p "$LISTDIR/local_scripted/bin"
cat > "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
echo "2099010100"
EOS
chmod +x "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh"

run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 local_scripted
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Dry-run message shows the script's version" "2099010100" "$OUT"
if [ ! -f "$LISTDIR/local_scripted/version" ]; then
    echo "  PASS: dry run didn't write a version file"
    ((PASS++))
else
    echo "  FAIL: version file was written despite dry run"
    ((FAIL++))
fi

run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run local_scripted
EC=$?
assert_exit_code "Exit code 0 for --run" 0 "$EC"
if [ -f "$LISTDIR/local_scripted/version" ] && [ "$(cat "$LISTDIR/local_scripted/version")" = "2099010100" ]; then
    echo "  PASS: version came from bin/get_latest_plugin_version.sh (2099010100), not plugins.json"
    ((PASS++))
else
    echo "  FAIL: expected local_scripted/version to be 2099010100 (got: $(cat "$LISTDIR/local_scripted/version" 2>/dev/null))"
    ((FAIL++))
fi
echo ""

echo "--- Test: script also gets picked up via auto-discovery (no plugin name given) ---"
rm -f "$LISTDIR/local_scripted/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
if [ -f "$LISTDIR/local_scripted/version" ] && [ "$(cat "$LISTDIR/local_scripted/version")" = "2099010100" ]; then
    echo "  PASS: local_scripted was picked up and updated without being named explicitly"
    ((PASS++))
else
    echo "  FAIL: expected local_scripted/version to be 2099010100 after an unfiltered --run"
    ((FAIL++))
fi
echo ""

echo "--- Test: script runs with cwd and env vars pointing at --moodle-root ---"
FAKEROOT=$(mktemp -d)
SCRIPT_LOG="$LISTDIR/local_scripted_env.log"
rm -f "$LISTDIR/local_scripted/version" "$SCRIPT_LOG"
cat > "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh" <<EOS
#!/usr/bin/env bash
{
    echo "PWD=\$(pwd -P)"
    echo "CONFIG_DIR=\$__config_plugin_directory"
    echo "MOODLE_ROOT=\$__moodle_root_directory"
} > "$SCRIPT_LOG"
echo "2099010101"
EOS
chmod +x "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh"

run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --moodle-root="$FAKEROOT" --run local_scripted
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
LOG=$(cat "$SCRIPT_LOG" 2>/dev/null || echo "")
EXPECTED_PWD=$(cd "$FAKEROOT" && pwd -P)
EXPECTED_CONFIGDIR=$(cd "$LISTDIR/local_scripted" && pwd -P)
if [[ "$LOG" == *"PWD=$EXPECTED_PWD"* ]]; then
    echo "  PASS: script ran with cwd set to --moodle-root"
    ((PASS++))
else
    echo "  FAIL: expected cwd $EXPECTED_PWD in script log, got: $LOG"
    ((FAIL++))
fi
if [[ "$LOG" == *"CONFIG_DIR=$EXPECTED_CONFIGDIR"* ]]; then
    echo "  PASS: __config_plugin_directory pointed at the component directory"
    ((PASS++))
else
    echo "  FAIL: expected CONFIG_DIR=$EXPECTED_CONFIGDIR in script log, got: $LOG"
    ((FAIL++))
fi
if [[ "$LOG" == *"MOODLE_ROOT=$FAKEROOT"* ]]; then
    echo "  PASS: __moodle_root_directory matched --moodle-root"
    ((PASS++))
else
    echo "  FAIL: expected MOODLE_ROOT=$FAKEROOT in script log, got: $LOG"
    ((FAIL++))
fi
rm -rf "$FAKEROOT"
rm -f "$SCRIPT_LOG"
echo ""

echo "--- Test: version pinned to 0 skips the script entirely, even with the script present ---"
rm -f "$LISTDIR/local_scripted/version"
echo "0" > "$LISTDIR/local_scripted/version"
cat > "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
# If this ever runs, the sentinel below would end up in version - it must not.
echo "2099010199"
EOS
chmod +x "$LISTDIR/local_scripted/bin/get_latest_plugin_version.sh"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run local_scripted
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "SKIP message for pinned version 0" "pinned to version 0" "$OUT"
PINNED=$(cat "$LISTDIR/local_scripted/version")
if [ "$PINNED" = "0" ]; then
    echo "  PASS: version file left at 0, script's 2099010199 was never written"
    ((PASS++))
else
    echo "  FAIL: expected version to stay 0, got '$PINNED'"
    ((FAIL++))
fi
rm -rf "$LISTDIR/local_scripted"
echo ""

# --- install_plugins.php update-versions compatibility ---
#
# Both tools manage the exact same declarative-plugin-list layout, so they
# have to agree on which `version` values mean "leave this alone" rather
# than "an out of date version to bump". install_plugins.php's
# plugin_update_version() skips on any `$current_version <= 0`; plugin:list-apply
# additionally recognizes the "uninstall"/"remove-files" spellings. All of
# those must be left untouched here too - most importantly "-1", since
# treating it as an ordinary version to bump would silently overwrite a
# remove-files-only pin with a real version number.

echo "--- Test: version pinned to -1 is skipped, not overwritten ---"
mkdir -p "$LISTDIR/mod_removefilespin"
echo "-1" > "$LISTDIR/mod_removefilespin/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_removefilespin
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "SKIP message explains the remove-files pin" "remove-files" "$OUT"
PINNED=$(cat "$LISTDIR/mod_removefilespin/version")
if [ "$PINNED" = "-1" ]; then
    echo "  PASS: version file left at -1, not overwritten with a real version"
    ((PASS++))
else
    echo "  FAIL: expected version to stay -1, got '$PINNED'"
    ((FAIL++))
fi
rm -rf "$LISTDIR/mod_removefilespin"
echo ""

echo "--- Test: version pinned to the string 'uninstall' is skipped ---"
mkdir -p "$LISTDIR/mod_uninstallstrpin"
echo "uninstall" > "$LISTDIR/mod_uninstallstrpin/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_uninstallstrpin
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "SKIP message explains the uninstall pin" "marked for uninstall" "$OUT"
PINNED=$(cat "$LISTDIR/mod_uninstallstrpin/version")
if [ "$PINNED" = "uninstall" ]; then
    echo "  PASS: version file left at 'uninstall', not overwritten"
    ((PASS++))
else
    echo "  FAIL: expected version to stay 'uninstall', got '$PINNED'"
    ((FAIL++))
fi
rm -rf "$LISTDIR/mod_uninstallstrpin"
echo ""

echo "--- Test: version pinned to the string 'remove-files' is skipped ---"
mkdir -p "$LISTDIR/mod_removefilesstrpin"
echo "remove-files" > "$LISTDIR/mod_removefilesstrpin/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_removefilesstrpin
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "SKIP message explains the remove-files pin" "marked for remove-files-only" "$OUT"
PINNED=$(cat "$LISTDIR/mod_removefilesstrpin/version")
if [ "$PINNED" = "remove-files" ]; then
    echo "  PASS: version file left at 'remove-files', not overwritten"
    ((PASS++))
else
    echo "  FAIL: expected version to stay 'remove-files', got '$PINNED'"
    ((FAIL++))
fi
rm -rf "$LISTDIR/mod_removefilesstrpin"
echo ""

echo "--- Test: any other non-positive version (install_plugins.php's <= 0 rule) is skipped ---"
mkdir -p "$LISTDIR/mod_negativepin"
echo "-2" > "$LISTDIR/mod_negativepin/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run mod_negativepin
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
PINNED=$(cat "$LISTDIR/mod_negativepin/version")
if [ "$PINNED" = "-2" ]; then
    echo "  PASS: version file left at -2, not overwritten"
    ((PASS++))
else
    echo "  FAIL: expected version to stay -2, got '$PINNED'"
    ((FAIL++))
fi
rm -rf "$LISTDIR/mod_negativepin"
echo ""

echo "--- Test: script present but not executable reports an error ---"
mkdir -p "$LISTDIR/local_not_executable/bin"
cat > "$LISTDIR/local_not_executable/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
echo "2099010100"
EOS
chmod -x "$LISTDIR/local_not_executable/bin/get_latest_plugin_version.sh"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run local_not_executable
EC=$?
assert_exit_code "Nonzero exit for non-executable script" 1 "$EC"
assert_output_contains "Error names the component" "local_not_executable" "$OUT"
assert_output_contains "Error mentions not executable" "not executable" "$OUT"
rm -rf "$LISTDIR/local_not_executable"
echo ""

echo "--- Test: script that exits non-zero surfaces its own output in the error ---"
mkdir -p "$LISTDIR/local_failing/bin"
cat > "$LISTDIR/local_failing/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
echo "boom: something went wrong" >&2
exit 3
EOS
chmod +x "$LISTDIR/local_failing/bin/get_latest_plugin_version.sh"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run local_failing
EC=$?
assert_exit_code "Nonzero exit for failing script" 1 "$EC"
assert_output_contains "Error mentions exit status" "exited with status 3" "$OUT"
assert_output_contains "Error surfaces the script's own message" "boom: something went wrong" "$OUT"
rm -rf "$LISTDIR/local_failing"
echo ""

echo "--- Test: script that outputs a non-integer version reports an error ---"
mkdir -p "$LISTDIR/local_badversion/bin"
cat > "$LISTDIR/local_badversion/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
echo "not-a-version"
EOS
chmod +x "$LISTDIR/local_badversion/bin/get_latest_plugin_version.sh"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run local_badversion
EC=$?
assert_exit_code "Nonzero exit for non-integer version output" 1 "$EC"
assert_output_contains "Error mentions invalid integer version" "did not report a valid integer version" "$OUT"
rm -rf "$LISTDIR/local_badversion"
echo ""

echo "--- Test: package_ component without the script still errors as before ---"
mkdir -p "$LISTDIR/package_missing_script"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_missing_script
EC=$?
assert_exit_code "Nonzero exit when a package_ component has no script" 1 "$EC"
assert_output_contains "Error mentions the missing script" "could not find package_missing_script/bin/get_latest_plugin_version.sh" "$OUT"
rm -rf "$LISTDIR/package_missing_script"
echo ""

# --- <component>/<component>.php (install_plugins.php's PHP package_base convention) ---
#
# Covers package_kaltura-style components: no bin/get_latest_plugin_version.sh, just a
# <component>/<component>.php class meant to run inside install_plugins.php itself. These tests
# use a minimal install_plugins.php stub that only implements the `get-latest-version` contract
# (stdout = exactly the resolved integer, nonzero exit + stderr on failure) rather than a real
# Moodle-bootstrapped install_plugins.php, so they don't depend on GitHub or a live Moodle site.

echo "--- Test: <component>/<component>.php with no bin/ script uses the install_plugins.php bridge ---"
mkdir -p "$LISTDIR/package_kaltura"
touch "$LISTDIR/package_kaltura/package_kaltura.php"
cat > "$LISTDIR/install_plugins.php" <<'EOS'
<?php
// Stub: only implements the get-latest-version contract this test needs.
if (($argv[1] ?? '') === 'get-latest-version' && ($argv[2] ?? '') === 'package_kaltura') {
    echo "2099010100\n";
    exit(0);
}
fwrite(STDERR, "stub does not know how to handle: " . implode(' ', array_slice($argv, 1)) . "\n");
exit(1);
EOS

run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 package_kaltura
EC=$?
assert_exit_code "Exit code 0 for dry run" 0 "$EC"
assert_output_contains "Dry-run message shows the resolved version" "2099010100" "$OUT"
if [ ! -f "$LISTDIR/package_kaltura/version" ]; then
    echo "  PASS: dry run didn't write a version file"
    ((PASS++))
else
    echo "  FAIL: version file was written despite dry run"
    ((FAIL++))
fi

run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_kaltura
EC=$?
assert_exit_code "Exit code 0 for --run" 0 "$EC"
if [ -f "$LISTDIR/package_kaltura/version" ] && [ "$(cat "$LISTDIR/package_kaltura/version")" = "2099010100" ]; then
    echo "  PASS: version came from install_plugins.php get-latest-version (2099010100), not a bin/ script"
    ((PASS++))
else
    echo "  FAIL: expected package_kaltura/version to be 2099010100 (got: $(cat "$LISTDIR/package_kaltura/version" 2>/dev/null))"
    ((FAIL++))
fi
echo ""

echo "--- Test: bin/get_latest_plugin_version.sh takes priority over <component>/<component>.php when both exist ---"
mkdir -p "$LISTDIR/package_kaltura/bin"
cat > "$LISTDIR/package_kaltura/bin/get_latest_plugin_version.sh" <<'EOS'
#!/usr/bin/env bash
echo "2099010199"
EOS
chmod +x "$LISTDIR/package_kaltura/bin/get_latest_plugin_version.sh"
rm -f "$LISTDIR/package_kaltura/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_kaltura
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
if [ -f "$LISTDIR/package_kaltura/version" ] && [ "$(cat "$LISTDIR/package_kaltura/version")" = "2099010199" ]; then
    echo "  PASS: bin/ script's version (2099010199) was used, not the install_plugins.php bridge's"
    ((PASS++))
else
    echo "  FAIL: expected package_kaltura/version to be 2099010199 (got: $(cat "$LISTDIR/package_kaltura/version" 2>/dev/null))"
    ((FAIL++))
fi
rm -rf "$LISTDIR/package_kaltura/bin"
rm -f "$LISTDIR/package_kaltura/version"
echo ""

echo "--- Test: version pinned to 0 skips the install_plugins.php bridge entirely ---"
echo "0" > "$LISTDIR/package_kaltura/version"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_kaltura
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "SKIP message for pinned version 0" "pinned to version 0" "$OUT"
PINNED=$(cat "$LISTDIR/package_kaltura/version")
if [ "$PINNED" = "0" ]; then
    echo "  PASS: version file left at 0, install_plugins.php stub was never invoked"
    ((PASS++))
else
    echo "  FAIL: expected version to stay 0, got '$PINNED'"
    ((FAIL++))
fi
echo ""

echo "--- Test: --install-plugins-script overrides the default --directory/install_plugins.php lookup ---"
rm -f "$LISTDIR/package_kaltura/version"
ALTDIR=$(mktemp -d)
cat > "$ALTDIR/alt_install_plugins.php" <<'EOS'
<?php
if (($argv[1] ?? '') === 'get-latest-version' && ($argv[2] ?? '') === 'package_kaltura') {
    echo "2099010177\n";
    exit(0);
}
exit(1);
EOS
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --install-plugins-script="$ALTDIR/alt_install_plugins.php" --run package_kaltura
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
if [ -f "$LISTDIR/package_kaltura/version" ] && [ "$(cat "$LISTDIR/package_kaltura/version")" = "2099010177" ]; then
    echo "  PASS: --install-plugins-script's stub was used instead of --directory/install_plugins.php"
    ((PASS++))
else
    echo "  FAIL: expected package_kaltura/version to be 2099010177 (got: $(cat "$LISTDIR/package_kaltura/version" 2>/dev/null))"
    ((FAIL++))
fi
rm -rf "$ALTDIR"
echo ""

echo "--- Test: <component>/<component>.php present but install_plugins.php missing reports an error ---"
rm -f "$LISTDIR/package_kaltura/version" "$LISTDIR/install_plugins.php"
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_kaltura
EC=$?
assert_exit_code "Nonzero exit when install_plugins.php can't be found" 1 "$EC"
assert_output_contains "Error names the component" "package_kaltura" "$OUT"
assert_output_contains "Error mentions install_plugins.php" "install_plugins.php" "$OUT"
assert_output_contains "Error suggests --install-plugins-script" "--install-plugins-script" "$OUT"
rm -rf "$LISTDIR/package_kaltura"
echo ""

echo "--- Test: install_plugins.php stub exiting non-zero surfaces its stderr in the error ---"
mkdir -p "$LISTDIR/package_unresolvable"
touch "$LISTDIR/package_unresolvable/package_unresolvable.php"
cat > "$LISTDIR/install_plugins.php" <<'EOS'
<?php
fwrite(STDERR, "boom: plugin not found upstream\n");
exit(1);
EOS
run_moosh plugin:list-update --directory="$LISTDIR" --moodle-version=5.1 --run package_unresolvable
EC=$?
assert_exit_code "Nonzero exit when install_plugins.php itself fails" 1 "$EC"
assert_output_contains "Error surfaces the stub's stderr message" "boom: plugin not found upstream" "$OUT"
rm -rf "$LISTDIR/package_unresolvable" "$LISTDIR/install_plugins.php"
echo ""

rm -rf "$LISTDIR"
print_summary
