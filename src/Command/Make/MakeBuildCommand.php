<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Make;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Build a Moodle codebase from a manifest, similar to drush make.
 */
class MakeBuildCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::None;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = $this->resolveHandler($moodleVersion);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('make:build')
            ->setDescription('Assemble a Moodle codebase from a declarative manifest, like drush make')
            ->setHelp(<<<'HELP'
                Reads an INI-formatted "make" manifest describing a Moodle core release
                and a set of plugins, then assembles the full codebase under a destination
                directory. Inspired by drush make.

                Manifest format:

                    api = 1

                    [core]
                    version = 5.2
                    ; optional: git = ..., branch = ...

                    [mod_attendance]
                    ; default: latest version compatible with core,
                    ;          fetched from the moodle.org plugin directory

                    [mod_bigbluebuttonbn]
                    version = 2024051300

                    [theme_boost_union]
                    git    = https://github.com/moodle-an-hochschulen/moodle-theme_boost_union.git
                    branch = MOODLE_502_STABLE

                    [local_codecheck]
                    zip = https://example.com/codecheck.zip

                Plugins are placed at the canonical Moodle frankenstyle path under
                <destination>/public/, e.g. mod_attendance → <destination>/public/mod/attendance.

                Without --run the command performs a dry-run and prints the plan only.
                Pass --run to actually clone core and fetch plugins. The destination must
                be empty or non-existent.

                Requires git on PATH and the PHP zip extension.

                Worked example (Moodle 5.2 site for teaching programming):
                  https://github.com/tmuras/moosh/blob/2.x/examples/programming-course.make
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
        return new MakeBuild52Handler();
    }
}
