<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Course;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CourseResetCommand extends BaseCommand
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
            ->setName('course:reset')
            ->setDescription('Reset course data')
            ->setHelp(<<<'HELP'
Resets course user data (enrolments, grades, events, etc.) while keeping
the course structure. Requires --run to execute.

Use --settings to pass space-separated key=value pairs. By default, moosh
applies the defaults returned by each module's reset_course_form_defaults()
function plus: reset_events=1, reset_roles_local=1,
reset_gradebook_grades=1, reset_notes=1.

Core options:
  reset_start_date=TIMESTAMP    New course start date
  reset_end_date=TIMESTAMP      New course end date
  reset_events=1                Delete calendar events
  reset_notes=1                 Delete course notes
  reset_comments=1              Delete all comments
  reset_completion=1            Delete course and activity completion
  delete_blog_associations=1    Delete blog associations
  reset_roles_local=1           Delete local role assignments
  reset_roles_overrides=1       Delete role overrides
  unenrol_users=ROLEID,ROLEID   Unenrol users with these role IDs
  reset_groups_remove=1         Delete all groups
  reset_groups_members=1        Remove all group members
  reset_groupings_remove=1      Delete all groupings
  reset_groupings_members=1     Remove all grouping members
  reset_gradebook_items=1       Remove all grade items and grades
  reset_gradebook_grades=1      Remove grades only
  reset_competency_ratings=1    Delete competency ratings

Module options:
  reset_assign_submissions=1            Delete assignment submissions
  reset_assign_user_overrides=1         Delete assignment user overrides
  reset_assign_group_overrides=1        Delete assignment group overrides
  reset_bigbluebuttonbn_events=1        Delete BBB events
  reset_bigbluebuttonbn_tags=1          Delete BBB tags
  reset_bigbluebuttonbn_logs=1          Delete BBB logs
  reset_bigbluebuttonbn_recordings=1    Delete BBB recordings
  reset_book_tags=1                     Delete book tags
  reset_choice=1                        Reset choice data
  reset_data=1                          Delete database records
  reset_data_notenrolled=1              Delete records of non-enrolled users
  reset_data_ratings=1                  Delete database ratings
  reset_data_comments=1                 Delete database comments
  reset_data_tags=1                     Delete database tags
  reset_feedback_responses=1            Delete feedback responses
  reset_forum_all=1                     Delete all forum posts/discussions
  reset_forum_types=TYPE,TYPE           Reset specific forum types
  reset_forum_subscriptions=1           Delete forum subscriptions
  reset_forum_digests=1                 Delete forum digest settings
  reset_forum_track_prefs=1             Delete forum tracking preferences
  reset_forum_ratings=1                 Delete forum ratings
  reset_forum_tags=1                    Delete forum tags
  reset_glossary_all=1                  Delete all glossary entries
  reset_glossary_notenrolled=1          Delete entries by non-enrolled users
  reset_glossary_types=TYPE,TYPE        Reset specific glossary types
  reset_glossary_ratings=1              Delete glossary ratings
  reset_glossary_comments=1             Delete glossary comments
  reset_glossary_tags=1                 Delete glossary tags
  reset_h5pactivity=1                   Reset H5P activity attempts
  reset_lesson=1                        Reset lesson data
  reset_lesson_user_overrides=1         Delete lesson user overrides
  reset_lesson_group_overrides=1        Delete lesson group overrides
  reset_quiz_attempts=1                 Delete quiz attempts
  reset_quiz_user_overrides=1           Delete quiz user overrides
  reset_quiz_group_overrides=1          Delete quiz group overrides
  reset_scorm=1                         Delete SCORM tracking data
  reset_wiki_pages=1                    Delete wiki pages
  reset_wiki_comments=1                 Delete wiki comments
  reset_wiki_tags=1                     Delete wiki tags
  reset_workshop_submissions=1          Delete workshop submissions
  reset_workshop_assessments=1          Delete workshop assessments
  reset_workshop_phase=1                Reset workshop phase
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
        return new CourseReset52Handler();
    }
}
