<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Quiz;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QuizQuestionAdd52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('cmid', InputArgument::REQUIRED, 'Quiz course module ID')
            ->addArgument('questionid', InputArgument::REQUIRED, 'Question ID from the question bank')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number to add the question on (default: append to last page)')
            ->addOption('maxmark', null, InputOption::VALUE_REQUIRED, "Override the question's default mark");

        $command->addExampleUsage('Append question 105 to quiz (cmid 42)', '42 105 --run');
        $command->addExampleUsage('Insert question on page 2', '42 105 --page=2 --run');
        $command->addExampleUsage('Override the max mark', '42 105 --maxmark=5 --run');
        $command->addExampleUsage('Dry-run preview', '42 105');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');

        $cmid = (int) $input->getArgument('cmid');
        $questionId = (int) $input->getArgument('questionid');
        $pageOpt = $input->getOption('page');
        $maxmarkOpt = $input->getOption('maxmark');

        if ($cmid <= 0) {
            $output->writeln("<error>Invalid course module ID: $cmid</error>");
            return Command::FAILURE;
        }
        if ($questionId <= 0) {
            $output->writeln("<error>Invalid question ID: $questionId</error>");
            return Command::FAILURE;
        }

        $page = null;
        if ($pageOpt !== null) {
            if (!ctype_digit((string) $pageOpt) || (int) $pageOpt < 1) {
                $output->writeln("<error>--page must be a positive integer.</error>");
                return Command::FAILURE;
            }
            $page = (int) $pageOpt;
        }

        $maxmark = null;
        if ($maxmarkOpt !== null) {
            if (!is_numeric($maxmarkOpt) || (float) $maxmarkOpt < 0) {
                $output->writeln("<error>--maxmark must be a non-negative number.</error>");
                return Command::FAILURE;
            }
            $maxmark = (float) $maxmarkOpt;
        }

        $verbose->step('Loading Moodle quiz libraries');
        require_once $CFG->dirroot . '/mod/quiz/locallib.php';
        require_once $CFG->libdir . '/questionlib.php';

        $cm = get_coursemodule_from_id('quiz', $cmid);
        if (!$cm) {
            $output->writeln("<error>Quiz course module with ID $cmid not found.</error>");
            return Command::FAILURE;
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        if (!$quiz) {
            $output->writeln("<error>Quiz instance for course module $cmid not found.</error>");
            return Command::FAILURE;
        }

        $question = $DB->get_record('question', ['id' => $questionId]);
        if (!$question) {
            $output->writeln("<error>Question with ID $questionId not found.</error>");
            return Command::FAILURE;
        }

        if ($question->qtype === 'random') {
            $output->writeln('<error>Random questions (qtype=random) are not supported by this command.</error>');
            return Command::FAILURE;
        }
        if (!\question_bank::is_qtype_installed($question->qtype)) {
            $output->writeln("<error>Question type '{$question->qtype}' is not installed.</error>");
            return Command::FAILURE;
        }

        $qbe = get_question_bank_entry($questionId);
        if (!$qbe) {
            $output->writeln("<error>Question bank entry for question $questionId not found.</error>");
            return Command::FAILURE;
        }

        // Detect if the question is already in this quiz.
        $alreadyInQuiz = $this->questionIsInQuiz($quiz, $qbe->id);

        $effectiveMaxmark = $maxmark ?? (float) $question->defaultmark;

        if (!$runMode) {
            $output->writeln('<info>Dry run — the following would be done (use --run to execute):</info>');
            $output->writeln("  Quiz:     '{$quiz->name}' (cmid={$cmid}, quizid={$quiz->id})");
            $output->writeln("  Question: '{$question->name}' (id={$questionId}, type={$question->qtype})");
            $output->writeln('  Page:     ' . ($page ?? 'append to last page'));
            $output->writeln("  Max mark: $effectiveMaxmark");

            if ($alreadyInQuiz) {
                $output->writeln('<comment>Note: this question is already in the quiz; --run would be a no-op.</comment>');
            }
            $this->warnIfAttempts($quiz, $output);
            return Command::SUCCESS;
        }

        if ($alreadyInQuiz) {
            $output->writeln("<comment>Question {$questionId} is already in quiz '{$quiz->name}' — nothing to do.</comment>");
            return Command::SUCCESS;
        }

        $this->warnIfAttempts($quiz, $output);

        $verbose->step('Adding question to quiz');
        $result = quiz_add_quiz_question($questionId, $quiz, $page ?? 0, $maxmark);
        if ($result === false) {
            // Defensive — questionIsInQuiz() should have caught this.
            $output->writeln("<comment>Question {$questionId} was already present in quiz '{$quiz->name}'.</comment>");
            return Command::SUCCESS;
        }

        $slot = $this->findSlotForQbe($quiz->id, $qbe->id);
        if ($slot === null) {
            $output->writeln("Added question {$questionId} to quiz '{$quiz->name}'.");
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            "Added question %d ('%s') to quiz '%s' at slot %d (page %d, maxmark %s).",
            $questionId,
            $question->name,
            $quiz->name,
            $slot->slot,
            $slot->page,
            (float) $slot->maxmark,
        ));

        return Command::SUCCESS;
    }

    private function questionIsInQuiz(\stdClass $quiz, int $qbeId): bool
    {
        global $DB;

        $sql = "SELECT 1
                  FROM {quiz_slots} slot
                  JOIN {question_references} qr ON qr.itemid = slot.id
                 WHERE slot.quizid = ?
                   AND qr.component = ?
                   AND qr.questionarea = ?
                   AND qr.questionbankentryid = ?";
        return (bool) $DB->record_exists_sql($sql, [$quiz->id, 'mod_quiz', 'slot', $qbeId]);
    }

    private function findSlotForQbe(int $quizId, int $qbeId): ?\stdClass
    {
        global $DB;

        $sql = "SELECT slot.id, slot.slot, slot.page, slot.maxmark
                  FROM {quiz_slots} slot
                  JOIN {question_references} qr ON qr.itemid = slot.id
                 WHERE slot.quizid = ?
                   AND qr.component = ?
                   AND qr.questionarea = ?
                   AND qr.questionbankentryid = ?";
        $row = $DB->get_record_sql($sql, [$quizId, 'mod_quiz', 'slot', $qbeId]);
        return $row ?: null;
    }

    private function warnIfAttempts(\stdClass $quiz, OutputInterface $output): void
    {
        global $DB;

        $count = $DB->count_records('quiz_attempts', ['quiz' => $quiz->id]);
        if ($count > 0) {
            $output->writeln(sprintf(
                '<comment>Warning: quiz already has %d attempt(s); adding questions to a quiz with attempts may have side effects.</comment>',
                $count,
            ));
        }
    }
}
