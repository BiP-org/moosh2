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
user-facing docs: `documentation/src/pages/PluginListsPage.tsx`. Key implementation pieces:
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

## phpMussel Whitelisting (per-plugin)

`plugin:phpmuslescan` false-positives on things that are safe in a Moodle plugin's own source tree (dotfiles like `.htaccess`, minified JS, Behat `.feature` files, ...). Three whitelist tiers stack — built-in (fixed, ships with `PhpMusselRunner::BUILTIN_WHITELIST`, no config needed), global (`~/.moosh2/phpmuslescan-whitelist`, every scan), and per-plugin (`.moosh-phpmuslescan-whitelist` in the plugin's own root). To generate a **per-plugin** one:

1. Scan the plugin and read the report:
   ```bash
   cd /path/to/the/plugin
   php /path/to/moosh.php plugin:phpmuslescan
   ```
   Each false positive prints as `INFECTED: <relative-path> — <phpMussel message>`.

2. For each false positive, add one line to `.moosh-phpmuslescan-whitelist` in the plugin root (create the file if it doesn't exist):
   - `pattern` (a glob, relative to the plugin root, matched with `fnmatch()`/`FNM_PATHNAME` so `*` doesn't cross `/`) — skips the file entirely, any detection.
   - `pattern | reason` — only suppresses a detection whose message contains `reason` (copy it verbatim, or a distinctive substring, from the `INFECTED:` line above) on files matching `pattern`; anything else found on that file still fires. Prefer this over a bare pattern whenever you can — it keeps the whitelist from silently swallowing an unrelated, genuine hit on the same file later.
   - Blank lines and lines starting with `#` are ignored — use `#` to note *why* each entry exists (ticket link, "known false positive because ...").

3. Re-scan and confirm: exit code `0`, and the report's `WHITELISTED:`/`Whitelisted (...)` lines name exactly the files/detections you intended to suppress — not more.

Only use the per-plugin file for exceptions specific to *this* plugin. Something that recurs across many plugins belongs in the global whitelist (`~/.moosh2/phpmuslescan-whitelist`, same format) instead; something structural about moosh2 itself or about phpMussel's heuristics in general belongs in `BUILTIN_WHITELIST` in `src/Service/PhpMusselRunner.php` as a code change, not a config file.

## Bash Command Style

Never chain commands with && or ; operators. Run them as separate bash calls instead.


## Releasing new version

