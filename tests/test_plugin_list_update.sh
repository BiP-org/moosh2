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

echo "--- Test: checksum is pinned as an MD5 (moodle.org's own downloadmd5), not a locally-computed sha256 ---"
CHECKSUM_FILE="$LISTDIR/mod_attendance/checksum"
if [ ! -f "$CHECKSUM_FILE" ]; then
    echo "  FAIL: $CHECKSUM_FILE was not created"
    echo "  --- plugin:list-update output ---"
    echo "$OUT"
    ((FAIL++))
else
    CHECKSUM=$(cat "$CHECKSUM_FILE")
    # An md5 is 32 lowercase hex chars; the old sha256-based logic would
    # have written 64. Deliberately not pinned to one exact expected value
    # here (unlike the version check above) since the actual latest
    # mod_attendance version - and so its downloadmd5 - drifts over time;
    # the format is what distinguishes "pinned from plugins.json's
    # downloadmd5" from "we went back to hashing the zip ourselves".
    if [[ "$CHECKSUM" =~ ^[a-f0-9]{32}$ ]]; then
        echo "  PASS: checksum looks like an md5 ($CHECKSUM)"
        ((PASS++))
    else
        echo "  FAIL: checksum doesn't look like a 32-char lowercase-hex md5: '$CHECKSUM' (length ${#CHECKSUM})"
        ((FAIL++))
    fi
fi
echo ""

echo "--- Test: pinned checksum matches plugins.json's own downloadmd5 for that version ---"
if [ -n "${CHECKSUM:-}" ]; then
    PINNED_VERSION=$(cat "$LISTDIR/mod_attendance/version")
    EXPECTED_MD5=$(php -r '
$json = @file_get_contents("https://download.moodle.org/api/1.3/pluglist.php");
if ($json === false) {
    exit(0); // network unavailable here - the caller treats empty output as SKIP
}
$data = json_decode($json);
foreach ($data->plugins as $p) {
    if ($p->component === "mod_attendance") {
        foreach ($p->versions as $v) {
            if ((string) $v->version === $argv[1]) {
                echo $v->downloadmd5 ?? "";
                exit(0);
            }
        }
    }
}
' "$PINNED_VERSION")

    if [ -z "$EXPECTED_MD5" ]; then
        echo "  SKIP: could not fetch/locate downloadmd5 for mod_attendance $PINNED_VERSION from plugins.json (network issue?)"
    elif [ "$CHECKSUM" = "$EXPECTED_MD5" ]; then
        echo "  PASS: pinned checksum matches plugins.json's downloadmd5 exactly ($EXPECTED_MD5)"
        ((PASS++))
    else
        echo "  FAIL: pinned checksum ($CHECKSUM) doesn't match plugins.json's downloadmd5 ($EXPECTED_MD5) for version $PINNED_VERSION"
        ((FAIL++))
    fi
else
    echo "  SKIP: no checksum was captured by the previous test"
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

rm -rf "$LISTDIR"
print_summary
