<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\User;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * user:create implementation for Moodle 5.1.
 */
class UserCreate52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('username', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Username(s) to create')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'User password', 'Abc123!@')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Authentication method', 'manual')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'City')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Country code')
            ->addOption('idnumber', null, InputOption::VALUE_REQUIRED, 'ID number')
            ->addOption('institution', null, InputOption::VALUE_REQUIRED, 'Institution')
            ->addOption('department', null, InputOption::VALUE_REQUIRED, 'Department')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Generate a random password and send a welcome email to the new user');

        $command->addExampleUsage('Create a single user with default password', 'john --run');
        $command->addExampleUsage('Create a user with full profile details', 'john --email=john@example.com --firstname=John --lastname=Doe --run');
        $command->addExampleUsage('Create multiple users with a shared password', 'student01 student02 student03 --password=Test123! --run');
        $command->addExampleUsage('Create a user with institution and department', "john --firstname=John --lastname=Doe --institution='Acme University' --department=Engineering --run");
        $command->addExampleUsage('Create a user and send welcome email with generated password', 'john --email=john@example.com --notify --run');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG, $DB, $SITE;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');
        $runMode = $input->getOption('run');
        $usernames = $input->getArgument('username');

        $verbose->step('Loading Moodle libraries');
        require_once $CFG->dirroot . '/user/lib.php';
        require_once $CFG->dirroot . '/lib/moodlelib.php';

        $notify = $input->getOption('notify');
        $password = $input->getOption('password');
        $email = $input->getOption('email');
        $firstname = $input->getOption('firstname');
        $lastname = $input->getOption('lastname');
        $auth = $input->getOption('auth');
        $city = $input->getOption('city');
        $country = $input->getOption('country');
        $idnumber = $input->getOption('idnumber');
        $institution = $input->getOption('institution');
        $department = $input->getOption('department');

        if (!$runMode) {
            $output->writeln('<info>Dry run — the following users would be created (use --run to execute):</info>');
            foreach ($usernames as $username) {
                $output->writeln("  $username");
            }
            return Command::SUCCESS;
        }

        $verbose->step('Creating ' . count($usernames) . ' user(s)');

        $headers = $notify ? ['id', 'username', 'email', 'generated_password'] : ['id', 'username', 'email'];
        $rows = [];

        foreach ($usernames as $username) {
            $user = new \stdClass();
            $user->username = $username;
            $generatedPassword = $notify ? generate_password() : null;
            $user->password = $notify ? $generatedPassword : $password;
            $user->auth = $auth;
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->email = $email ?? $username . '@example.com';
            $user->firstname = $firstname ?? $username;
            $user->lastname = $lastname ?? $username;

            if ($city !== null) {
                $user->city = $city;
            }
            if ($country !== null) {
                $user->country = $country;
            }
            if ($idnumber !== null) {
                $user->idnumber = $idnumber;
            }
            if ($institution !== null) {
                $user->institution = $institution;
            }
            if ($department !== null) {
                $user->department = $department;
            }

            $verbose->info("Creating user: $username");
            $id = user_create_user($user);
            $verbose->done("Created user $username with ID $id");

            if ($notify) {
                $createdUser = \core_user::get_user($id);
                set_user_preference('auth_forcepasswordchange', 1, $createdUser);

                $supportUser = \core_user::get_support_user();
                $a = new \stdClass();
                $a->firstname   = $createdUser->firstname;
                $a->lastname    = $createdUser->lastname;
                $a->sitename    = format_string($SITE->fullname);
                $a->username    = $createdUser->username;
                $a->newpassword = $generatedPassword;
                $a->link        = $CFG->wwwroot . '/login/';
                $a->signoff     = generate_email_signoff();

                $subject    = format_string($SITE->fullname) . ': ' . get_string('newusernewpasswordsubj');
                $messageraw = get_string('newusernewpasswordtext', '', $a);
                $messagehtml = text_to_html($messageraw, false, false, true);
                $messageplain = html_to_text($messagehtml);

                if (email_to_user($createdUser, $supportUser, $subject, $messageplain, $messagehtml)) {
                    $verbose->info("Welcome email sent to {$user->email}");
                } else {
                    $output->writeln("<comment>Warning: failed to send welcome email to {$user->email}</comment>");
                }

                $rows[] = [$id, $username, $user->email, $generatedPassword];
            } else {
                $rows[] = [$id, $username, $user->email];
            }
        }

        $formatter = new ResultFormatter($output, $format);
        $formatter->display($headers, $rows);

        return Command::SUCCESS;
    }
}
