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

echo "--- Test: plugin:clamscan:update-signatures downloads signatures ---"
# The signature directory is fixed by the ClamavSignatureManager service:
# it always lives at $HOME/.moosh2/clamav-signatures.  Remove any stale
# copy first so we know the files we find were fetched by this run.
SIGDIR="${HOME}/.moosh2/clamav-signatures"
rm -rf "$SIGDIR"

run_moosh plugin:clamscan:update-signatures
EC=$?
assert_exit_code "Exit code 0 updating signatures" 0 "$EC"
assert_output_contains "Reports the signature directory" "clamav-signatures" "$OUT"
assert_output_contains "Reports at least one OK file" "OK" "$OUT"

if [ -d "$SIGDIR" ]; then
    echo "  PASS: signature directory created at $SIGDIR"
    ((PASS++))
else
    echo "  FAIL: signature directory not created at $SIGDIR"
    ((FAIL++))
fi

# Verify every expected signature file landed on disk with non-zero size.
EXPECTED_FILES=(
    "interserver256.hdb"
    "interservertopline.db"
    "shell.ldb"
    "whitelist.fp"
)
for f in "${EXPECTED_FILES[@]}"; do
    if [ -f "$SIGDIR/$f" ] && [ -s "$SIGDIR/$f" ]; then
        echo "  PASS: found $f ($(stat -c%s "$SIGDIR/$f") bytes)"
        ((PASS++))
    else
        echo "  FAIL: missing or empty $f"
        ((FAIL++))
    fi
done

# Sanity-check that clamscan can actually load the downloaded directory.
# This catches malformed / truncated / still-gzipped files that pass the
# size check above but would make clamscan exit with code 2.
#
# Uses an empty regular file, not /dev/null: /dev/null is a character
# device, and clamscan reports a scan failure (exit 2) for non-regular
# targets on some platforms — which would make this check fail even when
# the database loaded fine. A real file avoids the false positive.
if command -v clamscan >/dev/null 2>&1; then
    SANITY_TARGET=$(mktemp)
    if clamscan -d "$SIGDIR" "$SANITY_TARGET" >/dev/null 2>&1; then
        echo "  PASS: clamscan loaded the downloaded signature directory"
        ((PASS++))
    else
        # Distinguish "database load failed" (exit 2 with a "Can't load"
        # message) from other errors, so a genuine problem is visible.
        SANITY_EC=$?
        SANITY_OUT=$(clamscan -d "$SIGDIR" "$SANITY_TARGET" 2>&1 | head -5)
        if [ "$SANITY_EC" -eq 1 ]; then
            echo "  PASS: clamscan loaded the downloaded signature directory (target matched a signature — harmless)"
            ((PASS++))
        else
            echo "  FAIL: clamscan could not load $SIGDIR (exit $SANITY_EC)"
            echo "        First lines of clamscan output:"
            echo "$SANITY_OUT" | sed 's/^/          /'
            ((FAIL++))
        fi
    fi
    rm -f "$SANITY_TARGET"
fi

echo "--- Test: EICAR test file is detected ---"
# The EICAR test file is the industry-standard antivirus test string.
# It is completely harmless, but any compliant scanner must flag it.
# This test is self-contained: it creates the EICAR file plus a minimal
# .ndb rule that matches the EICAR byte pattern, so it does not depend
# on the upstream signatures including EICAR detection.
EICARDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$EICARDIR/version.php"
cat > "$EICARDIR/eicar.txt" << 'EICAR_EOF'
X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*
EICAR_EOF

EICARRULEDIR=$(mktemp -d)
EICAR_HEX=$(od -An -tx1 "$EICARDIR/eicar.txt" | tr -d ' \n')
echo "Eicar-Test-Signature:0:*:${EICAR_HEX}" > "$EICARRULEDIR/eicar.ndb"

OUT=$(cd "$EICARDIR" && $PHP $MOOSH plugin:clamscan -d "$EICARRULEDIR" -i 2>&1)
EC=$?
assert_exit_code "Exit code 1 when EICAR is detected" 1 "$EC"
assert_output_contains "Reports the EICAR file" "eicar.txt" "$OUT"
assert_output_contains "Reports the EICAR signature" "Eicar-Test-Signature" "$OUT"
rm -rf "$EICARDIR" "$EICARRULEDIR"
echo ""

echo "--- Test: Scan EICAR with downloaded signatures ---"
# This exercises the full pipeline: update-signatures -> scan -> detect.
# The upstream signatures may or may not include EICAR detection, so this
# is reported as INFO if no match occurs rather than a hard failure.
if [ -d "$SIGDIR" ] && [ -n "$(ls -A "$SIGDIR" 2>/dev/null)" ]; then
    EICARDIR2=$(mktemp -d)
    echo '<?php $plugin->version = 1;' > "$EICARDIR2/version.php"
    cat > "$EICARDIR2/eicar.txt" << 'EICAR_EOF'
X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*
EICAR_EOF
    OUT=$(cd "$EICARDIR2" && $PHP $MOOSH plugin:clamscan -d "$SIGDIR" -i 2>&1)
    EC=$?
    if [ "$EC" -eq 1 ]; then
        echo "  PASS: downloaded signatures detected EICAR"
        ((PASS++))
    else
        echo "  INFO: downloaded signatures did not detect EICAR (exit code $EC)"
        echo "        This is expected if the upstream signatures do not include EICAR."
    fi
    rm -rf "$EICARDIR2"
else
    echo "  SKIP: no downloaded signatures available"
fi
echo ""

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