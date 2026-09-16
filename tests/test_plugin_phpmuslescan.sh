#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:phpmuslescan
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:phpmuslescan integration tests ==="
echo ""

echo "--- Test: Help ---"
run_moosh plugin:phpmuslescan --help
assert_output_contains "Help description" "Scan a plugin for malware using phpMussel" "$OUT"
assert_output_contains "Help shows --infected" "--infected" "$OUT"
assert_output_contains "Help shows --log" "--log" "$OUT"
echo ""

echo "--- Test: update-signatures downloads signatures ---"
SIGDIR="${HOME}/.moosh2/phpmussel-signatures"
CONFIG="${SIGDIR}/phpmussel.ini"
rm -rf "$SIGDIR"

run_moosh plugin:phpmuslescan:update-signatures
EC=$?
assert_exit_code "Exit code 0 updating signatures" 0 "$EC"
assert_output_contains "Reports the signature directory" "phpmussel-signatures" "$OUT"
assert_output_contains "Reports at least one OK file" "OK" "$OUT"

# Only phpMussel-format files belong here. clamav.hdb is NOT downloaded:
# phpMussel's loader skips any file whose first 9 bytes aren't "phpMussel",
# so a ClamAV-format .hdb would be dead weight in this directory.
EXPECTED_FILES=(
    "phpmussel.hdb"
    "phpmussel.ndb"
    "phpmussel.db"
    "phpmussel.fdb"
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

# Every signature file must start with the literal string "phpMussel".
# phpMussel's Scanner::organiseSigFiles() silently skips files that don't,
# which would make every scan report clean. This is the single most
# important guard in this test file.
for f in "${EXPECTED_FILES[@]}"; do
    if [ -f "$SIGDIR/$f" ]; then
        HEADER=$(head -c 9 "$SIGDIR/$f" 2>/dev/null)
        if [ "$HEADER" = "phpMussel" ]; then
            echo "  PASS: $f has the phpMussel header"
            ((PASS++))
        else
            echo "  FAIL: $f does not start with 'phpMussel' (got: '$HEADER')"
            ((FAIL++))
        fi
    fi
done

# The config written by update-signatures must exist and list the files
# in [signatures] active. Without it, Loader falls back to a default list
# of ~40 filenames that don't exist here and every scan reports -3 errors.
if [ -f "$CONFIG" ]; then
    echo "  PASS: phpmussel.ini exists"
    ((PASS++))

    if grep -q '^\[signatures\]' "$CONFIG"; then
        echo "  PASS: phpmussel.ini has a [signatures] section"
        ((PASS++))
    else
        echo "  FAIL: phpmussel.ini is missing the [signatures] section"
        ((FAIL++))
    fi

    for f in "${EXPECTED_FILES[@]}"; do
        if grep -q "$f" "$CONFIG"; then
            echo "  PASS: phpmussel.ini lists $f in active"
            ((PASS++))
        else
            echo "  FAIL: phpmussel.ini does not list $f"
            ((FAIL++))
        fi
    done

    # The [files] section narrows phpMussel's default filetype blacklist,
    # which includes php* and would otherwise flag every .php file in a
    # Moodle plugin as infected.
    if grep -q '^\[files\]' "$CONFIG"; then
        echo "  PASS: phpmussel.ini has a [files] section"
        ((PASS++))
    else
        echo "  FAIL: phpmussel.ini is missing the [files] section"
        ((FAIL++))
    fi
    if grep -q '^filetype_blacklist' "$CONFIG"; then
        echo "  PASS: phpmussel.ini overrides filetype_blacklist"
        ((PASS++))
    else
        echo "  FAIL: phpmussel.ini does not override filetype_blacklist"
        ((FAIL++))
    fi
else
    echo "  FAIL: phpmussel.ini not created at $CONFIG"
    ((FAIL++))
fi
echo ""

echo "--- Test: Clean plugin directory exits 0 (no false positives) ---"
# Every file here is a normal, harmless PHP file. phpMussel must report
# zero infections — a nonzero exit means the filetype blacklist override
# isn't working, which would make the scanner useless on real plugins.
CLEANDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$CLEANDIR/version.php"
echo '<?php echo "hello";' > "$CLEANDIR/lib.php"
mkdir "$CLEANDIR/lang"
echo '<?php $string["hello"] = "Hello";' > "$CLEANDIR/lang/en.php"

OUT=$(cd "$CLEANDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0 for a clean plugin" 0 "$EC"
assert_output_not_contains "No version.php false positive" "Filetype blacklisted (version.php)" "$OUT"
assert_output_not_contains "No lib.php false positive" "Filetype blacklisted (lib.php)" "$OUT"
if echo "$OUT" | grep -q "Infected files: 0"; then
    echo "  PASS: scan summary reports zero infections"
    ((PASS++))
else
    echo "  FAIL: scan summary does not report zero infections"
    echo "        Output: $OUT"
    ((FAIL++))
fi
rm -rf "$CLEANDIR"
echo ""

echo "--- Test: Chameleon detection flags a disguised PHP file -> exit 1 ---"
# phpMussel's chameleon_from_php heuristic fires when a file's extension
# suggests one type but its content is PHP source. A .jpg containing
# "<?php" is the canonical trigger and requires no signature file, so
# this test doesn't depend on any particular upstream signature being
# present. This is the primary positive-detection test.
CHAMDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$CHAMDIR/version.php"
echo '<?php echo "hello";' > "$CHAMDIR/lib.php"
printf '<?php echo "not really a jpg"; ?>' > "$CHAMDIR/payload.jpg"

OUT=$(cd "$CHAMDIR" && $PHP $MOOSH plugin:phpmuslescan -i 2>&1)
EC=$?
assert_exit_code "Exit code 1 when a chameleon file is detected" 1 "$EC"
assert_output_contains "Reports the disguised file" "payload.jpg" "$OUT"
if echo "$OUT" | grep -qi "chameleon"; then
    echo "  PASS: reports chameleon detection"
    ((PASS++))
else
    echo "  FAIL: does not report chameleon detection"
    echo "        Output: $OUT"
    ((FAIL++))
fi
# The clean PHP files in the same directory must NOT be flagged.
assert_output_not_contains "No version.php false positive alongside chameleon hit" "Filetype blacklisted (version.php)" "$OUT"
assert_output_not_contains "No lib.php false positive alongside chameleon hit" "Filetype blacklisted (lib.php)" "$OUT"
rm -rf "$CHAMDIR"
echo ""

echo "--- Test: No plugin name, no version.php -> exit 2 ---"
SCANDIR=$(mktemp -d)
OUT=$(cd "$SCANDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 2 when no version.php" 2 "$EC"
assert_output_contains "No version.php message" "no version.php found" "$OUT"
rm -rf "$SCANDIR"
echo ""

echo "--- Test: Missing config -> exit 2 with actionable message ---"
# If update-signatures hasn't been run, PhpMusselRunner should fail with
# a clear pointer at the fix, not phpMussel's generic "Unable to locate
# phpMussel's configuration file" exception.
BACKUP=$(mktemp -d)
if [ -f "$CONFIG" ]; then
    mv "$CONFIG" "$BACKUP/phpmussel.ini"
fi
MISCDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$MISCDIR/version.php"
OUT=$(cd "$MISCDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 2 when config is missing" 2 "$EC"
assert_output_contains "Points at update-signatures" "update-signatures" "$OUT"
rm -rf "$MISCDIR"
if [ -f "$BACKUP/phpmussel.ini" ]; then
    mv "$BACKUP/phpmussel.ini" "$CONFIG"
fi
rm -rf "$BACKUP"
echo ""

print_summary