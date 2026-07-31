#!/usr/bin/env bash
#
# Integration tests for moosh2 archive:dump and archive:restore.
# Requires a working Moodle 5.2 installation.
#
# Usage: MOODLE_DIR=/var/www/html/moodle52 bash tests/test_archive.sh
#

source "$(dirname "$0")/common.sh"

MOODLE_BASENAME="$(basename "${MOODLE_DIR}")"
DATAROOT="${DATAROOT:-/opt/data/$MOODLE_BASENAME}"

echo "=== moosh2 archive:dump / archive:restore integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

# Reset Moodle to a known state so the dump captures a predictable snapshot.
echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# ═══════════════════════════════════════════════════════════════════
#  archive:dump — argument & option handling
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: archive:dump help ---"
run_moosh archive:dump -p "$MOODLE_PATH" --help
assert_output_contains "Help shows description" "Bundle the Moodle codebase" "$OUT"
assert_output_contains "Help shows --code" "--code" "$OUT"
assert_output_contains "Help shows --files" "--files" "$OUT"
assert_output_contains "Help shows --db" "--db" "$OUT"
assert_output_contains "Help shows --description" "--description" "$OUT"
assert_output_contains "Help shows --exclude-code-paths" "--exclude-code-paths" "$OUT"
assert_output_contains "Help shows --overwrite" "--overwrite" "$OUT"
assert_output_contains "Help shows destination arg" "destination" "$OUT"
echo ""

echo "--- Test: archive:dump missing destination argument ---"
run_moosh archive:dump -p "$MOODLE_PATH"
EXIT_CODE=$?
assert_exit_code "Missing destination fails" 1 "$EXIT_CODE"
assert_output_contains "Argument error message" "destination" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:dump --db
# ═══════════════════════════════════════════════════════════════════

DB_ARCHIVE="$TMPDIR/db-only.tar.gz"

echo "--- Test: archive:dump --db creates archive ---"
run_moosh archive:dump -p "$MOODLE_PATH" --db "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
if [ -f "$DB_ARCHIVE" ] && [ -s "$DB_ARCHIVE" ]; then
    echo "  PASS: Archive file exists and is non-empty"
    ((PASS++))
else
    echo "  FAIL: Archive file missing or empty"
    ((FAIL++))
fi
echo ""

echo "--- Test: --db archive contains MANIFEST.yml + database.sql, no code/ or files/ ---"
ENTRIES=$(tar -tzf "$DB_ARCHIVE")
if echo "$ENTRIES" | grep -qE "^MANIFEST.yml$"; then
    echo "  PASS: Archive has MANIFEST.yml"
    ((PASS++))
else
    echo "  FAIL: MANIFEST.yml missing"
    ((FAIL++))
fi
if echo "$ENTRIES" | grep -qE "^database.sql$"; then
    echo "  PASS: Archive has database.sql"
    ((PASS++))
else
    echo "  FAIL: database.sql missing"
    ((FAIL++))
fi
if echo "$ENTRIES" | grep -q "^code/"; then
    echo "  FAIL: --db archive should not contain code/"
    ((FAIL++))
else
    echo "  PASS: No code/ in --db archive"
    ((PASS++))
fi
if echo "$ENTRIES" | grep -q "^files/"; then
    echo "  FAIL: --db archive should not contain files/"
    ((FAIL++))
else
    echo "  PASS: No files/ in --db archive"
    ((PASS++))
fi
echo ""

echo "--- Test: MANIFEST.yml records Moodle release / contents ---"
MANIFEST=$(tar -xzOf "$DB_ARCHIVE" MANIFEST.yml)
assert_output_contains "MANIFEST mentions archive:dump" "archive:dump" "$MANIFEST"
assert_output_contains "MANIFEST has Moodle release" "release:" "$MANIFEST"
assert_output_contains "MANIFEST flags db: true" "database: true" "$MANIFEST"
assert_output_contains "MANIFEST flags code: false" "code: false" "$MANIFEST"
assert_output_contains "MANIFEST flags files: false" "files: false" "$MANIFEST"
assert_output_contains "MANIFEST has db driver" "driver:" "$MANIFEST"
assert_output_contains "MANIFEST has db name" "moodle52" "$MANIFEST"
echo ""

echo "--- Test: database.sql contains expected schema ---"
# Verify the dump actually contains Moodle tables (search the whole dump,
# not just the head — table order is alphabetical so mdl_user comes late).
USER_TABLE_COUNT=$(tar -xzOf "$DB_ARCHIVE" database.sql | grep -c "mdl_user")
if [ "$USER_TABLE_COUNT" -gt 0 ]; then
    echo "  PASS: Dump references mdl_user ($USER_TABLE_COUNT occurrences)"
    ((PASS++))
else
    echo "  FAIL: Dump does not reference mdl_user"
    ((FAIL++))
fi
echo ""

echo "--- Test: Existing destination without --overwrite fails ---"
run_moosh archive:dump -p "$MOODLE_PATH" --db "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 1 when destination exists" 1 "$EXIT_CODE"
assert_output_contains "Overwrite hint" "--overwrite" "$OUT"
echo ""

echo "--- Test: --overwrite replaces destination ---"
run_moosh archive:dump -p "$MOODLE_PATH" --db --overwrite "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0 with --overwrite" 0 "$EXIT_CODE"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:dump --files
# ═══════════════════════════════════════════════════════════════════

FILES_ARCHIVE="$TMPDIR/files-only.tar.gz"

echo "--- Test: archive:dump --files ---"
run_moosh archive:dump -p "$MOODLE_PATH" --files "$FILES_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
ENTRIES=$(tar -tzf "$FILES_ARCHIVE")
if echo "$ENTRIES" | grep -q "^files/filedir/"; then
    echo "  PASS: Archive has files/filedir/"
    ((PASS++))
else
    echo "  FAIL: files/filedir/ missing"
    ((FAIL++))
fi
if echo "$ENTRIES" | grep -q "^files/cache/"; then
    echo "  FAIL: cache/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: cache/ correctly excluded"
    ((PASS++))
fi
if echo "$ENTRIES" | grep -q "^files/localcache/"; then
    echo "  FAIL: localcache/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: localcache/ correctly excluded"
    ((PASS++))
fi
if echo "$ENTRIES" | grep -q "^files/temp/"; then
    echo "  FAIL: temp/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: temp/ correctly excluded"
    ((PASS++))
fi
if echo "$ENTRIES" | grep -q "^files/sessions/"; then
    echo "  FAIL: sessions/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: sessions/ correctly excluded"
    ((PASS++))
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:dump --code with exclusions
# ═══════════════════════════════════════════════════════════════════

CODE_ARCHIVE="$TMPDIR/code-only.tar.gz"

echo "--- Test: archive:dump --code with exclusions ---"
# Heavy exclusions keep the archive small for the test.
run_moosh archive:dump -p "$MOODLE_PATH" --code \
    --exclude-code-paths='vendor,public,scripts,admin,lib,Gruntfile.js' \
    "$CODE_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
ENTRIES=$(tar -tzf "$CODE_ARCHIVE")
if echo "$ENTRIES" | grep -qE "^code/composer.json$"; then
    echo "  PASS: code/composer.json present"
    ((PASS++))
else
    echo "  FAIL: code/composer.json missing"
    ((FAIL++))
fi
if echo "$ENTRIES" | grep -q "^code/vendor/"; then
    echo "  FAIL: vendor/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: vendor/ excluded"
    ((PASS++))
fi
if echo "$ENTRIES" | grep -q "^code/public/"; then
    echo "  FAIL: public/ should be excluded"
    ((FAIL++))
else
    echo "  PASS: public/ excluded"
    ((PASS++))
fi
echo ""

echo "--- Test: --description recorded in MANIFEST ---"
DESCRIBED_ARCHIVE="$TMPDIR/described.tar.gz"
run_moosh archive:dump -p "$MOODLE_PATH" --db --description="moosh2 integration test snapshot" "$DESCRIBED_ARCHIVE"
MANIFEST=$(tar -xzOf "$DESCRIBED_ARCHIVE" MANIFEST.yml)
assert_output_contains "Description in MANIFEST" "moosh2 integration test snapshot" "$MANIFEST"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:dump (full = code + files + db)
# ═══════════════════════════════════════════════════════════════════

FULL_ARCHIVE="$TMPDIR/full.tar.gz"

echo "--- Test: archive:dump full (no flags = all components) ---"
# Exclude vendor + public to keep the test fast; the rest of the install
# is small enough that the archive stays under ~5MB.
run_moosh archive:dump -p "$MOODLE_PATH" \
    --exclude-code-paths='vendor,public,scripts,admin,lib,composer.phar' \
    "$FULL_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
ENTRIES=$(tar -tzf "$FULL_ARCHIVE")
echo "$ENTRIES" | grep -qE "^MANIFEST.yml$"   && { echo "  PASS: MANIFEST.yml"; ((PASS++)); }   || { echo "  FAIL: MANIFEST.yml missing"; ((FAIL++)); }
echo "$ENTRIES" | grep -qE "^database.sql$"   && { echo "  PASS: database.sql"; ((PASS++)); }   || { echo "  FAIL: database.sql missing"; ((FAIL++)); }
echo "$ENTRIES" | grep -q "^code/"            && { echo "  PASS: code/"; ((PASS++)); }          || { echo "  FAIL: code/ missing"; ((FAIL++)); }
echo "$ENTRIES" | grep -q "^files/filedir/"   && { echo "  PASS: files/filedir/"; ((PASS++)); } || { echo "  FAIL: files/filedir/ missing"; ((FAIL++)); }

MANIFEST=$(tar -xzOf "$FULL_ARCHIVE" MANIFEST.yml)
assert_output_contains "MANIFEST flags code: true" "code: true" "$MANIFEST"
assert_output_contains "MANIFEST flags files: true" "files: true" "$MANIFEST"
assert_output_contains "MANIFEST flags database: true" "database: true" "$MANIFEST"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore — argument & option handling
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: archive:restore help ---"
run_moosh archive:restore -p "$MOODLE_PATH" --help
assert_output_contains "Help description" "Restore Moodle codebase" "$OUT"
assert_output_contains "Help shows --code" "--code" "$OUT"
assert_output_contains "Help shows --files" "--files" "$OUT"
assert_output_contains "Help shows --db" "--db" "$OUT"
assert_output_contains "Help shows --code-destination" "--code-destination" "$OUT"
assert_output_contains "Help shows --files-destination" "--files-destination" "$OUT"
assert_output_contains "Help shows --overwrite" "--overwrite" "$OUT"
assert_output_contains "Help shows --run" "--run" "$OUT"
echo ""

echo "--- Test: archive:restore non-existent file ---"
run_moosh archive:restore -p "$MOODLE_PATH" /tmp/does-not-exist-zzz.tar.gz
EXIT_CODE=$?
assert_exit_code "Exit 1 for missing file" 1 "$EXIT_CODE"
assert_output_contains "Not found message" "not found" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore dry-run (default without --run)
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: archive:restore --db dry-run ---"
run_moosh archive:restore -p "$MOODLE_PATH" --db "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0 for dry-run" 0 "$EXIT_CODE"
assert_output_contains "Dry run banner" "Dry run" "$OUT"
assert_output_contains "Restores database yes" "Restore database:  yes" "$OUT"
assert_output_contains "Restores code no" "Restore code:      no" "$OUT"
assert_output_contains "Restores files no" "Restore files:     no" "$OUT"
echo ""

echo "--- Test: archive:restore (no flags) dry-run shows full plan ---"
run_moosh archive:restore -p "$MOODLE_PATH" "$FULL_ARCHIVE"
assert_output_contains "Dry run banner" "Dry run" "$OUT"
assert_output_contains "Restore code yes" "Restore code:      yes" "$OUT"
assert_output_contains "Restore files yes" "Restore files:     yes" "$OUT"
assert_output_contains "Restore database yes" "Restore database:  yes" "$OUT"
echo ""

echo "--- Test: archive:restore --code on db-only archive fails ---"
run_moosh archive:restore -p "$MOODLE_PATH" --code "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 1 when missing component requested" 1 "$EXIT_CODE"
assert_output_contains "Error mentions code" "no code/" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore --db --run (round-trip)
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: db round-trip — modify, restore, verify revert ---"
# Modify a value in the DB.
mysql -uroot -pa moodle52 -e "UPDATE mdl_user SET city='ARCHIVE_TEST_MODIFIED' WHERE username='admin';" 2>/dev/null
CITY_BEFORE=$(mysql -uroot -pa -N -B moodle52 -e "SELECT city FROM mdl_user WHERE username='admin';" 2>/dev/null)
if [ "$CITY_BEFORE" = "ARCHIVE_TEST_MODIFIED" ]; then
    echo "  PASS: City modified before restore"
    ((PASS++))
else
    echo "  FAIL: Could not modify city (got: '$CITY_BEFORE')"
    ((FAIL++))
fi

run_moosh archive:restore -p "$MOODLE_PATH" --db --run "$DB_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0 for restore" 0 "$EXIT_CODE"
assert_output_contains "Restore confirmation" "Restored from" "$OUT"

CITY_AFTER=$(mysql -uroot -pa -N -B moodle52 -e "SELECT city FROM mdl_user WHERE username='admin';" 2>/dev/null)
if [ "$CITY_AFTER" != "ARCHIVE_TEST_MODIFIED" ]; then
    echo "  PASS: City reverted after restore (now: '$CITY_AFTER')"
    ((PASS++))
else
    echo "  FAIL: City still modified after restore"
    ((FAIL++))
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore --files --run --overwrite (round-trip)
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: files round-trip — add marker, restore, verify removal ---"
MARKER="$DATAROOT/filedir/MOOSH_ARCHIVE_TEST_MARKER.tmp"
echo "marker" > "$MARKER"
if [ -f "$MARKER" ]; then
    echo "  PASS: Marker file created"
    ((PASS++))
else
    echo "  FAIL: Could not create marker"
    ((FAIL++))
fi

run_moosh archive:restore -p "$MOODLE_PATH" --files --run --overwrite "$FILES_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0 for restore" 0 "$EXIT_CODE"

if [ ! -f "$MARKER" ]; then
    echo "  PASS: Marker removed by restore (destination wiped)"
    ((PASS++))
else
    echo "  FAIL: Marker still present after restore"
    ((FAIL++))
fi
echo ""

echo "--- Test: --files restore without --overwrite fails on non-empty dest ---"
# Files were just restored; destination is non-empty. Without --overwrite,
# the command must refuse.
run_moosh archive:restore -p "$MOODLE_PATH" --files --run "$FILES_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 1 without --overwrite" 1 "$EXIT_CODE"
assert_output_contains "Overwrite hint" "--overwrite" "$OUT"
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore --code --code-destination --run
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: --code-destination puts code at custom path ---"
CODE_RESTORE_DIR="$TMPDIR/code-restore"
run_moosh archive:restore -p "$MOODLE_PATH" --code --run \
    --code-destination="$CODE_RESTORE_DIR" \
    "$CODE_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
if [ -f "$CODE_RESTORE_DIR/composer.json" ]; then
    echo "  PASS: composer.json restored to custom destination"
    ((PASS++))
else
    echo "  FAIL: composer.json missing from $CODE_RESTORE_DIR"
    ((FAIL++))
fi
echo ""

# ═══════════════════════════════════════════════════════════════════
#  archive:restore selective restore from full archive
# ═══════════════════════════════════════════════════════════════════

echo "--- Test: --db only on full archive restores just the database ---"
mysql -uroot -pa moodle52 -e "UPDATE mdl_user SET city='SELECTIVE_TEST' WHERE username='admin';" 2>/dev/null
run_moosh archive:restore -p "$MOODLE_PATH" --db --run "$FULL_ARCHIVE"
EXIT_CODE=$?
assert_exit_code "Exit 0" 0 "$EXIT_CODE"
CITY_AFTER=$(mysql -uroot -pa -N -B moodle52 -e "SELECT city FROM mdl_user WHERE username='admin';" 2>/dev/null)
if [ "$CITY_AFTER" != "SELECTIVE_TEST" ]; then
    echo "  PASS: DB restored from full archive (city='$CITY_AFTER')"
    ((PASS++))
else
    echo "  FAIL: DB not restored"
    ((FAIL++))
fi
echo ""

print_summary
