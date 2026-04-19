---
name: document-command
description: Document a moosh command — update the PHP Command/Handler classes and the HTML docs data file. Use when adding or editing command help text, examples, or command metadata.
paths: src/Command/**, documentation/src/data/commands.ts
---

# document-command

Skill for documenting a moosh command. Updates both the PHP source code and the HTML documentation data file.

## When to use

When the user asks to document a command, add help text to a command, add examples to a command, or update command documentation.

## Arguments

The user should provide the command name (e.g. `auth:mod`, `course:list`).

## Steps

### 1. Read the current state

Read these files to understand what needs updating:

- The **Command class** (e.g. `src/Command/Auth/AuthModCommand.php`) — look at `configure()` for `setName()`, `setDescription()`, `setHelp()`
- The **Handler class** (e.g. `src/Command/Auth/AuthMod52Handler.php`) — look at `configureCommand()` for arguments, options, and `addExampleUsage()` calls
- The **documentation entry** in `documentation/src/data/commands.ts` — find the line matching the command name

### 2. Update PHP source — Command class

In the Command class's `configure()` method, ensure:

- `setDescription()` — short one-line description
- `setHelp()` — longer explanation of what the command does, any prerequisites (e.g. "Requires --run"), and behavioral notes. Do NOT put examples here — examples go in the handler via `addExampleUsage()`.

Example pattern:
```php
protected function configure(): void
{
    $this
        ->setName('auth:mod')
        ->setDescription('Enable, disable, or reorder auth plugins')
        ->setHelp(<<<'HELP'
            Modify authentication plugin status: enable, disable, move up, or move down
            in priority order. The "manual" and "nologin" plugins cannot be modified —
            they are always active.

            Requires --run to apply changes.
            HELP);

    $this->handler->configureCommand($this);
}
```

### 3. Update PHP source — Handler class

In the Handler's `configureCommand()` method, add example usage calls **after** argument/option definitions:

```php
$command->addExampleUsage(
    'Enable self-registration via email',
    'enable email --run',
);
```

Rules for `addExampleUsage()`:
- First argument: human-readable description of what the example does
- Second argument: command arguments and options only — do NOT include `moosh <command-name>` prefix (it is prepended automatically by `getProcessedHelp()`)
- Add 2-5 examples covering the most common use cases
- Include a dry-run example (without `--run`) if the command supports `--run`

### 4. Update HTML documentation

Edit `documentation/src/data/commands.ts` and update the entry for this command. The entry must match the TypeScript `Command` interface:

```typescript
interface Command {
  name: string;           // e.g. 'auth:mod'
  category: string;       // e.g. 'auth' — the command group
  description: string;    // matches setDescription()
  help?: string;          // matches setHelp() — plain text, no HEREDOC
  bootstrapLevel: string; // e.g. 'Full', 'None', 'Config', 'FullNoCli', 'DbOnly', 'FullNoAdminCheck'
  arguments: CommandArgument[];
  options: CommandOption[];
  examples?: (string | { description: string; command: string })[];
  sinceVersion?: string;
}
```

For arguments, map from Symfony:
- `InputArgument::REQUIRED` → `required: true`
- `InputArgument::OPTIONAL` → `required: false`
- `InputArgument::IS_ARRAY` → `isArray: true`

For options, map from Symfony:
- `InputOption::VALUE_REQUIRED` → `type: 'value_required'`
- `InputOption::VALUE_OPTIONAL` → `type: 'value_optional'`
- `InputOption::VALUE_NONE` → `type: 'value_none'`
- Include `shortcut` if the option has one (e.g. `shortcut: '-s'`)
- Include `default` if the option has a default value

For examples with descriptions, use the object form and include the full command with `moosh <command-name>` prefix:
```typescript
examples: [
  { description: 'Enable email auth', command: 'moosh auth:mod enable email --run' },
  { description: 'Dry run', command: 'moosh auth:mod enable email' },
]
```

For simple examples without descriptions, use plain strings:
```typescript
examples: ['moosh auth:list', 'moosh auth:list --enabled-only']
```

### 5. Verify consistency

Check that all three locations are consistent:
- Description in Command class matches `description` in commands.ts
- Help text in Command class matches `help` in commands.ts
- Arguments and options in Handler match those in commands.ts
- Examples in Handler (via `addExampleUsage()`) match `examples` in commands.ts
- The `examples` in commands.ts include the `moosh <command-name>` prefix, while `addExampleUsage()` does NOT include it
