<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginInstallCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::Full;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:install')
            ->setDescription('Install a plugin from the moodle.org directory or a local ZIP file')
            ->setHelp(<<<'HELP'
                Installs a Moodle plugin into the correct directory and runs the upgrade
                process so Moodle picks up the new component.

                Two sources are supported:
                  * The moodle.org plugin directory — pass the frankenstyle name (e.g.
                    mod_attendance) and moosh resolves the best release for the current
                    Moodle version (override with --release).
                  * A local ZIP file — pass --from-file=/path/to/plugin.zip. The plugin
                    name is auto-detected from the ZIP's version.php; passing it
                    explicitly will be cross-checked against the ZIP.

                Requires --run to actually install. Without it, the command prints a
                preview of what would happen.
                HELP);
        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler { return $this->handler; }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $v): BaseHandler
    {
        return new PluginInstall52Handler();
    }
}
