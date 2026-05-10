---
name: write-integration-test
description: Conventions for writing moosh integration tests under tests/. Use when adding, extending, or fixing a tests/*.sh integration test.
---

# write-integration-test

Skill for writing integration tests for moosh commands. Applies to files inside the `tests/` directory.

## When to use

When the user asks to add, extend, or fix an integration test for a moosh command, or when creating a new test script under `tests/`.

## Rules

### One script per command

Each moosh command is covered by its own shell script named `tests/test_<group>_<command>.sh` (e.g. `test_course_list.sh`, `test_plugin.sh` for all `plugin:*` subcommands grouped together when tightly related, or `test_user_create.sh`). Do not bundle unrelated commands into one script. If an existing script covers a different command, create a new file instead of extending it.

### Never use sudo

Tests must run as the current user without elevated privileges. Do not call `sudo` for chmod, rm, mkdir, or anything else. To simulate restricted filesystem permissions, drop the current user's bits only:

```bash
# Simulate "no write access" for the current user.
ORIG_PERMS=$(stat -c '%a' "$DIR")
chmod u-w "$DIR"
# ... run the command under test ...
chmod "$ORIG_PERMS" "$DIR"   # always restore before asserting
```

If a scenario genuinely requires root (e.g. a file owned by another user), skip the test or document it as a manual-only check — do not introduce sudo.

## Script skeleton

```bash
#!/usr/bin/env bash
#
# Integration test for moosh2 <command:name>
# Requires a working Moodle 5.2 installation (MOODLE_DIR env var).
#
# Usage: bash tests/test_<name>.sh
#

source "$(dirname "$0")/common.sh"

echo "=== moosh2 <command:name> integration tests ==="
echo "Moodle path: $MOODLE_PATH"
echo "moosh path:  $MOOSH"
echo ""

echo "--- Resetting Moodle to known state ---"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$SCRIPT_DIR/clear.sh"
echo ""

# ── Tests ────────────────────────────────────────────────────────

echo "--- Test: <short description> ---"
run_moosh <command:name> <args>
assert_output_contains "<what>" "<substring>" "$OUT"
echo ""

print_summary
```

## Helpers from `common.sh`

- `run_moosh <args...>` — runs moosh and captures stdout+stderr in `$OUT`. Returns the exit code. **Never pipe `run_moosh` through other commands** — it runs in a subshell and `$OUT` won't propagate. Call it first, then extract from `$OUT`:

  ```bash
  # WRONG — $OUT won't be updated:
  run_moosh some:command -o csv | grep foo | cut -d, -f1

  # RIGHT:
  run_moosh some:command -o csv
  VALUE=$(echo "$OUT" | grep foo | cut -d, -f1)
  ```

- `assert_output_contains "<desc>" "<expected>" "$OUT"` — fails if `$OUT` does not contain the substring.
- `assert_output_not_contains "<desc>" "<expected>" "$OUT"` — fails if `$OUT` contains the substring.
- `assert_output_not_empty "<desc>" "$OUT"` — fails if `$OUT` is empty.
- `assert_exit_code "<desc>" <expected> <actual>` — capture `$?` into a local var immediately after `run_moosh`, then pass it. Do not inline `$?` in a later command.
- `print_summary` — prints the PASS/FAIL tally and exits non-zero if anything failed. Always call at the end.

## Coverage checklist

For every command, include tests for:

- **Happy path** — dry-run (if the command supports `--run`) and `--run` execution.
- **Output formats** — `table`, `csv`, and `json` via `-o` where applicable.
- **Error paths** — invalid arguments, missing dependencies, permission errors, nonexistent targets. Assert both the exit code (via `assert_exit_code`) and the error message substring.
- **Help** — `run_moosh <cmd> --help` plus `assert_output_contains` for description and key flags.
- **Cleanup** — reset any mutations the test made (delete created records, restore permissions, run `clear.sh`). Do not leave the Moodle instance dirty for the next test.

## Running tests

```bash
MOODLE_DIR=/path/to/moodle bash tests/test_<name>.sh
```

Run the test after every change to verify there are no regressions.
