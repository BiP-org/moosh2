<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Course;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CourseUnenrol52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('courseid', InputArgument::REQUIRED, 'Course ID')
            ->addArgument('user', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Username(s) or user ID(s) to unenrol')
            ->addOption('id', null, InputOption::VALUE_NONE, 'Treat user arguments as numeric IDs instead of usernames')
            ->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'Only unenrol from this enrolment plugin (e.g. manual)')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Remove only this role (shortname). If the user has additional roles in the course, they keep the enrolment; if it is the only role, the user is fully unenrolled.');

        $command->addExampleUsage('Dry run — show what would be unenrolled', '2 student01');
        $command->addExampleUsage('Unenrol multiple users by username', '2 student01 student02 --run');
        $command->addExampleUsage('Unenrol by numeric user ID', '2 5 --id --run');
        $command->addExampleUsage('Unenrol only from the manual enrolment plugin', '2 student01 --plugin=manual --run');
        $command->addExampleUsage('Remove only the student role', '2 student01 -r student --run');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB, $PAGE;

        $verbose = new VerboseLogger($output);
        $runMode = $input->getOption('run');
        $courseId = (int) $input->getArgument('courseid');
        $users = $input->getArgument('user');
        $byId = $input->getOption('id');
        $pluginFilter = $input->getOption('plugin');
        $roleName = $input->getOption('role');

        require_once $CFG->dirroot . '/enrol/locallib.php';
        require_once $CFG->dirroot . '/group/lib.php';

        $course = $DB->get_record('course', ['id' => $courseId]);
        if (!$course) {
            $output->writeln("<error>Course with ID $courseId not found.</error>");
            return Command::FAILURE;
        }

        // Validate role if --role was supplied.
        $role = null;
        if ($roleName !== null) {
            $role = $DB->get_record('role', ['shortname' => $roleName]);
            if (!$role) {
                $output->writeln("<error>Role '$roleName' not found.</error>");
                return Command::FAILURE;
            }
        }

        // Validate users
        $verbose->step('Validating users');
        $userRecords = [];
        foreach ($users as $identifier) {
            if ($byId) {
                $record = $DB->get_record('user', ['id' => (int) $identifier, 'deleted' => 0]);
            } else {
                $record = $DB->get_record('user', ['username' => $identifier, 'deleted' => 0]);
            }
            if (!$record) {
                $label = $byId ? "User with ID '$identifier'" : "User '$identifier'";
                $hint = $byId ? '' : ' (use --id to look up by numeric ID)';
                $output->writeln("<error>$label not found.$hint</error>");
                return Command::FAILURE;
            }
            $userRecords[] = $record;
        }

        $courseContext = \context_course::instance($course->id);
        $manager = new \course_enrolment_manager($PAGE, $course);

        // Collect actions: either a role-only unassignment or a full unenrol.
        $verbose->step('Checking enrolments');
        $unenrolActions = [];
        $unassignActions = [];
        foreach ($userRecords as $user) {
            if ($role !== null) {
                $courseRoleIds = $DB->get_fieldset_select(
                    'role_assignments',
                    'DISTINCT roleid',
                    'contextid = ? AND userid = ?',
                    [$courseContext->id, $user->id]
                );

                if (empty($courseRoleIds)) {
                    $output->writeln("<comment>User {$user->username} (ID={$user->id}) has no role assignments in this course.</comment>");
                    continue;
                }

                if (!in_array($role->id, $courseRoleIds, true)) {
                    $output->writeln("<comment>User {$user->username} (ID={$user->id}) does not have role '{$role->shortname}' in this course.</comment>");
                    continue;
                }

                if (count($courseRoleIds) > 1) {
                    // User has other roles — only remove the requested role assignment.
                    $unassignActions[] = ['user' => $user];
                    continue;
                }
                // Falls through to full unenrol — the requested role is the user's only role.
            }

            $enrolments = $manager->get_user_enrolments($user->id);
            if (empty($enrolments)) {
                $output->writeln("<comment>User {$user->username} (ID={$user->id}) has no enrolments in this course.</comment>");
                continue;
            }

            foreach ($enrolments as $enrolment) {
                [$instance, $plugin] = $manager->get_user_enrolment_components($enrolment);
                if (!$instance || !$plugin || !$plugin->allow_unenrol_user($instance, $enrolment)) {
                    continue;
                }
                if ($pluginFilter && $instance->enrol !== $pluginFilter) {
                    continue;
                }
                $unenrolActions[] = [
                    'user' => $user,
                    'instance' => $instance,
                    'plugin' => $plugin,
                    'enrolment' => $enrolment,
                ];
            }
        }

        if (empty($unenrolActions) && empty($unassignActions)) {
            $output->writeln('<info>No enrolments to remove.</info>');
            return Command::SUCCESS;
        }

        if (!$runMode) {
            $output->writeln('<info>Dry run — the following changes would be made (use --run to execute):</info>');
            foreach ($unassignActions as $a) {
                $output->writeln("  Remove role '{$role->shortname}' from {$a['user']->username} (ID={$a['user']->id}) — keeps enrolment.");
            }
            foreach ($unenrolActions as $a) {
                $output->writeln("  Unenrol {$a['user']->username} (ID={$a['user']->id}) from plugin: {$a['instance']->enrol}");
            }
            return Command::SUCCESS;
        }

        if (!empty($unassignActions)) {
            $verbose->step('Unassigning role from ' . count($unassignActions) . ' user(s)');
            foreach ($unassignActions as $a) {
                role_unassign_all([
                    'roleid' => $role->id,
                    'userid' => $a['user']->id,
                    'contextid' => $courseContext->id,
                ]);
                $output->writeln("Removed role \"{$role->shortname}\" from \"{$a['user']->username}\" (ID={$a['user']->id}) in \"{$course->shortname}\".");
            }
        }

        if (!empty($unenrolActions)) {
            $verbose->step('Unenrolling ' . count($unenrolActions) . ' enrolment(s)');
            foreach ($unenrolActions as $a) {
                $a['plugin']->unenrol_user($a['instance'], $a['user']->id);
                $output->writeln("Unenrolled \"{$a['user']->username}\" (ID={$a['user']->id}) from \"{$course->shortname}\" ({$a['instance']->enrol}).");
            }
        }

        return Command::SUCCESS;
    }
}
