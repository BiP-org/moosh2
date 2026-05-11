<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Instance;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Look up the course module ID for an activity instance.
 */
class InstanceInfoCommand extends BaseCommand
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
            ->setName('instance:info')
            ->setDescription('Look up the course module ID for an activity instance')
            ->setHelp(<<<'HELP'
                Resolves an activity instance ID to its course module ID using Moodle's
                get_coursemodule_from_instance() function.

                With a modulename (e.g. quiz, forum, assign), the lookup is scoped to that
                module type and returns a single row.

                Without a modulename, every installed module type is searched for an
                instance with the given ID — useful when you have an ID but don't yet know
                what kind of activity it points to. The same numeric instance ID can exist
                across multiple module types, so multiple rows may be returned.
                HELP);

        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler
    {
        return $this->handler;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $moodleVersion): BaseHandler
    {
        return new InstanceInfo52Handler();
    }
}
