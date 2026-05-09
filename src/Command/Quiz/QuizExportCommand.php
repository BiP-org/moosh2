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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export a quiz with its questions to Moodle XML.
 *
 * Canonical name: quiz:export
 */
class QuizExportCommand extends BaseCommand
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
            ->setName('quiz:export')
            ->setDescription('Export a quiz with its questions to Moodle XML')
            ->setHelp(<<<'HELP'
                Exports a quiz's questions in Moodle XML format. Images embedded in
                question text, general feedback, answers, hints, and answer feedback
                are inlined as base64 inside <file> elements, so the output is a
                single self-contained file.

                By default the output is the standard Moodle question-bank XML format
                (root element <quiz>), importable via Moodle's "Question bank > Import"
                UI on any compatible Moodle instance.

                Use --with-quiz to wrap the output in a moosh-specific <moosh-quiz>
                root that also captures the quiz instance settings (name, intro,
                timing, grading, review options, ...) and the slot order with each
                question's maximum mark.

                Slots that use random question selection (question_set_references)
                are skipped; a warning is emitted and the slot is omitted from the
                question payload.
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
        return new QuizExport52Handler();
    }
}
