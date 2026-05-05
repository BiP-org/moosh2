<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command;

use Moosh2\Attribute\SinceVersion;
use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleBootstrapper;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abstract base for all moosh2 commands.
 *
 * Subclasses declare their bootstrap level and implement handle().
 * The base class takes care of bootstrapping Moodle before handle() is called
 * and checks #[SinceVersion] constraints.
 */
abstract class BaseCommand extends Command
{
    /**
     * The bootstrap level this command requires.
     * Override in subclasses to change the default.
     */
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::FullNoAdminCheck;
    protected array $exampleUsage = [];

    /**
     * Implement the actual command logic here.
     */
    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    /**
     * Return the active handler for this command, if any.
     *
     * Override in subclasses that delegate to version-specific handlers
     * so the base class can query handler-specific settings (e.g. bootstrap level).
     */
    protected function getActiveHandler(): ?BaseHandler
    {
        return null;
    }

    /**
     * Resolve the effective bootstrap level.
     *
     * Uses the handler's level when the active handler specifies one,
     * otherwise falls back to the command's own bootstrapLevel property.
     */
    protected function getEffectiveBootstrapLevel(): BootstrapLevel
    {
        return $this->getActiveHandler()?->getBootstrapLevel() ?? $this->bootstrapLevel;
    }

    /**
     * Symfony Console entry point — bootstraps Moodle then delegates to handle().
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);

        $verbose->section('Command: ' . $this->getName());
        $verbose->step('Resolving Moodle bootstrapper');

        $bootstrapper = $this->getBootstrapper($input, $output);
        $effectiveLevel = $this->getEffectiveBootstrapLevel();

        $verbose->detail('Bootstrap level', $effectiveLevel->name);

        $handler = $this->getActiveHandler();
        if ($handler !== null) {
            $verbose->detail('Handler', get_class($handler));
            $handlerLevel = $handler->getBootstrapLevel();
            if ($handlerLevel !== null) {
                $verbose->info('Handler overrides bootstrap level to ' . $handlerLevel->name);
            }
        }

        if ($bootstrapper !== null) {
            if (!$this->meetsVersionRequirement($bootstrapper->getVersion())) {
                $attr = $this->getSinceVersionAttribute();
                $verbose->warn('Version requirement not met — need Moodle ' . $attr->version . '+');
                $output->writeln(sprintf(
                    '<error>This command requires Moodle %s or later.</error>',
                    $attr->version,
                ));
                return Command::FAILURE;
            }
            $verbose->done('Version requirement satisfied');

            $verbose->step('Bootstrapping Moodle at level ' . $effectiveLevel->name);
            $bootstrapper->bootstrap(
                $effectiveLevel,
                $input->getOption('user'),
                $input->getOption('no-login'),
            );
            $verbose->done('Moodle bootstrap complete');

            if ($input->getOption('no-user-check')) {
                $verbose->skip('Skipping data ownership check — --no-user-check flag');
            } elseif (!$this->shouldCheckDataOwnership($input)) {
                $verbose->skip('Skipping data ownership check — disabled by command');
            } else {
                $result = $this->checkDataOwnership($output, $verbose);
                if ($result !== null) {
                    return $result;
                }
            }
        } else {
            $verbose->skip('No bootstrapper — running without Moodle context');
        }

        $verbose->step('Executing command logic');
        $result = $this->handle($input, $output);

        if ($result === Command::SUCCESS) {
            $verbose->done('Command finished successfully');
        } else {
            $verbose->warn('Command finished with exit code ' . $result);
        }
        $verbose->end();

        return $result;
    }

    /**
     * Check the class-level #[SinceVersion] attribute against the running Moodle.
     */
    private function meetsVersionRequirement(MoodleVersion $moodle): bool
    {
        $attr = $this->getSinceVersionAttribute();
        if ($attr === null) {
            return true;
        }

        return $moodle->isAtLeast($attr->version);
    }

    private function getSinceVersionAttribute(): ?SinceVersion
    {
        $ref = new \ReflectionClass($this);
        $attrs = $ref->getAttributes(SinceVersion::class);
        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    /**
     * Override to disable the data ownership check for specific commands or option combinations
     * (e.g. when a command can operate against a dataroot owned by a different user).
     */
    protected function shouldCheckDataOwnership(InputInterface $input): bool
    {
        return true;
    }

    /**
     * Override to append a command-specific hint when the data ownership check fails.
     * Each returned line is rendered as an additional <error> line below the standard message.
     *
     * @return string[]
     */
    protected function dataOwnershipFailureHints(): array
    {
        return [];
    }

    /**
     * Check that directories under Moodle dataroot are owned by the current user.
     *
     * Returns Command::FAILURE if a mismatch is found, null if everything is fine.
     */
    private function checkDataOwnership(OutputInterface $output, VerboseLogger $verbose): ?int
    {
        global $CFG;

        if (!isset($CFG->dataroot) || !is_dir($CFG->dataroot)) {
            $verbose->skip('Skipping data ownership check — dataroot not available');
            return null;
        }

        $verbose->step('Checking data directory ownership');

        $currentUid = posix_getuid();
        $datarootOwner = fileowner($CFG->dataroot);

        if ($datarootOwner !== false && $datarootOwner !== $currentUid) {
            $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;
            $ownerUser = posix_getpwuid($datarootOwner)['name'] ?? (string) $datarootOwner;
            $output->writeln(sprintf(
                '<error>Moodle data directory "%s" is owned by "%s", but you are running as "%s".</error>',
                $CFG->dataroot,
                $ownerUser,
                $currentUser,
            ));
            $output->writeln('<error>This may cause file permission problems. Use --no-user-check to skip this check.</error>');
            foreach ($this->dataOwnershipFailureHints() as $hint) {
                $output->writeln('<error>' . $hint . '</error>');
            }
            return Command::FAILURE;
        }

        $dir = new \DirectoryIterator($CFG->dataroot);
        foreach ($dir as $item) {
            if ($item->isDot()) {
                continue;
            }
            if (!$item->isDir()) {
                continue;
            }
            $owner = $item->getOwner();
            if ($owner !== $currentUid) {
                $currentUser = posix_getpwuid($currentUid)['name'] ?? (string) $currentUid;
                $ownerUser = posix_getpwuid($owner)['name'] ?? (string) $owner;
                $output->writeln(sprintf(
                    '<error>Directory "%s" under Moodle dataroot is owned by "%s", but you are running as "%s".</error>',
                    $item->getPathname(),
                    $ownerUser,
                    $currentUser,
                ));
                $output->writeln('<error>This may cause file permission problems. Use --no-user-check to skip this check.</error>');
                return Command::FAILURE;
            }
        }

        $verbose->done('Data directory ownership OK');
        return null;
    }

    /**
     * Resolve the MoodleBootstrapper from the Application.
     * Returns null when no Moodle directory is found and bootstrap is None.
     */
    private function getBootstrapper(InputInterface $input, OutputInterface $output): ?MoodleBootstrapper
    {
        /** @var \Moosh2\Application $app */
        $app = $this->getApplication();

        $bootstrapper = $app->getBootstrapper($input, $output);

        if ($bootstrapper === null && $this->getEffectiveBootstrapLevel() !== BootstrapLevel::None) {
            throw new \RuntimeException(
                'Could not find a Moodle installation. '
                . 'Run moosh from within a Moodle directory or use --moodle-path.',
            );
        }

        return $bootstrapper;
    }

    public function addExampleUsage(string $description, string $command): void
    {
        $this->exampleUsage[] = ['description' => $description, 'command' => $command];
    }

    public function getProcessedHelp(): string
    {
        $help = parent::getProcessedHelp();

        if ($this->exampleUsage !== []) {
            $help .= "\n\n";
            foreach ($this->exampleUsage as $i => $example) {
                $help .= "\nExample " . ($i + 1) . '. ' . $example['description'] . ":\n";
                $help .= '    <info>moosh ' . $this->getName() . ' '. $example['command'] . '</info>';
                if ($i < count($this->exampleUsage) - 1) {
                    $help .= "\n";
                }
            }
        }

        return $help;
    }
}
