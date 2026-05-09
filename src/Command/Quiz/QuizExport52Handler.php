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

/**
 * quiz:export implementation for Moodle 5.2.
 *
 * Walks quiz_slots → question_references → question_versions → question and
 * delegates per-question XML rendering (with base64-inlined images) to Moodle's
 * own qformat_xml exporter.
 */
class QuizExport52Handler extends BaseHandler
{
    /**
     * Quiz table fields included in the <quizinfo> wrapper when --with-quiz
     * is used. Skips id/course/timecreated/timemodified — those are
     * environment-specific and would not survive a re-import anyway.
     */
    private const QUIZ_INFO_FIELDS = [
        'name', 'intro', 'introformat',
        'timeopen', 'timeclose', 'timelimit',
        'overduehandling', 'graceperiod',
        'preferredbehaviour', 'canredoquestions',
        'attempts', 'attemptonlast',
        'grademethod', 'decimalpoints', 'questiondecimalpoints',
        'reviewattempt', 'reviewcorrectness',
        'reviewmaxmarks', 'reviewmarks',
        'reviewspecificfeedback', 'reviewgeneralfeedback',
        'reviewrightanswer', 'reviewoverallfeedback',
        'questionsperpage', 'navmethod', 'shuffleanswers',
        'sumgrades', 'grade',
        'password', 'subnet', 'browsersecurity',
        'delay1', 'delay2',
        'showuserpicture', 'showblocks',
        'completionattemptsexhausted', 'completionminattempts',
        'allowofflineattempts', 'precreateattempts',
    ];

    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('cmid', InputArgument::REQUIRED, 'Quiz course module ID')
            ->addOption(
                'with-quiz',
                null,
                InputOption::VALUE_NONE,
                'Wrap output in <moosh-quiz> with quiz settings and slot order',
            );

        $command->addExampleUsage(
            'Export quiz questions to stdout (Moodle XML, importable via Question bank > Import)',
            '42',
        );
        $command->addExampleUsage(
            'Save the question bank XML to a file',
            '42 > questions.xml',
        );
        $command->addExampleUsage(
            'Include quiz settings and slot order in a <moosh-quiz> wrapper',
            '42 --with-quiz > quiz-full.xml',
        );
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB;

        $verbose = new VerboseLogger($output);

        $cmidArg = $input->getArgument('cmid');
        $cmid = (int) $cmidArg;
        if ($cmid <= 0) {
            $output->writeln("<error>Invalid course module ID: $cmidArg</error>");
            return Command::FAILURE;
        }

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

        require_once $CFG->libdir . '/questionlib.php';
        require_once $CFG->dirroot . '/question/format.php';
        require_once $CFG->dirroot . '/question/format/xml/format.php';

        $verbose->step("Resolving questions for quiz '{$quiz->name}' (cmid=$cmid)");

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
        if (empty($slots)) {
            $output->writeln('<comment>Quiz has no slots; nothing to export.</comment>');
            return Command::SUCCESS;
        }

        $quizContext = \context_module::instance($cm->id);

        // Resolve each slot to a concrete question id (skipping random slots).
        $questionIds = [];
        $slotMeta = []; // slot.id => ['questionid' => ?, 'version' => ?, 'random' => bool]
        $randomSlots = 0;

        foreach ($slots as $slot) {
            $ref = $DB->get_record('question_references', [
                'usingcontextid' => $quizContext->id,
                'component' => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid' => $slot->id,
            ]);
            if ($ref) {
                $qid = $this->resolveQuestionId($ref);
                if ($qid !== null) {
                    $questionIds[] = $qid;
                    $slotMeta[$slot->id] = [
                        'questionid' => $qid,
                        'version' => $ref->version,
                        'random' => false,
                    ];
                }
                continue;
            }

            $setRef = $DB->get_record('question_set_references', [
                'usingcontextid' => $quizContext->id,
                'component' => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid' => $slot->id,
            ]);
            if ($setRef) {
                $randomSlots++;
                $slotMeta[$slot->id] = ['random' => true];
            }
        }

        if ($randomSlots > 0) {
            $verbose->warn(
                "$randomSlots slot(s) use random selection (question_set_references) " .
                'and will be omitted from the question payload.',
            );
        }

        if (empty($questionIds)) {
            $output->writeln('<comment>Quiz has no fixed-reference questions to export.</comment>');
            return Command::SUCCESS;
        }

        $verbose->info('Found ' . count($questionIds) . ' question(s) to export');

        $qformat = new \qformat_xml();
        $qformat->setContexts($this->collectContexts($questionIds, $quizContext));

        $body = '';
        foreach ($questionIds as $qid) {
            $question = \question_bank::load_question_data($qid);
            $line = $qformat->writequestion($question);
            if ($line !== null) {
                $body .= $line;
            }
        }

        $withQuiz = (bool) $input->getOption('with-quiz');
        if ($withQuiz) {
            echo $this->renderWithQuizWrapper($quiz, $slots, $slotMeta, $body);
        } else {
            echo $this->renderQuestionBank($body);
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve a question_references row to the question id Moodle would actually
     * load: the pinned version when set, otherwise the latest 'ready' version.
     */
    private function resolveQuestionId(\stdClass $ref): ?int
    {
        global $DB;

        if ($ref->version !== null) {
            $row = $DB->get_record('question_versions', [
                'questionbankentryid' => $ref->questionbankentryid,
                'version' => $ref->version,
            ]);
            return $row ? (int) $row->questionid : null;
        }

        $rows = $DB->get_records_sql(
            "SELECT questionid
               FROM {question_versions}
              WHERE questionbankentryid = ?
                AND status = 'ready'
              ORDER BY version DESC",
            [$ref->questionbankentryid],
            0,
            1,
        );
        $row = reset($rows);
        return $row ? (int) $row->questionid : null;
    }

    /**
     * qformat_xml::setContexts() drives file-area permission checks during
     * export, so include every context that owns one of the questions plus the
     * quiz module context.
     *
     * @param int[] $questionIds
     * @return \context[]
     */
    private function collectContexts(array $questionIds, \context $quizContext): array
    {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($questionIds);
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT qc.contextid AS contextid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id $insql",
            $params,
        );

        $contexts = [$quizContext->id => $quizContext];
        foreach ($rows as $r) {
            $contexts[$r->contextid] = \context::instance_by_id($r->contextid);
        }
        return array_values($contexts);
    }

    private function renderQuestionBank(string $body): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . $body . "</quiz>\n";
    }

    /**
     * @param array<int,array<string,mixed>> $slotMeta
     */
    private function renderWithQuizWrapper(
        \stdClass $quiz,
        array $slots,
        array $slotMeta,
        string $body,
    ): string {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<moosh-quiz>\n";

        $xml .= "  <quizinfo>\n";
        foreach (self::QUIZ_INFO_FIELDS as $field) {
            if (!property_exists($quiz, $field)) {
                continue;
            }
            $value = $quiz->$field;
            if ($value === null) {
                $xml .= "    <$field/>\n";
            } else {
                $xml .= "    <$field>" . self::xmlEscape((string) $value) . "</$field>\n";
            }
        }
        $xml .= "  </quizinfo>\n";

        $xml .= "  <slots>\n";
        foreach ($slots as $slot) {
            $info = $slotMeta[$slot->id] ?? null;
            $xml .= sprintf(
                '    <slot slot="%d" page="%d" maxmark="%s" requireprevious="%d"',
                (int) $slot->slot,
                (int) $slot->page,
                self::xmlEscape((string) $slot->maxmark),
                (int) $slot->requireprevious,
            );
            if ($slot->displaynumber !== null && $slot->displaynumber !== '') {
                $xml .= ' displaynumber="' . self::xmlEscape((string) $slot->displaynumber) . '"';
            }
            if ($info !== null && !empty($info['random'])) {
                $xml .= ' random="1"';
            } elseif ($info !== null && isset($info['questionid'])) {
                $xml .= ' questionid="' . (int) $info['questionid'] . '"';
                if ($info['version'] !== null) {
                    $xml .= ' version="' . (int) $info['version'] . '"';
                }
            }
            $xml .= "/>\n";
        }
        $xml .= "  </slots>\n";

        $xml .= "  <questions>\n";
        $xml .= $body;
        $xml .= "  </questions>\n";

        $xml .= "</moosh-quiz>\n";
        return $xml;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
