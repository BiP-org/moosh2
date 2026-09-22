# CLAUDE.md — moosh2

## Project Overview

**moosh2** is a rewrite of [Moosh (Moodle Shell)](https://github.com/tmuras/moosh) using Symfony Console 7.x. It provides CLI commands for managing Moodle installations. Licensed under GNU GPL v3+.

This checkout is **bip-org/moosh2**, a fork of `tmuras/moosh`'s `2.x` branch (241 commits ahead as of issue #82). `composer.json` still names the upstream `tmuras2/moosh` package/homepage — that's upstream metadata, not a sign this fork is unmaintained. Default branch: `2.x`.

- **PHP**: >= 8.3
- **Moodle**: >= 5.2
  - **Main dependencies**: symfony/console ^7.0, phpmussel/core ^3.7 (malware scanning, see `plugin:phpmuslescan`/`plugin:list-apply --scanner`)
  - **PHP extensions required**: ext-dom, ext-pcre, ext-libxml, ext-zip
- **Entry points**: `php moosh.php` or `php bin/moosh`

## Repository Structure

```
src/
├── Application.php              # Main Symfony Application, command registration,
│                                 # global option definitions (--moodle-path, --user, ...)
├── Attribute/
│   └── SinceVersion.php         # PHP attribute for Moodle version gating
├── Bootstrap/
│   ├── BootstrapLevel.php       # Enum: None, Config, Full, FullNoCli, DbOnly, FullNoAdminCheck
│   ├── MoodleBootstrapper.php   # Handles Moodle bootstrap lifecycle
│   ├── MoodlePathResolver.php   # Walks directory tree to find Moodle root
│   └── MoodleVersion.php        # Parses version.php, provides version comparison
├── Command/
│   ├── BaseCommand.php          # Abstract base — bootstraps Moodle then calls handle()
│   ├── BaseHandler.php          # Abstract base for version-specific handlers
│   ├── BooleanFilterTrait.php, NumericFilterTrait.php, StdinIdsTrait.php
│   │                             # Shared filter/argument-parsing helpers used across categories
│   └── <Category>/              # ~55 category directories, each following the
│                                 # {Name}Command.php + {Name}52Handler.php pattern below.
│                                 # Notable ones beyond the obvious (Course, User, Activity, ...):
│       Plugin/                  #   plugin:list-update, plugin:list-apply (declarative plugin
│                                 #   lists — see below), plugin:install/uninstall/reinstall,
│                                 #   plugin:clamscan, plugin:phpmuslescan, plugin:releasenotes,
│                                 #   plugin:usage
│       Apache/, Nginx/          #   webserver config parsing (missing-file detection)
│       Sql/                     #   sql:select, sql:drop and friends
│       Make/                    #   plugin:phar-style build tooling (see src/Service/Make/)
├── Data/
│   └── event_map.php            # Static lookup table (event class -> human description) used
│                                 # by event:* / log export commands
├── Output/
│   ├── ResultFormatter.php      # Renders table/CSV/JSON output
│   └── VerboseLogger.php        # -v/-vv-aware structured logging helper
└── Service/                     # Business logic shared across command handlers, notably:
    ├── PluginApiClient.php      # moodle.org plugins.json client (download.moodle.org API +
    │                             # gist-mirror fallback), used by plugin:list-update/list-apply
    ├── PluginZipCache.php       # Shared, version-keyed zip cache + zip validation helpers
    │                             # (magic-byte check, version.php-vs-component check)
    ├── VersionPhpParser.php     # Reads $plugin->version/->component/->dependencies from a
    │                             # version.php via tokenizer, never include()/eval()s it
    ├── ClamscanRunner.php, ClamavSignatureManager.php
    ├── PhpMusselRunner.php, PhpMusselSignatureManager.php
    ├── MarketplaceReleaseNotes(Client).php, MarketplaceScrapeException.php
    ├── Apache/, Nginx/, Moodle/, Make/  # per-domain subdirectories
    └── SystemClock.php, MockupClock.php, ClockInterface.php  # injectable clock for testability
tests/
    ├── common.sh                 # shared helpers: run_moosh, assert_*, print_summary, and a
    │                             # per-dataroot lock so two test runs can't race the same Moodle
    ├── run_all_tests.sh          # runs every test_*.sh, with ONLY_TESTS/SKIP_TESTS filters
    └── test_*.sh                 # ~80 integration test files, one (or a small group) per
                                  # command, mostly against a live Moodle 5.2 install
```

### The declarative plugin list mechanism (`plugin:list-update` / `plugin:list-apply`)

Not covered by the original moosh at all — this fork's largest addition. A "declarative plugin
list" is a directory with one subdirectory per Frankenstyle component, each holding a `version`
file (plus optional `checksum`, `requires`, `archive/`, and `bin/` for `package_*` pseudo-
components). `plugin:list-update` resolves the latest compatible version from moodle.org and
writes `version`/`checksum`; `plugin:list-apply` reconciles a real Moodle install to match. Full
user-facing docs: `documentation/src/pages/PluginListsPage.tsx`, whose §3 "Directory structure
reference" this tree mirrors:

```
plugins/                              <- --directory (defaults to "plugins"
                                          under the Moodle root for list-apply,
                                          "." for list-update)
  <type>_<n>/                      <- one directory per Frankenstyle component
    version                           <- required (or a bin/ script, see package_*)
    checksum                          <- optional, md5 of the pinned zip (auto-pinned
                                          by list-update --archive or list-update
                                          itself; verified by list-apply before
                                          every install/upgrade)
    archive/                           <- optional, written only by list-update
                                          --archive, read by list-apply
                                          --archive-fallback
      <component>-<version>.zip      pluglist.json
      pluglist-entry.json
      pluglist.source
    requires                          <- optional, one Frankenstyle component name
                                          per line; installed first, recursively
    support_status                    <- auto-written by list-update when no version
                                          supports the target Moodle release;
                                          auto-removed once one does again
    phpmuslescan-whitelist             <- optional, per-plugin phpMussel whitelist for
    clamscan-whitelist                    list-apply's post-install scan - see
                                          "Malware Scan Whitelisting" below for why
                                          these live HERE and not in the installed
                                          Moodle plugin directory
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
                                          used by list-update only
  .clamav/                            <- auto-created by list-apply's malware scan
    report/clamav.log
    rules/
    exceptions/
  .phpmussel/
    report/phpmussel.log
```

Directories starting with `.` are never treated as plugin components by either command (skipped
when auto-discovering components from `--directory`), which is why the scanner report/rule
directories above are safe to keep alongside the plugin subdirectories.

Key implementation pieces:
- `PluginApiClient` — talks to `download.moodle.org/api/1.3/pluglist.php`, with a gist-mirror
  fallback, and a 24h-TTL cache at `~/.moosh/plugins.json`. `findBestVersion()`'s two "genuinely not
  on moodle.org" error strings (vs. its third, "not compatible with this Moodle release") are what
  `plugin:list-apply --archive-fallback` pattern-matches on — don't casually reword them.
- `PluginListUpdate52Handler` / `PluginListApply52Handler` — the two handlers, each dispatching on
  three resolution paths per component (`bin/get_latest_plugin_version.sh` / `<component>.php` /
  plain `plugins.json` lookup for update; `package_*` bin/ scripts vs. everything else for apply).
- `--archive` (list-update) / `--archive-fallback` (list-apply) — long-term archival of the pinned
  zip + a full `pluglist.php` snapshot for CI/forensics (NIS2/DSGVO traceability), and installing
  from that archive if a version is later withdrawn from moodle.org. Both opt-in, never default.
  `package_*` is unrelated to this — it exists solely for one-zip-contains-several-plugins bundles
  (Kaltura is the only real-world example), not as any kind of "graduation path" for archived
  components.
- `checksum` (an MD5, pinned by list-update from `plugins.json`'s own `downloadmd5`) is verified by
  list-apply before every install — hard failure on mismatch, warning (not a block) when absent.
- `*.patch` files next to a component's `version` file — ported from `install_plugins.php`'s
  `get_patches()`/`are_patches_applied()`/`apply_patches()`, added on top of the original moosh
  command, which had none. list-apply applies them (`-p1`, `git apply`, sorted by filename) right
  after every (re)install of that component, tracked via a `.patches-applied` fingerprint file
  inside the installed component directory; a changed/removed patch forces a redownload-and-repatch
  even when the requested version itself didn't change. `package_*` components are patched too
  (ported from `package_install()`): their `*.patch` files sit next to `bin/`, patch paths are
  relative to the Moodle *repository* root (`public/…` with the split layout), the fingerprint lives
  in the dir `bin/get_component_path.sh` reports, and `bin/install_requested_version.sh` must
  replace (not merge into) its plugin dirs — it is the only "fresh copy" a changed patch gets.

## Common Commands

```bash
# Install dependencies
composer install

# Run the tool against a Moodle installation
php moosh.php course:list --moodle-path=/path/to/moodle

# Run one integration test (requires MOODLE_DIR pointing to a working Moodle)
MOODLE_DIR=/path/to/moodle bash tests/test_course_list.sh

# Run the whole suite, or a filtered subset
MOODLE_DIR=/path/to/moodle bash tests/run_all_tests.sh
ONLY_TESTS=test_plugin_list_apply MOODLE_DIR=/path/to/moodle bash tests/run_all_tests.sh
```

There is no unit test suite or linter configured yet. No Makefile.

## Architecture & Conventions

### Command Pattern

Every command follows this structure:

1. **Command class** extends `BaseCommand` — sets name, description, bootstrap level
2. **Handler classes** extend `BaseHandler` — implement version-specific logic
3. Command delegates `configure()` and `handle()` to the appropriate handler based on detected Moodle version
4. `BaseCommand::execute()` handles Moodle bootstrapping before calling `handle()`

### Version-Specific Dispatch

- `Application` detects Moodle version early (constructor, before command registration)
- Handler naming: `{CommandName}{MajorMinor}Handler.php` (e.g., `CourseList52Handler.php`)
- Currently all handlers target Moodle 5.2; when 5.3 arrives, add `{Name}53Handler.php` and version dispatch in `resolveHandler()`

### Bootstrap Levels

Commands declare a `BootstrapLevel` enum value controlling how deeply Moodle is initialized:
- `None` — no Moodle includes
- `Config` — config.php only (ABORT_AFTER_CONFIG)
- `Full` — standard full CLI bootstrap (defines CLI_SCRIPT; sessions are NOT started)
- `FullNoCli` — browser context (no CLI_SCRIPT; Moodle starts a session). Used by `admin:login` and `user:login`
- `DbOnly` — database only
- `FullNoAdminCheck` — full without admin check (default for most read-only commands)

Handlers can override the command's bootstrap level by implementing `getBootstrapLevel()` on `BaseHandler` (returns `?BootstrapLevel`, default `null`). When a handler returns a non-null value, it takes precedence over the command's `$bootstrapLevel` property. Commands must override `getActiveHandler()` on `BaseCommand` for this to work.

### Output Formatting

`ResultFormatter` supports three formats via `--output` / `-o`:
- `table` — ASCII table (default)
- `csv` — quoted CSV
- `json` — pretty-printed JSON

### Global CLI Options

- `--moodle-path` / `-p` — path to Moodle directory
- `--user` / `-u` — Moodle user (default: admin)
- `--no-login` / `-l` — skip login
- `--no-user-check` — skip data ownership check
- `--output` / `-o` — output format (table, csv, json)
- `--run` — execute write operations (without it, write commands show a dry-run preview)

## Coding Style

- PHP 8.3+ features: enums, readonly properties, named arguments, match expressions, attributes
- Type hints on all parameters and return types
- One class per file, PSR-4 autoloading (`Moosh2\` namespace)
- PascalCase classes, camelCase methods, colon-separated command names (`course:list`)
- Command names use **singular nouns**: `course:list`, `user:info`, `plugin:usage` (not `courses:list` or `plugins:usage`)
- PHPDoc copyright/license headers on all files
- No dev tooling (phpunit, phpcs) configured yet — keep changes manually consistent

## Command naming

Command names follow the pattern:
category:command-name

That is category, then : then command name with each word split with -
For example:
quiz:question-add


## Adding a New Command

1. Create a directory under `src/Command/` for the command group (e.g., `User/`)
2. Create `{Name}Command.php` extending `BaseCommand` — set bootstrap level, name, description
3. Create handler `{Name}52Handler.php` extending `BaseHandler`
4. Optionally create a helper trait for shared logic
5. Register the command in `Application::registerCommands()`
6. Add integration tests in `tests/`

## Testing

Integration tests use a local Moodle instance. Set `MOODLE_DIR` to the Moodle installation parent directory (the one containing `public/`).

```bash
MOODLE_DIR=/var/www/html/moodle52 bash tests/test_course_list.sh
```

Test scripts source `tests/common.sh` which provides `run_moosh`, `assert_output_contains`, and other helpers. The `run_moosh` function captures output in `$OUT` — never pipe `run_moosh` through other commands (it runs in a subshell and `$OUT` won't propagate). Instead, call `run_moosh` first, then extract from `$OUT`:

```bash
# WRONG — $OUT won't be updated:
run_moosh some:command -o csv | grep foo | cut -d, -f1

# RIGHT:
run_moosh some:command -o csv
VALUE=$(echo "$OUT" | grep foo | cut -d, -f1)
```

Always run the relevant test script after making changes to verify no regressions.

`common.sh` also takes a lock inside the Moodle dataroot (`.moosh-tests.lock`) before running, released on exit — so two test runs (locally, or two CI jobs) can't race the same Moodle install/database. A stale lock (dead PID) is reclaimed automatically on the next run; you shouldn't normally need to touch it by hand.

## Malware Scan Whitelisting (phpMussel & ClamAV, per-plugin)

Both `plugin:phpmuslescan` and `plugin:clamscan` false-positive on things that are safe in a Moodle plugin's own source tree (dotfiles like `.htaccess`, minified JS, Behat `.feature` files, ...). They share one whitelist-matching implementation (`WhitelistMatcher` trait, used by both `PhpMusselRunner` and `ClamscanRunner`) and file format, just with separate filenames so a phpMussel exception and a ClamAV exception for the same plugin don't collide:

| | phpMussel | ClamAV |
|---|---|---|
| Per-plugin file | `phpmuslescan-whitelist` | `clamscan-whitelist` |
| Global file | `~/.moosh2/phpmuslescan-whitelist` | `~/.moosh2/clamscan-whitelist` |

**Where the per-plugin file lives depends on which command is scanning** (`ClamscanRunner`/`PhpMusselRunner`'s `scan()` take an explicit whitelist directory, defaulting to the scanned root):
- `plugin:phpmuslescan` / `plugin:clamscan` (standalone) scan a bare plugin tree with nowhere else to put it, so the file lives in that plugin's own root, alongside its `version.php`.
- `plugin:list-apply` instead reads it from the declarative plugin list's **component directory** (`<--directory>/<component>/`) — the same directory as that component's `version`/`checksum`/`archive/`, see the directory tree above. It deliberately does NOT read it from the installed Moodle plugin directory: that directory is replaced wholesale by every (re)install (a downloaded zip is extracted over it, and a `package_*` component's `bin/install_requested_version.sh` is contractually required to do the same), so a whitelist file kept there would silently vanish on the very next reinstall. The declarative list directory is the user's own git-managed checkout and is never touched by an install, so that's where it survives.
| Built-in entries | `PhpMusselRunner::BUILTIN_WHITELIST` | `ClamscanRunner::BUILTIN_WHITELIST` (empty so far — ClamAV's signature-based detections haven't needed one yet) |
| `reason` matches against | phpMussel's detection message | the ClamAV/YARA signature name |
| When whitelisting applies | before scanning, for a whole-file entry (phpMussel scans file-by-file in-process); after scanning for a scoped entry | always after scanning — clamscan is one external process scanning the whole tree, so every file is scanned regardless, and a match is filtered out of the results plus the exit code recomputed |

Three tiers stack for either scanner — built-in (no config needed), global (every scan), and per-plugin. To generate a **per-plugin** one:

1. Scan the plugin and read the report:
   ```bash
   cd /path/to/the/plugin
   php /path/to/moosh.php plugin:phpmuslescan   # or: plugin:clamscan
   ```
   Each false positive prints as `INFECTED: <relative-path> — <message>` (phpMussel) or `<absolute-path>: <signature> FOUND` (clamscan).

2. For each false positive, add one line to `phpmuslescan-whitelist` or `clamscan-whitelist` in the plugin root (create the file if it doesn't exist):
   - `pattern` — suppresses every detection on a matching file.
   - `pattern | reason` — only suppresses a detection whose message (phpMussel) or signature name (ClamAV) contains `reason` (copy it verbatim, or a distinctive substring, from the report above) on files matching `pattern`; anything else found on that file still fires. Prefer this over a bare pattern whenever you can — it keeps the whitelist from silently swallowing an unrelated, genuine hit on the same file later.
   - Patterns are globs by default, relative to the plugin root: `*` matches within one path segment (never crosses `/`), `**` matches across any number of segments *including zero* — so `**/*.min.js` also matches a root-level file, not only a nested one — and `?` matches one non-`/` character. Prefix with `regex:` instead for a raw PCRE (anchored to the whole relative path) when a glob can't express it, e.g. `regex:^jquery-\d+(\.\d+)*(\.min)?\.js$`.
   - Blank lines and lines starting with `#` are ignored — use `#` to note *why* each entry exists (ticket link, "known false positive because ...").
   - A handful of `**` lines usually cover an entire vendor-library sprawl at once instead of one line per directory, e.g. (phpMussel example, same idea for clamscan with a signature name instead):
     ```
     # minified/versioned vendor JS trips the double-extension heuristic, anywhere in the plugin
     **/*.min.js | phpMussel-Suspect.DoubleExtension-00
     **/*.min.js.map | phpMussel-Suspect.DoubleExtension-00
     **/jquery-*.js | phpMussel-Suspect.DoubleExtension-00

     # IDE/tooling dotfiles trip the filename-manipulation heuristic
     .idea/** | Filename manipulation detected
     .phpcs.xml | Filename manipulation detected
     .prettierrc | Filename manipulation detected
     ```

3. Re-scan and confirm: exit code `0`, and the report's `WHITELISTED:`/`Whitelisted (...)` lines (phpMussel) or `WHITELISTED:` lines and rewritten `Infected files:` summary count (clamscan) name exactly the files/detections you intended to suppress — not more.

Only use the per-plugin file for exceptions specific to *this* plugin. Something that recurs across many plugins belongs in the matching global whitelist instead; something structural about moosh2 itself or about a scanner's heuristics in general belongs in that scanner's `BUILTIN_WHITELIST` constant as a code change, not a config file. Note ClamAV's own signature downloads (`plugin:clamscan:update-signatures`) also include an InterServer `whitelist.fp` — that's ClamAV's native exact-hash ignore-list, a different mechanism from moosh2's pattern-based whitelist described here.

## Bash Command Style

Never chain commands with && or ; operators. Run them as separate bash calls instead.


## Releasing new version

