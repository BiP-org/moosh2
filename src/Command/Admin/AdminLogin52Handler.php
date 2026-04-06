<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Admin;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Output\VerboseLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * admin:login implementation for Moodle 5.1.
 */
class AdminLogin52Handler extends BaseHandler
{
    public function handle(InputInterface $input, OutputInterface $output): int
    {
        global $CFG;

        $verbose = new VerboseLogger($output);
        $format = $input->getOption('output');

        $verbose->step('Loading Moodle libraries');
        require_once $CFG->libdir . '/datalib.php';

        // Get admin user.
        $verbose->step('Looking up admin user');
        $user = get_admin();
        if (!$user) {
            $output->writeln('<error>Unable to find admin user in database.</error>');
            return Command::FAILURE;
        }

        $verbose->detail('Admin user', $user->username . ' (ID: ' . $user->id . ')');

        // Validate authentication method.
        $auth = empty($user->auth) ? 'manual' : $user->auth;
        if ($auth === 'nologin' || !is_enabled_auth($auth)) {
            $output->writeln(sprintf(
                '<error>User authentication is either "nologin" or disabled. Check Moodle authentication method for "%s".</error>',
                $user->username,
            ));
            return Command::FAILURE;
        }

        $verbose->detail('Auth method', $auth);

        $webLogin = $input->getOption('web-login');

        // When using file-based sessions, PHP refuses to read session files
        // not owned by the running process uid. Detect the mismatch early.
        if (ini_get('session.save_handler') === 'files') {
            $datarootOwner = fileowner($CFG->dataroot);
            if ($datarootOwner !== false && $datarootOwner !== posix_getuid()) {
                $ownerInfo = posix_getpwuid($datarootOwner);
                $ownerName = $ownerInfo['name'] ?? "uid $datarootOwner";
                if (!$webLogin) {
                    $output->writeln(sprintf(
                        '<error>Moodle data is owned by "%s" but you are running as "%s".</error>',
                        $ownerName,
                        posix_getpwuid(posix_getuid())['name'] ?? 'uid ' . posix_getuid(),
                    ));
                    $output->writeln(sprintf(
                        '<error>Session files created by the wrong user cannot be used by the web server.</error>',
                    ));
                    $output->writeln(sprintf(
                        '<error>Run as the web server user:  sudo -u %s moosh admin:login ...</error>',
                        $ownerName,
                    ));
                    $output->writeln(sprintf(
                        '<error>Or use --web-login to create the session via the web server.</error>',
                    ));
                    return Command::FAILURE;
                }
            }
        }

        if ($webLogin) {
            $verbose->step('Creating session via web server');
            $result = $this->loginViaWeb($user, $CFG, $verbose);
            if ($result === null) {
                $output->writeln('<error>Failed to create session via web server. Make sure the web server is running at ' . $CFG->wwwroot . '</error>');
                return Command::FAILURE;
            }
            [$sessionName, $sessionId] = $result;
        } else {
            $verbose->step('Authenticating admin user');
            $authPlugin = get_auth_plugin($auth);
            $authPlugin->sync_roles($user);
            login_attempt_valid($user);
            complete_user_login($user);
            session_write_close();

            $sessionName = session_name();
            $sessionId = session_id();
        }

        $verbose->done('Login successful');

        // Default output: simple cookie format for scripting.
        if ($format === 'table') {
            $output->writeln("$sessionName:$sessionId");
        } else {
            $formatter = new ResultFormatter($output, $format);
            $formatter->display(
                ['cookie_name', 'cookie_value'],
                [[$sessionName, $sessionId]],
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Create the session through the web server using a temporary helper script.
     *
     * PHP's file session handler refuses to read files not owned by the running
     * uid.  When the CLI user differs from the web server user, we must let the
     * web server create the session file so its uid matches.
     *
     * @return array{0: string, 1: string}|null  [sessionName, sessionId] or null on failure
     */
    private function loginViaWeb(\stdClass $user, \stdClass $CFG, VerboseLogger $verbose): ?array
    {
        $token = bin2hex(random_bytes(16));
        $userId = (int) $user->id;

        $helperCode = <<<'HELPER'
<?php
define('ABORT_AFTER_CONFIG_CANCEL', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/datalib.php');
if (!isset($_GET['t']) || $_GET['t'] !== '%TOKEN%') {
    http_response_code(403);
    die();
}
$user = $DB->get_record('user', ['id' => %USERID%]);
if (!$user) {
    http_response_code(500);
    die();
}
complete_user_login($user);
header('Content-Type: text/plain');
echo session_name() . ':' . session_id();
@unlink(__FILE__);
HELPER;

        $helperCode = str_replace(['%TOKEN%', '%USERID%'], [$token, (string) $userId], $helperCode);
        $helperFile = 'moosh_login_' . $token . '.php';
        $helperPath = $CFG->dirroot . '/' . $helperFile;

        $verbose->detail('Helper script', $helperPath);

        if (file_put_contents($helperPath, $helperCode) === false) {
            return null;
        }

        $url = $CFG->wwwroot . '/' . $helperFile . '?t=' . $token;
        $verbose->detail('Fetching', $url);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        // Clean up (in case the helper didn't delete itself).
        @unlink($helperPath);

        if ($response === false || !str_contains($response, ':')) {
            return null;
        }

        return explode(':', trim($response), 2);
    }
}
