<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Quiz;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Add a question from the question bank to a quiz.
 */
class QuizQuestionAddCommand extends BaseCommand
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
            ->setName('quiz:question:add')
            ->setDescription('Add a question from the question bank to a quiz')
            ->setHelp(<<<'HELP'
                Adds an existing question from the question bank to a quiz, creating a new
                slot. The quiz is identified by its course module ID; the question by its
                question ID (see question:list).

                Without --page, the question is appended after the last slot, respecting the
                quiz's "questions per page" setting. With --page=N, the question is inserted
                onto page N; later slots are shifted by one.

                Without --maxmark, the question's defaultmark is used.

                Random questions (qtype=random) are not supported.

                Requires --run to actually add. Adding a question to a quiz that already has
                attempts is allowed but discouraged — a warning is printed.
                HELP);

        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler
    {
        return $this->handler;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $verbose = new VerboseLogger($output);
        $verbose->step('Delegating to handler: ' . get_class($this->handler));
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $moodleVersion): BaseHandler
    {
        return new QuizQuestionAdd52Handler();
    }
}
