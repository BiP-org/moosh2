#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:clamscan
# Requires a working Moodle 5.2 installation at /var/www/html/moodle52
#
# Usage: bash tests/test_plugin_clamscan.sh
#

source "$(dirname "$0")/common.sh"

# A valid ClamAV extended signature that won't match anything in a real
# plugin's source - used everywhere below that a scan needs a database
# that's real (won't itself error out) but guaranteed not to hit, since we
# can't rely on the system's default ClamAV database (/var/lib/clamav)
# having any signatures loaded - CI images ship clamscan without ever
# running freshclam.
NOMATCH_MARKER_HEX=$(printf 'MOOSH2_TEST_NOMATCH_MARKER' | od -An -tx1 | tr -d ' \n')
write_nomatch_db() {
    echo "Test.Moosh2.NoMatch:0:*:${NOMATCH_MARKER_HEX}" > "$1/nomatch.ndb"
}

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
    run_moosh plugin:clamscan auth_oidc
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
# Uses an explicit database with a real, non-matching signature rather
# than relying on the system's default ClamAV database (see note above).
EMPTYRULEDIR=$(mktemp -d)
write_nomatch_db "$EMPTYRULEDIR"
run_moosh plugin:clamscan -d "$EMPTYRULEDIR" auth_oidc
EC=$?
assert_exit_code "Exit code 0 for a clean plugin" 0 "$EC"
rm -rf "$EMPTYRULEDIR"
echo ""

echo "--- Test: Scan the plugin in the current directory ---"
CWDDIR=$(mktemp -d)
# The previous test ("Scan a downloaded plugin (clean)") already downloaded
# auth_oidc once via plugin:clamscan, which populates moosh2's shared plugin
# zip cache (~/.moosh/moodleplugins, or $MOOSH_CACHE_DIR). Reuse that cached
# copy here instead of hitting download.moodle.org again for the exact same
# file a few hundred milliseconds later: a second, immediate download of the
# same resource is exactly the kind of request some CDNs/APIs rate-limit,
# and CI runners are more likely to be sharing an already-flagged IP range
# than a local dev machine is. Only fall back to a real download if, for
# whatever reason, nothing ended up cached.
CACHE_DIR="${MOOSH_CACHE_DIR:-$HOME/.moosh/moodleplugins}"
CACHED_ZIP=$(ls "$CACHE_DIR"/auth_oidc-*.zip 2>/dev/null | head -n1)
if [ -n "$CACHED_ZIP" ]; then
    LAST_CMD="(reused cached copy: $CACHED_ZIP)"
else
    # Not cd'd into CWDDIR yet, so plugin:download (which writes to getcwd())
    # writes auth_oidc.zip into the directory we're still sitting in.
    run_moosh plugin:download -p "$MOODLE_PATH" auth_oidc
    CACHED_ZIP=$(ls "$(pwd)"/auth_oidc.zip 2>/dev/null | head -n1)
fi
cd "$CWDDIR"
unzip -q -o "$CACHED_ZIP" -d . 2>/dev/null || true
EMPTYRULEDIR2=$(mktemp -d)
write_nomatch_db "$EMPTYRULEDIR2"
# Don't assume the zip's top-level folder is named after the frankenstyle
# component: moodle.org zips are named after the plugin's install path, not
# its frankenstyle name (e.g. auth_oidc extracts to a folder called "oidc",
# since it installs to auth/oidc). Locate the plugin root the same way
# moosh2 itself does: the first directory (depth-first) containing a
# version.php.
PLUGIN_ROOT=$(find "$CWDDIR" -mindepth 1 -maxdepth 3 -name version.php -print -quit)
PLUGIN_ROOT=$(dirname -- "${PLUGIN_ROOT:-/nonexistent}")
OUT=$(cd "$PLUGIN_ROOT" 2>/dev/null && $PHP $MOOSH plugin:clamscan -d "$EMPTYRULEDIR2" 2>&1)
EC=$?
cd - >/dev/null
assert_exit_code "Exit code 0 scanning cwd" 0 "$EC"
rm -rf "$CWDDIR" "$EMPTYRULEDIR2"
echo ""

echo "--- Test: Custom database (-d) detects a planted signature -> exit 1 ---"
SCANDIR=$(mktemp -d)
RULEDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$SCANDIR/version.php"
cat > "$SCANDIR/backdoor.php" << 'EOF'
<?php
// MOOSH2_TEST_MALWARE_MARKER
eval($_GET['x']);
EOF
MARKER_HEX=$(printf 'MOOSH2_TEST_MALWARE_MARKER' | od -An -tx1 | tr -d ' \n')
echo "Test.Moosh2.Marker:0:*:${MARKER_HEX}" > "$RULEDIR/custom.ndb"
LOGFILE=$(mktemp)
OUT=$(cd "$SCANDIR" && $PHP $MOOSH plugin:clamscan -d "$RULEDIR" -i --log="$LOGFILE" 2>&1)
EC=$?
assert_exit_code "Exit code 1 when a signature matches" 1 "$EC"
assert_output_contains "Reports the infected file" "backdoor.php" "$OUT"
assert_output_contains "Reports the matched rule" "Test.Moosh2.Marker" "$OUT"
if [ -s "$LOGFILE" ]; then
    echo "  PASS: --log wrote a non-empty report file"
    ((PASS++))
else
    echo "  FAIL: --log file is missing or empty"
    ((FAIL++))
fi
rm -rf "$SCANDIR" "$RULEDIR"
rm -f "$LOGFILE"
echo ""

echo "--- Test: Custom database (-d) with no match still exits 0 ---"
CLEANDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$CLEANDIR/version.php"
echo '<?php echo "nothing suspicious here";' > "$CLEANDIR/lib.php"
RULEDIR2=$(mktemp -d)
echo "Test.Moosh2.Marker:0:*:${MARKER_HEX}" > "$RULEDIR2/custom.ndb"
OUT=$(cd "$CLEANDIR" && $PHP $MOOSH plugin:clamscan -d "$RULEDIR2" 2>&1)
EC=$?
assert_exit_code "Exit code 0 when the signature is absent" 0 "$EC"
rm -rf "$CLEANDIR" "$RULEDIR2"
echo ""

print_summary
