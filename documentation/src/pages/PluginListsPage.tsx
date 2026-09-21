import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { CodeBlock } from '@/components/CodeBlock';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

function InlineCode({ children }: { children: string }) {
  return <code className="bg-muted px-1.5 py-0.5 rounded text-sm">{children}</code>;
}

function Note({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm space-y-1">
      <p className="font-medium text-amber-700 dark:text-amber-400">{title}</p>
      <div className="text-muted-foreground">{children}</div>
    </div>
  );
}

export function PluginListsPage() {
  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-bold tracking-tight">Declarative Plugin Lists</h1>
        <p className="text-muted-foreground mt-2">
          <InlineCode>plugin:list-update</InlineCode> and <InlineCode>plugin:list-apply</InlineCode> manage a
          Moodle site&apos;s third-party plugins as version-pinned files in a git repository: directory
          structure, the <InlineCode>version</InlineCode> file sentinels, worked examples for an ordinary
          plugin and a specially crafted <InlineCode>package_*</InlineCode> component, patching a plugin after
          install, and wiring all of this into GitHub Actions.
        </p>
        <p className="text-muted-foreground mt-2">
          For the full flag-by-flag reference see{' '}
          <Link className="underline" to="/commands/plugin/list-update">plugin:list-update</Link> and{' '}
          <Link className="underline" to="/commands/plugin/list-apply">plugin:list-apply</Link> in the command
          reference.
        </p>
      </div>

      {/* ── 1. Concept ───────────────────────────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">1. Concept</h2>
        <p className="text-muted-foreground">A declarative plugin list is a directory that looks like this:</p>
        <CodeBlock>{`plugins/
  mod_board/
    version
  block_fastnav/
    version
    checksum
  filter_cssinject/
    version`}</CodeBlock>
        <ul className="list-disc list-inside space-y-1 text-muted-foreground">
          <li>One subdirectory per Frankenstyle component (<InlineCode>{'<type>_<name>'}</InlineCode>).</li>
          <li>
            Each subdirectory holds a <InlineCode>version</InlineCode> file: a single integer (or a{' '}
            <a href="#sentinels" className="underline">sentinel</a>) that says which build of the plugin{' '}
            <em>should</em> be installed.
          </li>
          <li>
            Nothing else is required for an ordinary plugin &mdash; <InlineCode>checksum</InlineCode>,{' '}
            <InlineCode>support_status</InlineCode>, and <InlineCode>.gitignore</InlineCode> are written and
            maintained automatically by the tooling.
          </li>
        </ul>

        <div className="grid gap-4 sm:grid-cols-2">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base font-mono">plugin:list-update</CardTitle>
            </CardHeader>
            <CardContent>
              <CardDescription>
                Talks to moodle.org (and optionally the Moodle Marketplace) to find the latest version of each
                plugin compatible with a given Moodle release, and writes it into <InlineCode>version</InlineCode>.
                It only ever edits files under the plugin list &mdash; it never touches a Moodle installation.
              </CardDescription>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base font-mono">plugin:list-apply</CardTitle>
            </CardHeader>
            <CardContent>
              <CardDescription>
                Reads <InlineCode>version</InlineCode> from each subdirectory and reconciles a real Moodle
                installation to match: install, upgrade, uninstall, or remove-files, plus a malware scan of
                anything newly installed. It never edits the plugin list &mdash; it only reads it.
              </CardDescription>
            </CardContent>
          </Card>
        </div>

        <p className="text-muted-foreground">
          The split is deliberate: <InlineCode>list-update</InlineCode> is safe to run anywhere (no side effects
          on a live site) and is typically run on a schedule to open a pull request; <InlineCode>list-apply</InlineCode>{' '}
          is the command that actually changes a Moodle installation, typically run as part of a deployment.
          Both default to a <strong>dry run</strong> &mdash; nothing is written or installed unless you pass{' '}
          <InlineCode>--run</InlineCode>.
        </p>
      </section>

      {/* ── 2. Sentinels ─────────────────────────────────────────── */}
      <section className="space-y-4" id="sentinels">
        <h2 className="text-xl font-semibold">2. Version file sentinels</h2>
        <p className="text-muted-foreground">
          The <InlineCode>version</InlineCode> file doesn&apos;t only hold a real Moodle build number. Three
          states are recognized, each with a numeric <em>and</em> a readable spelling &mdash; prefer the readable
          spelling in your repository, it&apos;s far easier to review in a diff or pull request than a bare{' '}
          <InlineCode>0</InlineCode> or <InlineCode>-1</InlineCode>.
        </p>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Readable value</TableHead>
              <TableHead>Numeric equivalent</TableHead>
              <TableHead>Meaning</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow>
              <TableCell className="font-mono text-sm">(a build number, e.g. 2025041400)</TableCell>
              <TableCell className="font-mono text-sm">(itself)</TableCell>
              <TableCell className="text-muted-foreground">Install/upgrade to exactly this version.</TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">uninstall</TableCell>
              <TableCell className="font-mono text-sm">0</TableCell>
              <TableCell className="text-muted-foreground">
                Uninstall the plugin completely, including its database tables (
                <InlineCode>db/uninstall.php</InlineCode> runs, its tables are dropped).
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">remove-files</TableCell>
              <TableCell className="font-mono text-sm">-1</TableCell>
              <TableCell className="text-muted-foreground">
                Delete the plugin&apos;s files only. The database is left untouched &mdash; Moodle will show the
                plugin as &ldquo;missing from disk&rdquo; until it&apos;s reinstalled.
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">(file absent)</TableCell>
              <TableCell className="font-mono text-sm">&mdash;</TableCell>
              <TableCell className="text-muted-foreground">
                Treated as <InlineCode>remove-files</InlineCode> by <InlineCode>plugin:list-apply</InlineCode> (nothing
                to reconcile against). <InlineCode>plugin:list-update</InlineCode> treats it as &ldquo;not yet
                resolved&rdquo; and creates the file.
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <p className="text-muted-foreground">
          Both spellings are accepted <strong>case-insensitively</strong> by both commands, so{' '}
          <InlineCode>Uninstall</InlineCode>, <InlineCode>UNINSTALL</InlineCode>, and <InlineCode>0</InlineCode> are
          equivalent. Whichever you write is whichever you&apos;ll see echoed back in command output (
          <InlineCode>OK block_fastnav: already at uninstall</InlineCode>,{' '}
          <InlineCode>WOULD REMOVE block_fastnav: ...</InlineCode>) &mdash; pick one convention
          (<InlineCode>uninstall</InlineCode>/<InlineCode>remove-files</InlineCode>) and use it repo-wide instead of
          mixing bare numbers and words.
        </p>

        <ul className="list-disc list-inside space-y-1 text-muted-foreground">
          <li>
            <InlineCode>plugin:list-update</InlineCode> never resurrects a plugin pinned to{' '}
            <InlineCode>uninstall</InlineCode> or <InlineCode>remove-files</InlineCode> (or any other value{' '}
            <InlineCode>{'<= 0'}</InlineCode>) &mdash; it leaves the file exactly as it is. This is what lets you
            &ldquo;turn off&rdquo; a plugin permanently without a scheduled update silently turning it back on.
          </li>
          <li>
            <InlineCode>plugin:list-apply</InlineCode> requires the <InlineCode>version</InlineCode> file to exist
            for every ordinary component it&apos;s asked to apply &mdash; a missing file is a hard error, not a
            guess (<InlineCode>package_*</InlineCode> components resolve their requested version through a script
            instead, see below).
          </li>
          <li>
            Downgrading is never attempted automatically. If the installed version is newer than what{' '}
            <InlineCode>version</InlineCode> requests, both commands leave things alone and report the mismatch
            rather than guessing what you meant.
          </li>
        </ul>
      </section>

      {/* ── 3. Directory structure reference ────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">3. Directory structure reference</h2>
        <CodeBlock>{`plugins/                              <- --directory (defaults to "plugins"
                                          under the Moodle root for list-apply,
                                          "." for list-update)
  <type>_<name>/                      <- one directory per Frankenstyle component
    version                           <- required (or a bin/ script, see package_*)
    checksum                          <- optional, md5 of the pinned zip (auto-pinned
                                          by list-update --archive or list-update
                                          itself; verified by list-apply before
                                          every install/upgrade, see §6)
    archive/                           <- optional, written only by list-update
                                          --archive, read by list-apply
                                          --archive-fallback; see §6
      <component>-<version>.zip      pluglist.json
      pluglist-entry.json
      pluglist.source
    requires                          <- optional, one Frankenstyle component name
                                          per line; installed first, recursively
    support_status                    <- auto-written by list-update when no version
                                          supports the target Moodle release;
                                          auto-removed once one does again
    bin/                              <- only for package_* pseudo-components, or any
                                          other component that wants full manual
                                          control over how it's resolved/installed
      get_requested_version.sh
      get_installed_version.sh
      get_component_path.sh
      get_component_ignore_path.sh
      install_requested_version.sh
      uninstall_requested_version.sh
      install_requested_always_run.sh
      get_latest_plugin_version.sh    <- used by list-update only
    <component>.php                   <- alternative to get_latest_plugin_version.sh,
                                          used by list-update only, see §5.2
  .clamav/                            <- auto-created by list-apply's malware scan
    report/clamav.log
    rules/
    exceptions/
  .phpmussel/
    report/phpmussel.log`}</CodeBlock>
        <p className="text-muted-foreground">
          Directories starting with <InlineCode>.</InlineCode> are never treated as plugin components by either
          command (skipped when auto-discovering components from <InlineCode>--directory</InlineCode>), which is
          why the scanner report/rule directories above are safe to keep alongside the plugin subdirectories.
        </p>
      </section>

      {/* ── 4. Ordinary plugin example ──────────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">4. Example: an ordinary Moodle plugin</h2>
        <p className="text-muted-foreground">
          Most plugins need nothing beyond a <InlineCode>version</InlineCode> file. Given:
        </p>
        <CodeBlock>{`plugins/mod_board/version
---
2025041400`}</CodeBlock>
        <p className="text-muted-foreground">Running:</p>
        <CodeBlock>{`php moosh2.phar plugin:list-update --directory=plugins --moodle-version=5.1 --run mod_board`}</CodeBlock>
        <p className="text-muted-foreground">
          looks up <InlineCode>mod_board</InlineCode> in moodle.org&apos;s <InlineCode>plugins.json</InlineCode>,
          finds the highest version compatible with Moodle 5.1, and rewrites <InlineCode>version</InlineCode> (and
          pins <InlineCode>checksum</InlineCode> next to it, unless <InlineCode>--no-checksum</InlineCode> is
          given). Then:
        </p>
        <CodeBlock>{`php moosh2.phar plugin:list-apply --moodle-path=/var/www/moodle --directory=plugins --run mod_board`}</CodeBlock>
        <p className="text-muted-foreground">
          downloads that exact build, verifies it&apos;s really a zip and that its own{' '}
          <InlineCode>version.php</InlineCode> really declares <InlineCode>mod_board</InlineCode> (not a stale or
          mismatched download), resolves anything it declares via{' '}
          <InlineCode>{'$plugin->dependencies'}</InlineCode> in its <InlineCode>version.php</InlineCode>, and moves
          it into <InlineCode>mod/board</InlineCode>. A malware scan (ClamAV by default; see{' '}
          <InlineCode>--scanner</InlineCode>) runs over the newly installed files before the command reports
          success.
        </p>

        <p className="text-muted-foreground">
          <strong>Turning it off later</strong> is just editing the file:
        </p>
        <CodeBlock>{`plugins/mod_board/version
---
uninstall`}</CodeBlock>
        <p className="text-muted-foreground">
          The next <InlineCode>plugin:list-apply --run</InlineCode> drops the plugin&apos;s tables and deletes{' '}
          <InlineCode>mod/board</InlineCode>.
        </p>

        <p className="text-muted-foreground">
          <strong>A pinned dependency</strong> between two plugins in the same list, independent of whatever the
          plugin&apos;s own <InlineCode>version.php</InlineCode> declares, is written as a{' '}
          <InlineCode>requires</InlineCode> file:
        </p>
        <CodeBlock>{`plugins/block_fastnav/requires
---
# fastnav needs the sharing cart's local API
local_sharingcart`}</CodeBlock>
        <p className="text-muted-foreground">
          <InlineCode>local_sharingcart</InlineCode> is then installed/upgraded first (recursively, up to a depth
          of 5) whenever <InlineCode>block_fastnav</InlineCode> is applied.
        </p>
      </section>

      {/* ── 5. package_* example ─────────────────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">5. Example: a specially crafted package_* component</h2>
        <p className="text-muted-foreground">
          Some things aren&apos;t a single downloadable zip from moodle.org at all &mdash; a bundle of several
          plugins shipped together from a vendor&apos;s own release feed, an install that needs a licence check
          before download, or one that touches more than one directory under the Moodle root. moosh2 handles these
          via a <strong>package_* pseudo-component</strong>: a directory whose name starts with{' '}
          <InlineCode>package_</InlineCode> and that supplies a set of shell scripts instead of a plain{' '}
          <InlineCode>version</InlineCode> file, giving it full manual control over every step both commands would
          otherwise do automatically.
        </p>
        <p className="text-muted-foreground">
          The example below &mdash; <InlineCode>package_kaltura</InlineCode> &mdash; is a{' '}
          <strong>worked illustration</strong>, not a literal Kaltura integration; adapt the download URL, install
          locations, and version-comparison logic to whatever you&apos;re actually packaging. It stands in for any
          vendor plugin distributed as a bundle of several Moodle plugins in one release archive (a common shape
          for commercial video/LTI-style integrations, where &ldquo;the version installed&rdquo; isn&apos;t the{' '}
          <InlineCode>{'$plugin->version'}</InlineCode> of any single directory but something you have to define
          yourself).
        </p>

        <CodeBlock>{`plugins/package_kaltura/
  package_kaltura.php               <- optional, see §5.2
  bin/
    get_latest_plugin_version.sh    <- used by list-update, see §5.2
    get_requested_version.sh        <- used by list-apply
    get_installed_version.sh        <- used by list-apply
    get_component_path.sh           <- used by list-apply
    get_component_ignore_path.sh    <- used by list-apply
    install_requested_version.sh    <- used by list-apply
    uninstall_requested_version.sh  <- used by list-apply
    install_requested_always_run.sh <- used by list-apply, see §7`}</CodeBlock>

        <p className="text-muted-foreground">
          Every script is invoked with <InlineCode>cwd</InlineCode> set to the Moodle root, exactly as if you&apos;d{' '}
          <InlineCode>{'cd /var/www/moodle && bin/whatever.sh'}</InlineCode> yourself, so relative paths in the
          scripts below (<InlineCode>mod/kalturamediagallery</InlineCode>, ...) resolve against the Moodle
          installation being managed.
        </p>

        <h3 className="text-lg font-semibold">5.1 plugin:list-apply side &mdash; always required for package_*</h3>
        <p className="text-muted-foreground">
          Unlike an ordinary component, <InlineCode>plugin:list-apply</InlineCode> <strong>never</strong> looks at
          a <InlineCode>version</InlineCode> file for a <InlineCode>package_*</InlineCode> directory &mdash; it
          shells out to these five scripts for every single step. All five must exist and be executable (
          <InlineCode>chmod +x</InlineCode>) or the component fails outright; there is no fallback.
        </p>

        <p className="text-muted-foreground">
          <InlineCode>bin/get_requested_version.sh</InlineCode> &mdash; stdout must be exactly one integer or
          sentinel. This is the <InlineCode>package_*</InlineCode> equivalent of reading{' '}
          <InlineCode>version</InlineCode> &mdash; nothing stops you from actually keeping a{' '}
          <InlineCode>version</InlineCode> file next to it and just <InlineCode>cat</InlineCode>-ing it, which is
          the simplest option:
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
set -euo pipefail
cat "$(dirname "$0")/../version"`}</CodeBlock>

        <p className="text-muted-foreground">
          <InlineCode>bin/get_installed_version.sh</InlineCode> &mdash; stdout must be exactly one integer
          describing what&apos;s currently installed, or <InlineCode>-1</InlineCode> if nothing is. Since a package
          can span several real plugin directories, this script is where you define what &ldquo;the installed
          version&rdquo; of the bundle even means &mdash; here, a marker file dropped by the install script
          itself:
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
set -euo pipefail
marker="mod/kalturamediagallery/.package_kaltura_version"
if [ -f "$marker" ]; then
    cat "$marker"
else
    echo "-1"
fi`}</CodeBlock>

        <p className="text-muted-foreground">
          <InlineCode>bin/get_component_path.sh</InlineCode> &mdash; stdout must be exactly one path, relative to
          the Moodle root, used for log messages and the orphan marker, and as the place the patch
          fingerprint is kept (see &sect;7). For a multi-directory bundle, pick the primary/anchor directory:
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
echo "mod/kalturamediagallery"`}</CodeBlock>

        <p className="text-muted-foreground">
          <InlineCode>{'bin/install_requested_version.sh <component> <requestedversion>'}</InlineCode> &mdash; does
          the actual work: download, extract, place every plugin the bundle contains, then write the version
          marker <InlineCode>get_installed_version.sh</InlineCode> reads. Exit non-zero on any failure.
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
set -euo pipefail
component="$1"
version="$2"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

url="https://github.com/example-org/moodle-kaltura-bundle/releases/download/\${version}/kaltura-bundle-\${version}.zip"
curl -fsSL -o "$tmp/bundle.zip" "$url"
unzip -q "$tmp/bundle.zip" -d "$tmp/extracted"

for plugin in mod_kalturamediagallery filter_kaltura repository_kaltura; do
    dest=$(php -r '
        require "lib/setup.php";
        $types = core_component::get_plugin_types();
        [$t, $n] = explode("_", $argv[1], 2);
        echo $types[$t] . "/" . $n;
    ' "$plugin")
    rm -rf "$dest"
    cp -r "$tmp/extracted/$plugin" "$dest"
done

# Trigger Moodle's own upgrade for every plugin just placed on disk.
php admin/cli/upgrade.php --non-interactive

mkdir -p mod/kalturamediagallery
echo "$version" > mod/kalturamediagallery/.package_kaltura_version`}</CodeBlock>

        <p className="text-muted-foreground">
          <InlineCode>{'bin/uninstall_requested_version.sh <component>'}</InlineCode> &mdash; the one script that{' '}
          the &ldquo;remove files&rdquo;, &ldquo;uninstall&rdquo;, and best-effort recovery paths all call
          identically. It is also the script most commonly missing on a freshly-written package:
        </p>
        <Note title="Add the uninstall script before you ever pin uninstall / remove-files">
          If it&apos;s missing, <InlineCode>plugin:list-apply</InlineCode> fails loudly for that component rather
          than silently leaving things half-removed.
        </Note>
        <CodeBlock>{`#!/usr/bin/env bash
set -euo pipefail
component="$1"

php admin/cli/uninstall_plugins.php \\
    --plugins=mod_kalturamediagallery,filter_kaltura,repository_kaltura \\
    --run --non-interactive || true

for plugin in mod_kalturamediagallery filter_kaltura repository_kaltura; do
    dest=$(php -r '
        require "lib/setup.php";
        $types = core_component::get_plugin_types();
        [$t, $n] = explode("_", $argv[1], 2);
        echo $types[$t] . "/" . $n;
    ' "$plugin")
    rm -rf "$dest"
done`}</CodeBlock>

        <p className="text-muted-foreground">
          <InlineCode>bin/get_component_ignore_path.sh</InlineCode> &mdash; optional. If present, stdout may list
          one or more paths (one per line, relative to the Moodle root) that should each get a catch-all{' '}
          <InlineCode>.gitignore</InlineCode> written into them after a successful install &mdash; useful when
          your bundle spans several directories and you want every one of them ignored by git, not just the
          &ldquo;anchor&rdquo; directory <InlineCode>get_component_path.sh</InlineCode> reports:
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
cat <<'EOF'
mod/kalturamediagallery
filter/kaltura
repository/kaltura
EOF`}</CodeBlock>

        <h3 className="text-lg font-semibold">5.2 plugin:list-update side &mdash; two ways to resolve &ldquo;latest version&rdquo;</h3>
        <p className="text-muted-foreground">
          <InlineCode>plugin:list-update</InlineCode> looks for either of the following, in this order, and only
          errors if <strong>neither</strong> exists for a <InlineCode>package_*</InlineCode> component:
        </p>

        <p className="text-muted-foreground">
          <strong>Option A</strong> &mdash; <InlineCode>bin/get_latest_plugin_version.sh</InlineCode> (checked
          first, and the only option available to a non-<InlineCode>package_*</InlineCode> component that wants
          this same manual control). Its stdout must be exactly one integer:
        </p>
        <CodeBlock>{`#!/usr/bin/env bash
set -euo pipefail
latest=$(curl -fsSL https://api.github.com/repos/example-org/moodle-kaltura-bundle/releases/latest \\
    | php -r 'echo json_decode(stream_get_contents(STDIN))->tag_name;')
echo "$latest"`}</CodeBlock>

        <p className="text-muted-foreground">
          Two environment variables are exported for it, matching the calling convention of the original{' '}
          <InlineCode>moodle_plugins_lib.rc</InlineCode> shell tooling this mirrors:
        </p>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Variable</TableHead>
              <TableHead>Value</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow>
              <TableCell className="font-mono text-sm">__config_plugin_directory</TableCell>
              <TableCell className="text-muted-foreground">
                absolute path to <InlineCode>package_kaltura/</InlineCode> (i.e.{' '}
                <InlineCode>dirname(dirname($script))</InlineCode>)
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">__moodle_root_directory</TableCell>
              <TableCell className="text-muted-foreground">
                absolute path used for <InlineCode>--moodle-root</InlineCode> (defaults to the parent of{' '}
                <InlineCode>--directory</InlineCode>)
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <p className="text-muted-foreground">
          <strong>Option B</strong> &mdash; <InlineCode>{'<component>.php'}</InlineCode> (e.g.{' '}
          <InlineCode>package_kaltura.php</InlineCode>) &mdash; a PHP class written against{' '}
          <InlineCode>install_plugins.php</InlineCode>&apos;s own <InlineCode>package_base</InlineCode> convention.
          This exists purely so a <InlineCode>package_*</InlineCode> directory that already has one of these
          classes (ported from, or shared with, a project using the older, standalone{' '}
          <InlineCode>install_plugins.php</InlineCode> CLI tool) doesn&apos;t need a parallel shell script
          maintained too. moosh2 doesn&apos;t reimplement <InlineCode>package_base</InlineCode> itself; it shells
          out to:
        </p>
        <CodeBlock>{`php install_plugins.php get-latest-version package_kaltura`}</CodeBlock>
        <p className="text-muted-foreground">
          and takes stdout (exactly one integer) as the resolved version.{' '}
          <InlineCode>install_plugins.php</InlineCode> is expected at{' '}
          <InlineCode>{'<directory>/install_plugins.php'}</InlineCode> by default; point elsewhere with{' '}
          <InlineCode>--install-plugins-script</InlineCode>.
        </p>
        <p className="text-muted-foreground">
          Only use option B if you already have (or need to share) an{' '}
          <InlineCode>install_plugins.php</InlineCode>-compatible class. For a component written purely for
          moosh2, option A is simpler and self-contained. <strong>If both exist, the shell script wins</strong>{' '}
          &mdash; <InlineCode>bin/get_latest_plugin_version.sh</InlineCode> is checked first.
        </p>
        <p className="text-muted-foreground">
          Either way, the resolved integer is reconciled into <InlineCode>version</InlineCode> exactly like an
          ordinary component (<InlineCode>CREATE</InlineCode>/<InlineCode>UPDATE</InlineCode>/
          <InlineCode>OK</InlineCode>/<InlineCode>SKIP</InlineCode> &mdash; including the sentinel-pinning rule
          from section 2: a <InlineCode>version</InlineCode> file already at <InlineCode>uninstall</InlineCode> or{' '}
          <InlineCode>remove-files</InlineCode> is left untouched and neither script is even invoked).
        </p>
      </section>

      {/* ── 6. Archiving & checksum verification ────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">6. Archiving for CI/forensics &amp; checksum verification</h2>
        <p className="text-muted-foreground">
          <InlineCode>--archive-fallback</InlineCode> is unrelated to <InlineCode>package_*</InlineCode>
          (§5) &mdash; that mechanism exists solely so a single download can contain several bundled
          plugins. This section is about a single, ordinary component whose pinned version is later
          withdrawn from moodle.org entirely.
        </p>
        <p className="text-muted-foreground">
          moodle.org can, and does, withdraw specific plugin versions from its public directory &mdash;
          a security issue, a licence change, or the maintainer simply removing an old release. Once
          that happens, <InlineCode>plugins.json</InlineCode> no longer lists it and{' '}
          <InlineCode>plugin:list-apply</InlineCode> can&apos;t download it anymore, even though your{' '}
          <InlineCode>version</InlineCode> file still (correctly) pins it &mdash; and for NIS2/DSGVO-style
          supply-chain traceability, you may need to prove <em>what was actually live</em> at the moment
          you pinned it, not just what you can still download today. <InlineCode>--archive</InlineCode> and{' '}
          <InlineCode>--archive-fallback</InlineCode> exist for exactly this.
        </p>

        <h3 className="text-lg font-semibold">6.1 plugin:list-update --archive</h3>
        <p className="text-muted-foreground">
          Whenever a version bump is actually written (not on a dry run, and not for a component left
          pinned at <InlineCode>uninstall</InlineCode>/<InlineCode>remove-files</InlineCode>),{' '}
          <InlineCode>--archive</InlineCode> writes four files into{' '}
          <InlineCode>{'<component>/archive/'}</InlineCode>:
        </p>
        <Table>
          <TableHeader>            <TableRow>
              <TableHead>File</TableHead>
              <TableHead>Contents</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow>
              <TableCell className="font-mono text-sm">{'<component>-<version>.zip'}</TableCell>
              <TableCell className="text-muted-foreground">
                The exact zip that was downloaded and pinned &mdash; reused via the same
                cache-then-download path as checksum pinning, no separate download.
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">{'pluglist.json'}</TableCell>
              <TableCell className="text-muted-foreground">
                The <strong>full, byte-exact</strong> <InlineCode>pluglist.php</InlineCode> (or mirror)
                response, not just this component&apos;s entry &mdash; the stronger evidentiary artifact
                for forensic purposes than anything derived from it. Deliberately left{' '}
                <strong>uncompressed</strong> so it diffs cleanly in a GitHub pull request; no new HTTP
                request is made to produce it, since <InlineCode>list-update</InlineCode> already
                refreshed its own cache earlier in the same run.
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">{'pluglist-entry.json'}</TableCell>
              <TableCell className="text-muted-foreground">
                Just this component&apos;s own entry from the response above &mdash; small and
                human-readable directly in a PR diff, without needing to open the full snapshot.
              </TableCell>
            </TableRow>
            <TableRow>
              <TableCell className="font-mono text-sm">{'pluglist.source'}</TableCell>
              <TableCell className="text-muted-foreground">
                One line: which URL (moodle.org&apos;s API, or the mirror it falls back to) actually
                supplied the snapshot above.
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <p className="text-muted-foreground">
          Only <InlineCode>{'<component>-<version>.zip'}</InlineCode> carries the version in its
          filename. The other three are deliberately <strong>stable</strong> filenames (matching how{' '}
          <InlineCode>checksum</InlineCode> itself has never had a version suffix) &mdash; the version
          is unambiguous from the sibling <InlineCode>version</InlineCode>/<InlineCode>checksum</InlineCode>{' '}
          files, and a stable name means a later <InlineCode>--archive</InlineCode> run&apos;s pull
          request shows an actual line-level diff of what changed in the moodle.org catalog, instead of
          a delete-and-recreate under a new filename every time.
        </p>
        <p className="text-muted-foreground">
          Only one version&apos;s worth of evidence is kept per component, matching the existing{' '}
          <InlineCode>checksum</InlineCode> convention: a later <InlineCode>--archive</InlineCode> run
          replaces all four files together. Archiving is <strong>best-effort</strong> &mdash; a failure
          here is logged as a warning, it never fails the version bump itself.
        </p>
        <CodeBlock>{`php moosh2.phar plugin:list-update --directory=plugins --moodle-version=5.1 --run --archive`}</CodeBlock>

        <h3 className="text-lg font-semibold">6.2 plugin:list-apply --archive-fallback</h3>
        <p className="text-muted-foreground">
          When moodle.org can&apos;t resolve a requested version or component at all (the withdrawn-version
          case above &mdash; not a &ldquo;not supported for this Moodle release&rdquo; error, which{' '}
          <InlineCode>--archive-fallback</InlineCode> never touches), <InlineCode>plugin:list-apply</InlineCode>{' '}
          normally fails. With <InlineCode>--archive-fallback</InlineCode>, it instead looks for exactly one
          zip under <InlineCode>{'<component>/archive/'}</InlineCode> and installs from that &mdash; through
          the <strong>same</strong> install pipeline as a normal download: the same zip/component
          verification, the same <InlineCode>{'$plugin->dependencies'}</InlineCode> resolution, the same
          malware scan. No archive found (or an unrelated failure) falls straight through to the original          error, unchanged.
        </p>
        <CodeBlock>{`php moosh2.phar plugin:list-apply --moodle-path=/var/www/moodle --directory=plugins --run --archive-fallback --keep-going`}</CodeBlock>
        <p className="text-muted-foreground">
          A component installed this way is reported as <InlineCode>ARCHIVED</InlineCode> instead of{' '}
          <InlineCode>INSTALLED</InlineCode>, and every such component is listed again in a dedicated
          end-of-run summary line (<InlineCode>Archived component(s) in use...</InlineCode>) &mdash; the
          point isn&apos;t just that the install succeeded, it&apos;s that this component now needs an
          explicit, ongoing decision (keep tracking it manually, find a replacement, or formally accept
          the risk), not a one-time fix. That summary line is a GitHub Actions{' '}
          <InlineCode>::warning::</InlineCode> annotation by default under CI; override the level with{' '}
          <InlineCode>--archive-annotation-level=notice</InlineCode> if warning-level is too loud for your
          workflow.
        </p>

        <h3 className="text-lg font-semibold">6.3 Checksum verification</h3>
        <p className="text-muted-foreground">
          <InlineCode>plugin:list-apply</InlineCode> now verifies a component&apos;s <InlineCode>checksum</InlineCode>{' '}
          file (an md5, pinned by <InlineCode>plugin:list-update</InlineCode>) against every zip it&apos;s
          about to install &mdash; a freshly downloaded one and an archive-sourced one alike. A mismatch is
          a hard failure; nothing is installed.
        </p>
        <p className="text-muted-foreground">
          A <strong>missing</strong> <InlineCode>checksum</InlineCode> file only warns, it doesn&apos;t
          block &mdash; failing hard here would break every existing declarative plugin list that predates
          this convention, and some plugins already fell out of the moodle.org directory before it existed
          for them, so <InlineCode>plugin:list-update</InlineCode> can no longer backfill one on its own.
          The warning tells you exactly how to create the file by hand once you have a zip you trust, e.g.:
        </p>
        <CodeBlock>{`md5sum plugins/<component>/archive/*.zip > plugins/<component>/checksum`}</CodeBlock>
        <p className="text-muted-foreground">
          Like the archive summary, this warning is a <InlineCode>::warning::</InlineCode> GitHub Actions
          annotation under CI (this one is not affected by{' '}          <InlineCode>--archive-annotation-level</InlineCode>) and fires on every run that installs/upgrades
          the component &mdash; it&apos;s meant to nag until fixed, not be silently swallowed after the
          first run.
        </p>

        <h3 className="text-lg font-semibold">6.4 --suppress-lifecycle-warnings</h3>
        <p className="text-muted-foreground">
          If your CI runs <InlineCode>plugin:list-apply</InlineCode> twice per job &mdash; once to
          reproduce the currently-deployed production state, once to actually apply the branch&apos;s
          target versions &mdash; only the second run&apos;s archive/checksum state is new information;
          the first run would otherwise repeat the exact same warnings on every single invocation for
          every already-known-archived plugin. <InlineCode>--suppress-lifecycle-warnings</InlineCode> turns
          off both the missing-checksum warning (§6.3) and the archived-component summary (§6.2) for that
          one run, without affecting <InlineCode>ARCHIVED</InlineCode>/<InlineCode>INSTALLED</InlineCode>{' '}
          reporting or anything else:
        </p>
        <CodeBlock>{`# job 1: reproduce production - already-known lifecycle state, don't nag about it
php moosh2.phar plugin:list-apply --moodle-path=/var/www/moodle --directory=plugins-production \\
    --run --archive-fallback --suppress-lifecycle-warnings

# job 2: apply this branch's target versions - this is the run that should nag
php moosh2.phar plugin:list-apply --moodle-path=/var/www/moodle --directory=plugins \\
    --run --archive-fallback --keep-going`}</CodeBlock>
      </section>

      {/* ── 7. Patching ───────────────────────────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">7. Patching a plugin after installation</h2>
        <p className="text-muted-foreground">
          <InlineCode>plugin:list-apply</InlineCode> applies <InlineCode>*.patch</InlineCode> files natively
          &mdash; there is no separate hook script to write. Drop one or more unified diffs directly next to a
          component&apos;s <InlineCode>version</InlineCode> file, e.g.{' '}
          <InlineCode>block_exaaichat/01-version-info.patch</InlineCode>:
        </p>
        <CodeBlock>{`diff --git a/version.php b/version.php
--- a/version.php
+++ b/version.php
@@ -30,3 +30,4 @@ $plugin->version = 2026080600;
 $plugin->requires = 2024100701; // moodle 4.5
 $plugin->maturity = MATURITY_STABLE;
 $plugin->release = '5.1';
+// locally patched, see block_exaaichat/01-version-info.patch`}</CodeBlock>
        <p className="text-muted-foreground">
          Patches must be in <InlineCode>-p1</InlineCode> format, exactly what <InlineCode>git diff</InlineCode>{' '}
          produces (modifying, adding, deleting and renaming files all work) &mdash; <InlineCode>git apply</InlineCode>{' '}
          is what applies them under the hood. With several patches for the same component, a numeric filename
          prefix (<InlineCode>01-...</InlineCode>, <InlineCode>02-...</InlineCode>) controls the apply order.
        </p>

        <h3 className="text-lg font-semibold">When patches are applied</h3>
        <p className="text-muted-foreground">
          Unlike the old hook-based approach this replaces, there is <strong>no one-run lag</strong>: patches are
          applied as part of the same <InlineCode>plugin:list-apply --run</InlineCode> that installs or upgrades
          the component, right after its files are put in place and before the malware scan runs. A component
          freshly installed by a given run comes out of that same run already patched.
        </p>
        <p className="text-muted-foreground">
          What was last applied is tracked in a <InlineCode>.patches-applied</InlineCode> fingerprint file inside
          the installed component directory (not part of the plugin&apos;s own source, so nothing to{' '}
          <InlineCode>.gitignore</InlineCode> by hand). On every later run, if the fingerprint still matches the
          current <InlineCode>*.patch</InlineCode> files, nothing happens &mdash; reported as{' '}
          <InlineCode>OK &lt;component&gt;: already at &lt;version&gt; (including local patches)</InlineCode>.
          If a patch file changed, was added, removed, or renamed since then, the fingerprint no longer matches
          and the component is <strong>redownloaded from moodle.org and re-patched</strong> &mdash; even if the
          requested <InlineCode>version</InlineCode> itself didn&apos;t change. Reverting the old patches in
          place is never attempted, which is why this always starts from a fresh copy of the plugin instead.
        </p>
        <p className="text-muted-foreground">
          A patch that doesn&apos;t apply cleanly (e.g. it no longer matches the plugin&apos;s current source
          after an upstream update) fails the component loudly with the <InlineCode>git apply</InlineCode> output
          included, the same as any other install failure &mdash; it does not silently install the unpatched
          version.
        </p>

        <h3 className="text-lg font-semibold">Patching a package_* component</h3>
        <p className="text-muted-foreground">
          <InlineCode>package_*</InlineCode> components are patched the same way: drop the{' '}
          <InlineCode>*.patch</InlineCode> files next to the package&apos;s <InlineCode>bin/</InlineCode>{' '}
          directory, same fingerprint, same ordering, same &ldquo;changed patch means start again from a fresh copy&rdquo;.
          Three things differ, because a package bundles several plugin directories and installs itself:
        </p>
        <ul className="list-disc pl-6 space-y-2 text-muted-foreground">
          <li>
            <strong>Patch paths are relative to the Moodle repository root</strong>, exactly what{' '}
            <InlineCode>git diff</InlineCode> prints in a Moodle checkout &mdash; a patch may touch any of the package&apos;s
            directories. With the split layout that is the directory <em>above</em> <InlineCode>public/</InlineCode>,
            so the paths start with <InlineCode>public/</InlineCode> (e.g.{' '}
            <InlineCode>a/public/mod/kalturamediagallery/lib.php</InlineCode>). The scripts in{' '}
            <InlineCode>bin/</InlineCode> keep working relative to <InlineCode>$CFG-&gt;dirroot</InlineCode>; only patch
            paths use the repository root.
          </li>
          <li>
            The fingerprint is kept in the directory <InlineCode>bin/get_component_path.sh</InlineCode> reports, which
            therefore has to exist after the install whenever the package has patches (otherwise the component
            fails with a message saying so).
          </li>
          <li>
            <InlineCode>bin/install_requested_version.sh</InlineCode> <strong>must replace its plugin directories
            rather than merge into them</strong>. When a patch changes, the package is not deleted first (the{' '}
            <InlineCode>bin/</InlineCode> contract has no files-only removal &mdash;{' '}
            <InlineCode>uninstall_requested_version.sh</InlineCode> also drops the database); the install script running
            again is what provides the fresh copy. A script that merges leaves the old patched files in place: a new
            patch that no longer fits then fails loudly, but one that still applies goes unnoticed.
          </li>
        </ul>
        <p className="text-muted-foreground">
          Patches are applied right after <InlineCode>install_requested_version.sh</InlineCode> returns, and
          only PHP&apos;s and Moodle&apos;s code caches are reset afterwards. <InlineCode>plugin:list-apply</InlineCode>{' '}
          never runs Moodle&apos;s upgrade for a package &mdash; that stays the install script&apos;s business (or a later{' '}
          <InlineCode>admin/cli/upgrade.php</InlineCode> step). A script that runs the upgrade itself does so{' '}
          <em>before</em> the patches are there, so a patch that changes what an upgrade step does is too late for that run.
        </p>

        <p className="text-muted-foreground">
          As with everything else in <InlineCode>plugin:list-apply</InlineCode>, <InlineCode>--run</InlineCode>{' '}
          gates this &mdash; a dry run against a component with changed patches reports{' '}
          <InlineCode>{'WOULD REAPPLY PATCHES <component>: local patches changed, files will be downloaded again and re-patched'}</InlineCode>{' '}
          instead of actually touching anything.
        </p>
      </section>

      {/* ── 8. GitHub Actions ────────────────────────────────────── */}
      <section className="space-y-4">
        <h2 className="text-xl font-semibold">8. GitHub Actions workflows</h2>
        <p className="text-muted-foreground">
          Both examples below fetch the published <InlineCode>moosh2.phar</InlineCode> from GitHub Releases (the{' '}
          <InlineCode>phar-latest</InlineCode> tag moosh2&apos;s own <InlineCode>build-phar.yml</InlineCode>{' '}
          publishes) rather than building it from source &mdash; faster, and there&apos;s nothing to compile:
        </p>
        <CodeBlock>{`      - name: Install moosh2
        run: |
          curl -fsSL -o moosh2.phar \\
            https://github.com/<org>/moosh2/releases/download/phar-latest/moosh2.phar
          chmod +x moosh2.phar`}</CodeBlock>

        <h3 className="text-lg font-semibold">8.1 Scheduled list-update &rarr; PR with a changelog</h3>
        <p className="text-muted-foreground">
          Runs weekly, updates every <InlineCode>version</InlineCode> (and <InlineCode>checksum</InlineCode>) file
          that has a newer compatible release, and opens a pull request whose description lists what changed per
          plugin (via <InlineCode>plugin:releasenotes</InlineCode>) &mdash; an easy place to triage before
          merging: review the diff, check the linked release notes, merge to ship, or push a fixup commit pinning
          a <InlineCode>version</InlineCode> back down if a release looks broken.
        </p>
        <CodeBlock>{`name: Update plugin list

on:
  schedule:
    - cron: '0 6 * * 1'   # every Monday, 06:00 UTC
  workflow_dispatch: {}

jobs:
  update:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - name: Install moosh2
        run: |
          curl -fsSL -o moosh2.phar \\
            https://github.com/<org>/moosh2/releases/download/phar-latest/moosh2.phar
          chmod +x moosh2.phar

      - name: Snapshot current versions
        run: cp -r plugins plugins.before

      - name: Update every plugin's version file
        run: php moosh2.phar plugin:list-update --directory=plugins --moodle-version=5.1 --run
        env:
          MOODLE_MARKETPLACE_TOKEN: \${{ secrets.MOODLE_MARKETPLACE_TOKEN }}

      - name: Build changelog for the PR description
        id: changelog
        run: |
          {
            echo 'changelog<<EOF'
            for dir in plugins/*/; do
              component=$(basename "$dir")
              before="plugins.before/$component/version"
              after="$dir/version"
              [ -f "$after" ] || continue
              oldv=$(cat "$before" 2>/dev/null || echo "unset")
              newv=$(cat "$after")
              [ "$oldv" = "$newv" ] && continue

              echo "### $component: $oldv -> $newv"
              if [[ "$newv" =~ ^[0-9]+$ && "$oldv" =~ ^[0-9]+$ ]]; then
                php moosh2.phar plugin:releasenotes "$component" "$newv" --since="$oldv" || true
              fi
              echo ""
            done
            echo 'EOF'
          } >> "$GITHUB_OUTPUT"

      - name: Open pull request
        uses: peter-evans/create-pull-request@v7
        with:
          commit-message: 'plugins: update version files'
          branch: automated/plugin-list-update
          title: 'Update plugin versions'
          body: \${{ steps.changelog.outputs.changelog }}
          delete-branch: true`}</CodeBlock>
        <p className="text-muted-foreground">
          Marketplace-gated plugins that the token can&apos;t reach are left at their current version rather than
          bumped to something undownloadable &mdash; the job still succeeds, and{' '}
          <InlineCode>plugin:list-update</InlineCode> prints a <InlineCode>::warning::</InlineCode> annotation
          (picked up automatically as a GitHub Actions check annotation, since <InlineCode>$CI</InlineCode> is set
          by the runner) naming exactly which plugin needs attention.
        </p>

        <h3 className="text-lg font-semibold">8.2 Scheduled/on-merge list-apply &rarr; deploy, patch, and report failures</h3>
        <p className="text-muted-foreground">
          Applies the plugin list to a real Moodle installation &mdash; typically on merge to the branch the PR
          above targets, and/or on its own daily schedule so any <InlineCode>install_requested_always_run.sh</InlineCode>{' '}
          hooks a component defines get a chance to run even on days nothing else changed (patches, see section 7,
          need no such schedule &mdash; they land in the same run as the install/upgrade that triggers them). Runs
          with <InlineCode>--keep-going</InlineCode> so one broken plugin doesn&apos;t block every other one from
          installing, and opens an issue summarising anything that failed.
        </p>
        <CodeBlock>{`name: Apply plugin list

on:
  push:
    branches: [main]
    paths: ['plugins/**']
  schedule:
    - cron: '0 3 * * *'   # daily, 03:00 UTC - also re-runs always_run hooks
  workflow_dispatch: {}

jobs:
  apply:
    runs-on: self-hosted   # needs network/filesystem access to the real Moodle install
    steps:
      - uses: actions/checkout@v7

      - name: Install moosh2
        run: |
          curl -fsSL -o moosh2.phar \\
            https://github.com/<org>/moosh2/releases/download/phar-latest/moosh2.phar
          chmod +x moosh2.phar

      - name: Apply plugin list
        id: apply
        run: |
          php moosh2.phar plugin:list-apply \\
            --moodle-path=/var/www/moodle \\
            --directory=plugins \\
            --keep-going \\
            --scanner=all \\
            --run \\
            | tee apply.log
        env:
          MOODLE_MARKETPLACE_TOKEN: \${{ secrets.MOODLE_MARKETPLACE_TOKEN }}
        continue-on-error: true

      - name: File an issue on failure
        if: steps.apply.outcome == 'failure'
        uses: actions/github-script@v7
        with:
          script: |
            const fs = require('fs');
            const log = fs.readFileSync('apply.log', 'utf8');
            const failed = log
              .split('\\n')
              .filter(l => l.startsWith('ERROR') || l.startsWith('SKIP'))
              .join('\\n');
            await github.rest.issues.create({
              owner: context.repo.owner,
              repo: context.repo.repo,
              title: \`plugin:list-apply failed on \${new Date().toISOString().slice(0,10)}\`,
              body: '\`\`\`\\n' + failed + '\\n\`\`\`',
              labels: ['plugin-list', 'triage'],
            });

      - name: Fail the job if apply failed
        if: steps.apply.outcome == 'failure'
        run: exit 1`}</CodeBlock>

        <p className="text-muted-foreground">A few notes on adapting this:</p>
        <ul className="list-disc list-inside space-y-1 text-muted-foreground">
          <li>
            <InlineCode>--keep-going</InlineCode> aggregates every failing component instead of stopping at the
            first one &mdash; worth it here specifically so the failure-issue step has something more useful to
            report than &ldquo;it broke somewhere&rdquo;.
          </li>
          <li>
            The daily schedule is still worth keeping for any <InlineCode>install_requested_always_run.sh</InlineCode>{' '}
            hooks a component defines for non-patch purposes: a plugin installed by today&apos;s{' '}
            <InlineCode>push</InlineCode> trigger gets that hook run by tomorrow&apos;s{' '}
            <InlineCode>schedule</InlineCode> trigger automatically, with no special-casing needed in the workflow
            itself. Patches (section 7) don&apos;t depend on this &mdash; they&apos;re applied in the same run
            that installs or upgrades the component.
          </li>
          <li>
            <InlineCode>--scanner=all</InlineCode> runs both ClamAV and phpMussel (equivalent to{' '}
            <InlineCode>--scanner=clamav,phpmussel</InlineCode>); drop to the default (
            <InlineCode>clamav</InlineCode>) or <InlineCode>--scanner=none</InlineCode> if neither is installed
            on the runner &mdash; an unavailable scanner only warns and skips, but there&apos;s no reason to ask
            for a scan you know isn&apos;t there.
          </li>
          <li>
            Swap <InlineCode>runs-on: self-hosted</InlineCode> for whatever gives the job real access to the
            target Moodle filesystem and database &mdash; a self-hosted runner on the server itself, an SSH step,
            or a container with the site mounted in.
          </li>
        </ul>
      </section>
    </div>
  );
}
