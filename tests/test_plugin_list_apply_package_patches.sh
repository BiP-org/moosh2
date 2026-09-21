#!/usr/bin/env bash
#
# Integration test for moosh2 plugin:list-apply's patching of package_*
# components, ported from install_plugins.php's package_install() (which
# patches a package with the same get_patches()/are_patches_applied()/
# apply_patches() machinery as a plain plugin).
#
# Uses a self-contained fake package (no network): package_mooshpk bundles
# two plugin directories, local/mooshpka and local/mooshpkb, and its
# bin/install_requested_version.sh writes both from scratch (replacing
# them, as a package install script has to - see the class docblock of
# PluginListApply52Handler, "Package patching").
#
# Package patch paths are relative to the Moodle REPOSITORY root, i.e. the
# parent of public/ with the split layout - this test builds them from
# $MOODLE_DIR/$MOODLE_PATH so it works with either layout.
#
# Requires a working Moodle 5.2 installation at $MOODLE_DIR (see common.sh).
#
# Usage: bash tests/test_plugin_list_apply_package_patches.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 plugin:list-apply package_* patching integration tests ==="
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

# Path of the Moodle root ($CFG->dirroot) relative to the repository root:
# "public" with the split layout, "." otherwise. Patch paths start with it.
REL=$(realpath --relative-to="$MOODLE_DIR" "$MOODLE_PATH")
if [ "$REL" = "." ]; then PFX=""; else PFX="$REL/"; fi

PKGDIR=$(mktemp -d)
PK="$PKGDIR/package_mooshpk"
INSTALLS="$PKGDIR/installs.log"
A="$MOODLE_PATH/local/mooshpka"
B="$MOODLE_PATH/local/mooshpkb"
FP="$A/.patches-applied"
trap 'sudo rm -rf "$A" "$B" 2>/dev/null; rm -rf "$PKGDIR"; _moosh_test_release_lock' EXIT

sudo rm -rf "$A" "$B" 2>/dev/null
mkdir -p "$PK/bin"
echo "2024010100" > "$PK/version"

cat > "$PK/bin/get_requested_version.sh" <<'SH'
#!/bin/bash
cat "$(dirname "$0")/../version"
SH
cat > "$PK/bin/get_component_path.sh" <<'SH'
#!/bin/bash
echo "local/mooshpka"
SH
cat > "$PK/bin/get_component_ignore_path.sh" <<'SH'
#!/bin/bash
echo "local/mooshpka"
echo "local/mooshpkb"
SH
cat > "$PK/bin/get_installed_version.sh" <<'SH'
#!/bin/bash
if [ -f local/mooshpka/.pkgver ]; then cat local/mooshpka/.pkgver; else echo -1; fi
SH
cat > "$PK/bin/uninstall_requested_version.sh" <<'SH'
#!/bin/bash
rm -rf local/mooshpka local/mooshpkb
SH
# Replaces (never merges into) both plugin directories - the contract.
cat > "$PK/bin/install_requested_version.sh" <<SH
#!/bin/bash
set -e
echo run >> "$INSTALLS"
rm -rf local/mooshpka local/mooshpkb
for p in a b; do
    d=local/mooshpk\$p
    mkdir -p \$d/lang/en
    cat > \$d/version.php <<PHP
<?php
defined('MOODLE_INTERNAL') || die();
\\\$plugin->component = 'local_mooshpk\$p';
\\\$plugin->version = \$2;
\\\$plugin->requires = 2020110900;
\\\$plugin->maturity = MATURITY_STABLE;
\\\$plugin->release = '1.0';
PHP
    cat > \$d/lang/en/local_mooshpk\$p.php <<PHP
<?php
defined('MOODLE_INTERNAL') || die();
\\\$string['pluginname'] = 'Moosh package test \$p';
PHP
    printf 'line1\nline2\n' > \$d/lib.php
done
echo "\$2" > local/mooshpka/.pkgver
SH
chmod +x "$PK"/bin/*.sh

# A patch for one of the package's directories, in repository-root-relative form.
make_patch() { # $1 = a|b  $2 = line to add
    cat <<PATCH
diff --git a/${PFX}local/mooshpk$1/lib.php b/${PFX}local/mooshpk$1/lib.php
--- a/${PFX}local/mooshpk$1/lib.php
+++ b/${PFX}local/mooshpk$1/lib.php
@@ -1,2 +1,3 @@
 line1
 line2
+$2
PATCH
}

apply_pkg() { run_moosh plugin:list-apply -p "$MOODLE_PATH" --directory="$PKGDIR" "$@" package_mooshpk; }
installs() { [ -f "$INSTALLS" ] && grep -c '^run$' "$INSTALLS" || echo 0; }
content_is() { # $1 file  $2 expected (printf format)
    [ "$(cat "$1" 2>/dev/null)" = "$(printf "$2")" ]
}

# ── #5: fresh install applies package patches to every directory ──

make_patch a "patched-a" > "$PK/01-a.patch"
make_patch b "patched-b" > "$PK/02-b.patch"

echo "--- Test: dry run of a not yet installed, patched package installs nothing ---"
apply_pkg
assert_output_contains "Previews the install" "WOULD INSTALL package_mooshpk" "$OUT"
if [ ! -d "$A" ]; then
    echo "  PASS: dry run wrote nothing"
    ((PASS++))
else
    echo "  FAIL: dry run installed the package"
    ((FAIL++))
fi
echo ""

echo "--- Test: fresh install applies the patches to both plugin directories ---"
reset_cache_definitions
apply_pkg --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Reports the patches" "INSTALLED package_mooshpk" "$OUT"
assert_output_contains "Mentions the local patches" "(including local patches)" "$OUT"
assert_output_contains "Applies patch 1" "Applying patch 01-a.patch to package_mooshpk" "$OUT"
assert_output_contains "Applies patch 2" "Applying patch 02-b.patch to package_mooshpk" "$OUT"
if content_is "$A/lib.php" 'line1\nline2\npatched-a'; then
    echo "  PASS: first plugin directory patched"; ((PASS++))
else
    echo "  FAIL: first plugin directory not patched"; ((FAIL++))
fi
if content_is "$B/lib.php" 'line1\nline2\npatched-b'; then
    echo "  PASS: second plugin directory of the package patched too"; ((PASS++))
else
    echo "  FAIL: second plugin directory not patched"; ((FAIL++))
fi
if [ -f "$FP" ] && [ "$(cat "$FP")" != "incomplete" ]; then
    echo "  PASS: patch fingerprint written to the package's anchor directory"; ((PASS++))
else
    echo "  FAIL: patch fingerprint missing or still 'incomplete'"; ((FAIL++))
fi
echo ""

# ── nothing changed: nothing happens ─────────────────────────────

echo "--- Test: unchanged patches - already at, install script not run again ---"
N=$(installs)
apply_pkg --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Reports already at, including patches" "OK      package_mooshpk: already at 2024010100 (including local patches)" "$OUT"
assert_output_not_contains "Applies nothing" "Applying patch" "$OUT"
if [ "$(installs)" = "$N" ]; then
    echo "  PASS: bin/install_requested_version.sh not run again"; ((PASS++))
else
    echo "  FAIL: install script ran again although nothing changed"; ((FAIL++))
fi
echo ""

# ── patch changed ────────────────────────────────────────────────

make_patch a "patched-a-v2" > "$PK/01-a.patch"

echo "--- Test: changed patch - dry run reports it, changes nothing ---"
apply_pkg
assert_output_contains "Reports the re-apply" "WOULD REAPPLY PATCHES package_mooshpk" "$OUT"
if [ "$(installs)" = "$N" ] && content_is "$A/lib.php" 'line1\nline2\npatched-a'; then
    echo "  PASS: dry run changed nothing"; ((PASS++))
else
    echo "  FAIL: dry run touched the package"; ((FAIL++))
fi
echo ""

echo "--- Test: changed patch - install script runs again, current patches applied to the fresh code ---"
reset_cache_definitions
apply_pkg --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_contains "Says why it reinstalls" "local patches changed" "$OUT"
if [ "$(installs)" = "$((N + 1))" ]; then
    echo "  PASS: install script ran once more"; ((PASS++))
else
    echo "  FAIL: install script run count is $(installs), expected $((N + 1))"; ((FAIL++))
fi
if content_is "$A/lib.php" 'line1\nline2\npatched-a-v2'; then
    echo "  PASS: new patch applied to fresh code (old patch content gone)"; ((PASS++))
else
    echo "  FAIL: first plugin directory has the wrong content after the patch changed"; ((FAIL++))
fi
if content_is "$B/lib.php" 'line1\nline2\npatched-b'; then
    echo "  PASS: unchanged second patch applied again"; ((PASS++))
else
    echo "  FAIL: second plugin directory has the wrong content"; ((FAIL++))
fi
echo ""

# ── patch that does not apply ────────────────────────────────────

echo "--- Test: a patch that does not apply fails the component and is retried next run ---"
cat > "$PK/03-bad.patch" <<PATCH
diff --git a/${PFX}local/mooshpka/lib.php b/${PFX}local/mooshpka/lib.php
--- a/${PFX}local/mooshpka/lib.php
+++ b/${PFX}local/mooshpka/lib.php
@@ -1,2 +1,3 @@
 nomatch1
 nomatch2
+x
PATCH
apply_pkg --run
EC=$?
assert_exit_code "Non-zero exit" 1 "$EC"
assert_output_contains "Names the failing patch" "applying patch 03-bad.patch to package_mooshpk failed" "$OUT"
if [ "$(cat "$FP" 2>/dev/null)" = "incomplete" ]; then
    echo "  PASS: fingerprint says incomplete - the package does not look patched"; ((PASS++))
else
    echo "  FAIL: fingerprint is not 'incomplete' after a failed patch"; ((FAIL++))
fi
rm -f "$PK/03-bad.patch"
apply_pkg --run
EC=$?
assert_exit_code "Next run repairs it" 0 "$EC"
assert_output_contains "Reinstalled with the good patches" "INSTALLED package_mooshpk" "$OUT"
echo ""

# ── patch paths relative to the wrong root ───────────────────────

if [ -n "$PFX" ]; then
    echo "--- Test: patch paths relative to \$CFG->dirroot instead of the repository root fail loudly ---"
    cat > "$PK/03-wrongroot.patch" <<PATCH
diff --git a/local/mooshpka/lib.php b/local/mooshpka/lib.php
--- a/local/mooshpka/lib.php
+++ b/local/mooshpka/lib.php
@@ -1,2 +1,3 @@
 line1
 line2
+wrong-root
PATCH
    apply_pkg --run
    EC=$?
    assert_exit_code "Non-zero exit" 1 "$EC"
    assert_output_contains "Names the failing patch" "03-wrongroot.patch" "$OUT"
    rm -f "$PK/03-wrongroot.patch"
    apply_pkg --run
    echo ""
fi

# ── patches removed ──────────────────────────────────────────────

echo "--- Test: all patches removed - reinstalled without them, fingerprint gone ---"
rm -f "$PK"/*.patch
apply_pkg --run
EC=$?
assert_exit_code "Exit code 0" 0 "$EC"
assert_output_not_contains "No longer reports patches" "including local patches" "$OUT"
if content_is "$A/lib.php" 'line1\nline2' && content_is "$B/lib.php" 'line1\nline2' && [ ! -e "$FP" ]; then
    echo "  PASS: code pristine again, no fingerprint left"; ((PASS++))
else
    echo "  FAIL: patched code or fingerprint survived the removal of all patches"; ((FAIL++))
fi
echo ""

# ── no patches: behaviour unchanged ──────────────────────────────

echo "--- Test: a package without patches behaves exactly as before ---"
N=$(installs)
apply_pkg --run
assert_output_contains "Already at, no patch suffix" "OK      package_mooshpk: already at 2024010100" "$OUT"
assert_output_not_contains "No patch suffix" "including local patches" "$OUT"
if [ "$(installs)" = "$N" ] && [ ! -e "$FP" ]; then
    echo "  PASS: no reinstall, no fingerprint file created"; ((PASS++))
else
    echo "  FAIL: an unpatched package was reinstalled or got a fingerprint"; ((FAIL++))
fi
echo ""

echo "--- Cleaning up ---"
sudo rm -rf "$A" "$B" 2>/dev/null
reset_cache_definitions
echo ""

bash "$SCRIPT_DIR/clear.sh"

print_summary
