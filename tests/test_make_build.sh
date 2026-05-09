#!/usr/bin/env bash
#
# Integration test for moosh2 make:build
#
# This command does not bootstrap Moodle (BootstrapLevel::None), but we still
# source common.sh for the test-run lock and helpers. Fixtures (fake "Moodle"
# core repo, fake plugin repo, fake plugin zip) are constructed locally so the
# test runs offline.
#
# Usage: MOODLE_DIR=/path/to/moodle bash tests/test_make_build.sh
#

source "$(dirname "$0")/common.sh"

WORK=$(mktemp -d /tmp/moosh-make-XXXXXX)
DEST="$WORK/build"
MANIFEST="$WORK/site.make"
BAD_MANIFEST="$WORK/bad.make"

cleanup() {
    rm -rf "$WORK"
    _moosh_test_release_lock
}
trap cleanup EXIT

# ── Build fake fixtures ───────────────────────────────────────────

# Fake Moodle "core" git repo (bare). The working copy contains a public/ tree
# so plugin destinations can be created beneath it.
git init --quiet --bare "$WORK/fake-moodle.git"
git init --quiet "$WORK/fake-moodle"
mkdir -p "$WORK/fake-moodle/public/blocks" \
         "$WORK/fake-moodle/public/local" \
         "$WORK/fake-moodle/public/theme"
echo "<?php // fake moodle core" > "$WORK/fake-moodle/public/version.php"
git -C "$WORK/fake-moodle" add . > /dev/null
git -C "$WORK/fake-moodle" -c user.email=t@t -c user.name=t commit --quiet -m init
git -C "$WORK/fake-moodle" branch -M MOODLE_502_STABLE
git -C "$WORK/fake-moodle" remote add origin "$WORK/fake-moodle.git"
git -C "$WORK/fake-moodle" push --quiet -u origin MOODLE_502_STABLE

# Fake plugin git repo (bare).
git init --quiet --bare "$WORK/fake-plugin.git"
git init --quiet "$WORK/fake-plugin"
echo "<?php // fakeblock plugin" > "$WORK/fake-plugin/version.php"
echo "MARKER_FROM_GIT" > "$WORK/fake-plugin/marker.txt"
git -C "$WORK/fake-plugin" add . > /dev/null
git -C "$WORK/fake-plugin" -c user.email=t@t -c user.name=t commit --quiet -m init
git -C "$WORK/fake-plugin" branch -M main
git -C "$WORK/fake-plugin" remote add origin "$WORK/fake-plugin.git"
git -C "$WORK/fake-plugin" push --quiet -u origin main

# Fake plugin zip — exactly one top-level dir, containing a version.php and a marker.
mkdir -p "$WORK/zipsrc/codecheck"
echo "<?php // codecheck plugin" > "$WORK/zipsrc/codecheck/version.php"
echo "MARKER_FROM_ZIP" > "$WORK/zipsrc/codecheck/marker.txt"
( cd "$WORK/zipsrc" && zip -qr "$WORK/fake-plugin.zip" codecheck )

# ── Manifest ──────────────────────────────────────────────────────

cat > "$MANIFEST" <<EOF
; moosh make manifest for the integration test
api = 1

[core]
version = 5.2
git    = $WORK/fake-moodle.git
branch = MOODLE_502_STABLE

[block_fakeblock]
git    = $WORK/fake-plugin.git
branch = main

[local_codecheck]
zip = file://$WORK/fake-plugin.zip
EOF

echo "=== moosh2 make:build integration tests ==="
echo "Workdir: $WORK"
echo ""

# ── Dry run ───────────────────────────────────────────────────────

echo "--- Test: Dry-run prints plan, writes nothing ---"
run_moosh make:build "$MANIFEST" "$DEST"
EXIT=$?
assert_exit_code "Dry-run exits 0" 0 "$EXIT"
assert_output_contains "DRY RUN banner shown" "DRY RUN" "$OUT"
assert_output_contains "Core line shown" "Core: $WORK/fake-moodle.git @ MOODLE_502_STABLE" "$OUT"
assert_output_contains "block_fakeblock target" "$DEST/public/blocks/fakeblock" "$OUT"
assert_output_contains "local_codecheck target" "$DEST/public/local/codecheck" "$OUT"
if [ -d "$DEST" ]; then
    echo "  FAIL: Dry-run created $DEST"
    ((FAIL++))
else
    echo "  PASS: Dry-run did not create destination"
    ((PASS++))
fi
echo ""

# ── Real build ────────────────────────────────────────────────────

echo "--- Test: --run assembles core + plugins ---"
run_moosh make:build "$MANIFEST" "$DEST" --run
EXIT=$?
assert_exit_code "Build exits 0" 0 "$EXIT"

if [ -f "$DEST/public/version.php" ]; then
    echo "  PASS: Core checked out (public/version.php exists)"
    ((PASS++))
else
    echo "  FAIL: Core not checked out"
    ((FAIL++))
fi

if [ -f "$DEST/public/blocks/fakeblock/marker.txt" ]; then
    MARKER=$(cat "$DEST/public/blocks/fakeblock/marker.txt")
    if [ "$MARKER" = "MARKER_FROM_GIT" ]; then
        echo "  PASS: block_fakeblock placed via git source"
        ((PASS++))
    else
        echo "  FAIL: block_fakeblock marker is '$MARKER' (expected MARKER_FROM_GIT)"
        ((FAIL++))
    fi
else
    echo "  FAIL: block_fakeblock not at expected path"
    ((FAIL++))
fi

if [ -f "$DEST/public/local/codecheck/marker.txt" ]; then
    MARKER=$(cat "$DEST/public/local/codecheck/marker.txt")
    if [ "$MARKER" = "MARKER_FROM_ZIP" ]; then
        echo "  PASS: local_codecheck placed via zip source"
        ((PASS++))
    else
        echo "  FAIL: local_codecheck marker is '$MARKER' (expected MARKER_FROM_ZIP)"
        ((FAIL++))
    fi
else
    echo "  FAIL: local_codecheck not at expected path"
    ((FAIL++))
fi
echo ""

# ── Idempotency / safety ──────────────────────────────────────────

echo "--- Test: Re-running --run on a non-empty destination errors out ---"
run_moosh make:build "$MANIFEST" "$DEST" --run
EXIT=$?
assert_exit_code "Re-run exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions non-empty destination" "not empty" "$OUT"
echo ""

# ── Manifest error paths ──────────────────────────────────────────

echo "--- Test: Missing manifest fails cleanly ---"
run_moosh make:build "$WORK/no-such.make" "$WORK/dest2"
EXIT=$?
assert_exit_code "Missing manifest exits non-zero" 1 "$EXIT"
assert_output_contains "Error names the missing manifest" "no-such.make" "$OUT"
echo ""

echo "--- Test: Manifest without [core] is rejected ---"
cat > "$BAD_MANIFEST" <<'EOF'
api = 1

[mod_attendance]
EOF
run_moosh make:build "$BAD_MANIFEST" "$WORK/dest3"
EXIT=$?
assert_exit_code "Bad manifest exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions [core]" "[core]" "$OUT"
echo ""

echo "--- Test: Manifest with unknown plugin type is rejected ---"
cat > "$BAD_MANIFEST" <<'EOF'
api = 1

[core]
version = 5.2

[bogus_thing]
EOF
run_moosh make:build "$BAD_MANIFEST" "$WORK/dest4"
EXIT=$?
assert_exit_code "Unknown plugin type exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions unknown plugin type" "Unknown plugin type" "$OUT"
echo ""

echo "--- Test: Manifest with both git and zip is rejected ---"
cat > "$BAD_MANIFEST" <<EOF
api = 1

[core]
version = 5.2

[local_x]
git = $WORK/fake-plugin.git
zip = file://$WORK/fake-plugin.zip
EOF
run_moosh make:build "$BAD_MANIFEST" "$WORK/dest5"
EXIT=$?
assert_exit_code "Both git+zip exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions git+zip conflict" "both 'git' and 'zip'" "$OUT"
echo ""

echo "--- Test: Manifest with version+git is rejected ---"
cat > "$BAD_MANIFEST" <<EOF
api = 1

[core]
version = 5.2

[local_x]
git = $WORK/fake-plugin.git
version = 2024010100
EOF
run_moosh make:build "$BAD_MANIFEST" "$WORK/dest6"
EXIT=$?
assert_exit_code "version+git exits non-zero" 1 "$EXIT"
assert_output_contains "Error mentions version+git/zip conflict" "alongside 'git'/'zip'" "$OUT"
echo ""

# ── Help ──────────────────────────────────────────────────────────

echo "--- Test: Help output ---"
run_moosh make:build --help
assert_output_contains "Help shows description" "drush make" "$OUT"
assert_output_contains "Help shows manifest argument" "manifest" "$OUT"
assert_output_contains "Help shows destination argument" "destination" "$OUT"
assert_output_contains "Help shows --proxy option" "--proxy" "$OUT"
assert_output_contains "Help shows manifest format example" "[core]" "$OUT"
assert_output_contains "Help mentions --run" "--run" "$OUT"
echo ""

print_summary
