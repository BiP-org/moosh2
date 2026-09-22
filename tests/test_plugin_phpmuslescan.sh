#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:phpmuslescan
#

source "$(dirname "$0")/common.sh"

# Name of the per-plugin whitelist file (PhpMusselRunner::WHITELIST_FILENAME).
WHITELIST_FILENAME=".moosh-phpmuslescan-whitelist"

echo "=== moosh2 plugin:phpmuslescan integration tests ==="
echo ""

echo "--- Test: Help ---"
run_moosh plugin:phpmuslescan --help
assert_output_contains "Help description" "Scan a plugin for malware using phpMussel" "$OUT"
assert_output_contains "Help shows --infected" "--infected" "$OUT"
assert_output_contains "Help shows --log" "--log" "$OUT"
assert_output_contains "Help shows --whitelist" "--whitelist" "$OUT"
assert_output_contains "Help mentions the whitelist filename" ".moosh-phpmuslescan-whitelist" "$OUT"
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

echo "--- Test: Dotfile false positive -> exit 1 (Filename manipulation detected) ---"
# phpMussel's filename-manipulation heuristic fires on a name with nothing
# before the first "." (a dotfile reads as an all-extension filename).
# Moodle plugins legitimately ship dotfiles (.htaccess being the classic
# one), so this is a real, reproducible false positive — not a real
# threat — and is exactly what the per-plugin whitelist exists for.
FPDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$FPDIR/version.php"
echo '<?php echo "hello";' > "$FPDIR/lib.php"
echo 'deny from all' > "$FPDIR/.htaccess"

OUT=$(cd "$FPDIR" && $PHP $MOOSH plugin:phpmuslescan -i 2>&1)
EC=$?
assert_exit_code "Exit code 1 for the unwhitelisted dotfile false positive" 1 "$EC"
assert_output_contains "Reports the dotfile" ".htaccess" "$OUT"
if echo "$OUT" | grep -qi "filename manipulation"; then
    echo "  PASS: reports filename manipulation detected"
    ((PASS++))
else
    echo "  FAIL: does not report filename manipulation detected"
    echo "        Output: $OUT"
    ((FAIL++))
fi
echo ""

echo "--- Test: Per-plugin whitelist file suppresses the false positive ---"
# A .moosh-phpmuslescan-whitelist in the plugin root, matching the
# offending file by exact relative path, should skip it entirely: exit 0,
# zero infections, and the file listed as whitelisted rather than scanned.
echo ".htaccess" > "$FPDIR/.moosh-phpmuslescan-whitelist"

OUT=$(cd "$FPDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0 once .htaccess is whitelisted" 0 "$EC"
assert_output_contains "Reports the whitelisted file" "Whitelisted ($WHITELIST_FILENAME)" "$OUT"
assert_output_contains "Names .htaccess as whitelisted" ".htaccess" "$OUT"
assert_output_not_contains "No filename-manipulation hit once whitelisted" "Filename manipulation" "$OUT"
if echo "$OUT" | grep -q "Infected files: 0"; then
    echo "  PASS: scan summary reports zero infections once whitelisted"
    ((PASS++))
else
    echo "  FAIL: scan summary does not report zero infections once whitelisted"
    echo "        Output: $OUT"
    ((FAIL++))
fi
echo ""

echo "--- Test: Whitelist glob pattern matches ---"
# A glob (not just an exact filename) should also suppress the hit —
# confirms fnmatch() patterns work, not just literal names.
GLOBDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$GLOBDIR/version.php"
mkdir "$GLOBDIR/thirdparty"
echo 'deny from all' > "$GLOBDIR/thirdparty/.htaccess"
echo "thirdparty/*" > "$GLOBDIR/.moosh-phpmuslescan-whitelist"

OUT=$(cd "$GLOBDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0 with a glob-whitelisted dotfile" 0 "$EC"
assert_output_contains "Reports the glob-whitelisted file" "thirdparty/.htaccess" "$OUT"
rm -rf "$GLOBDIR"
echo ""

echo "--- Test: --whitelist option, without a file in the plugin root ---"
# Simulates scanning a plugin you can't drop a whitelist file into (e.g.
# a freshly downloaded one): the same false positive, suppressed purely
# via an external --whitelist file.
EXTWLDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$EXTWLDIR/version.php"
echo 'deny from all' > "$EXTWLDIR/.htaccess"
EXTWL="$EXTWLDIR/external-whitelist.txt"
echo "# external whitelist, not in the plugin root" > "$EXTWL"
echo ".htaccess" >> "$EXTWL"

OUT=$(cd "$EXTWLDIR" && $PHP $MOOSH plugin:phpmuslescan --whitelist="$EXTWL" 2>&1)
EC=$?
assert_exit_code "Exit code 0 using --whitelist alone" 0 "$EC"
assert_output_contains "Reports the file whitelisted via --whitelist" "Whitelisted (--whitelist)" "$OUT"
rm -rf "$EXTWLDIR"
echo ""

echo "--- Test: Unrelated dotfile stays flagged (whitelist doesn't over-match) ---"
# A whitelist entry for .htaccess must not accidentally suppress a
# different dotfile false positive in the same plugin.
echo 'deny from all' > "$FPDIR/.another"
OUT=$(cd "$FPDIR" && $PHP $MOOSH plugin:phpmuslescan -i 2>&1)
EC=$?
assert_exit_code "Exit code 1: unwhitelisted dotfile still flagged" 1 "$EC"
assert_output_contains "Reports the unwhitelisted dotfile" ".another" "$OUT"
rm -f "$FPDIR/.another"
rm -rf "$FPDIR"
echo ""

echo "--- Test: Built-in whitelist suppresses moosh2's own marker file ---"
# .downloaded-non-core-plugin is a marker file moosh2 itself touches in
# every plugin it manages via plugin:list-apply (see MARKER_FILENAME in
# PluginListApply52Handler). It's a dotfile, so it trips the same
# filename-manipulation heuristic as .htaccess above -- but this one is
# whitelisted BUILT IN, with no whitelist file needed at all.
MARKDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$MARKDIR/version.php"
touch "$MARKDIR/.downloaded-non-core-plugin"

OUT=$(cd "$MARKDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0: built-in whitelist needs no config" 0 "$EC"
# This is a scoped built-in entry (pattern | reason), so it's applied
# post-scan and reported inline as WHITELISTED, not in the pre-scan
# "Whitelisted (source): N file(s)" header (that's for whole-file entries).
assert_output_contains "Reports it as WHITELISTED via built-in" "WHITELISTED: .downloaded-non-core-plugin" "$OUT"
assert_output_contains "Names the built-in source" "via built-in" "$OUT"
assert_output_not_contains "No plain INFECTED line for the marker file" "INFECTED: .downloaded-non-core-plugin" "$OUT"
rm -rf "$MARKDIR"
echo ""

echo "--- Test: Global whitelist (~/.moosh2/phpmuslescan-whitelist) applies across plugins ---"
# Unlike the per-plugin file, the global whitelist lives outside any
# plugin and is picked up automatically for every scan -- no --whitelist
# flag, no per-plugin file.
GLOBALWL="${HOME}/.moosh2/phpmuslescan-whitelist"
GLOBALWL_BACKUP=$(mktemp -d)
if [ -f "$GLOBALWL" ]; then
    mv "$GLOBALWL" "$GLOBALWL_BACKUP/phpmuslescan-whitelist"
fi
mkdir -p "$(dirname "$GLOBALWL")"
echo ".htaccess" > "$GLOBALWL"

GWLDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$GWLDIR/version.php"
echo 'deny from all' > "$GWLDIR/.htaccess"

OUT=$(cd "$GWLDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0: global whitelist applies with no per-plugin config" 0 "$EC"
assert_output_contains "Reports the global whitelist source" "Whitelisted (global)" "$OUT"
rm -rf "$GWLDIR"
rm -f "$GLOBALWL"
if [ -f "$GLOBALWL_BACKUP/phpmuslescan-whitelist" ]; then
    mv "$GLOBALWL_BACKUP/phpmuslescan-whitelist" "$GLOBALWL"
fi
rm -rf "$GLOBALWL_BACKUP"
echo ""

echo "--- Test: Built-in whitelist also covers tests/behat/*.feature chameleon hits ---"
# The third built-in entry, verified on its own before the per-plugin
# scoping tests below (which deliberately use a DIFFERENT path so they
# aren't accidentally passing via this built-in entry instead of their
# own per-plugin whitelist file).
BEHATDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$BEHATDIR/version.php"
mkdir -p "$BEHATDIR/tests/behat"
printf '<?php echo "not really gherkin"; ?>' > "$BEHATDIR/tests/behat/scenario.feature"

OUT=$(cd "$BEHATDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0: built-in tests/behat entry, no config needed" 0 "$EC"
assert_output_contains "Reports it as WHITELISTED via built-in" "WHITELISTED: tests/behat/scenario.feature" "$OUT"
assert_output_contains "Names the built-in source" "via built-in" "$OUT"
rm -rf "$BEHATDIR"
echo ""

echo "--- Test: Per-plugin scoped whitelist (pattern | reason) suppresses only that detection ---"
# "pattern | reason" whitelists a specific detection on matching files,
# not the whole file -- unlike a bare pattern. Uses a path NOT covered by
# any built-in entry (custom/behat/, not tests/behat/) so this genuinely
# exercises the per-plugin whitelist file, not the built-in one.
SCOPEDIR=$(mktemp -d)
echo '<?php $plugin->version = 1;' > "$SCOPEDIR/version.php"
mkdir -p "$SCOPEDIR/custom/behat"
printf '<?php echo "not really gherkin"; ?>' > "$SCOPEDIR/custom/behat/scenario.feature"
echo 'custom/behat/*.feature | PHP chameleon attack' > "$SCOPEDIR/$WHITELIST_FILENAME"

OUT=$(cd "$SCOPEDIR" && $PHP $MOOSH plugin:phpmuslescan 2>&1)
EC=$?
assert_exit_code "Exit code 0: scoped whitelist suppresses the chameleon hit" 0 "$EC"
assert_output_contains "Reports it as WHITELISTED, not INFECTED" "WHITELISTED: custom/behat/scenario.feature" "$OUT"
assert_output_not_contains "No plain INFECTED line for the scoped file" "INFECTED: custom/behat/scenario.feature" "$OUT"
assert_output_contains "Names the per-plugin whitelist source" "via $WHITELIST_FILENAME" "$OUT"
echo ""

echo "--- Test: Scoped whitelist reason must actually match, or the file still fires ---"
# Same file, same path pattern, but the whitelist entry's reason text
# doesn't occur in phpMussel's message -- so this must NOT be suppressed.
# Confirms scoping isn't secretly a blanket per-path whitelist.
echo 'custom/behat/*.feature | some unrelated signature that will never match' > "$SCOPEDIR/$WHITELIST_FILENAME"

OUT=$(cd "$SCOPEDIR" && $PHP $MOOSH plugin:phpmuslescan -i 2>&1)
EC=$?
assert_exit_code "Exit code 1: non-matching reason does not suppress the hit" 1 "$EC"
assert_output_contains "Still reports the file as infected" "scenario.feature" "$OUT"
rm -rf "$SCOPEDIR"
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