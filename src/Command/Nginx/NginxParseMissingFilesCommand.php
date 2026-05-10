<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Nginx;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scan an Nginx access log (combined format) for 404s on /file.php/... URLs
 * and report how many times each missing file was requested.
 */
class NginxParseMissingFilesCommand extends BaseCommand
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
            ->setName('nginx:parse-missing-files')
            ->setDescription('Find Moodle file.php URLs that returned 404 in an Nginx access log')
            ->setHelp(<<<'HELP'
                Streams an Nginx access log and reports every GET /file.php/... URL that
                returned HTTP 404, grouped by path with an occurrence count.

                Defaults to the Nginx "combined" log format. For custom log_format
                directives, pass the literal log_format value from nginx.conf via
                --log-format (with $variables intact).

                Output is one "count,path" line per missing file, in first-seen order.
                Lines that do not match the configured format are skipped silently.

                Use --after to limit the scan to a date range. The value is parsed with
                strtotime(), so anything from "2024-01-01" to "1 week ago" works.

                Does not bootstrap Moodle — runs anywhere with no Moodle config required.
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
        return new NginxParseMissingFiles52Handler();
    }
}
