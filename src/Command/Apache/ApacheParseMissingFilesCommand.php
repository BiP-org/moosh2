<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Apache;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scan an Apache combined-format access log for 404s on /file.php/... URLs
 * and report how many times each missing file was requested.
 */
class ApacheParseMissingFilesCommand extends BaseCommand
{
    protected BootstrapLevel $bootstrapLevel = BootstrapLevel::None;

    private BaseHandler $handler;

    public function __construct(?MoodleVersion $moodleVersion)
    {
        $this->handler = new ApacheParseMissingFiles52Handler();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('apache:parse-missing-files')
            ->setDescription('Find Moodle file.php URLs that returned 404 in an Apache access log')
            ->setHelp(<<<'HELP'
                Streams an Apache access log in the standard combined format and reports
                every GET /file.php/... URL that returned HTTP 404, grouped by path with
                an occurrence count.

                Output is one "count,path" line per missing file, in first-seen order.
                Lines that do not match the combined format are skipped silently.

                Use --after to limit the scan to a date range. The value is parsed with
                strtotime(), so anything from "2024-01-01" to "1 week ago" works.

                Does not bootstrap Moodle — runs anywhere with no Moodle config required.
                HELP);

        $this->handler->configureCommand($this);

        $this->addExampleUsage(
            'Report missing files from a combined-format log',
            '/var/log/apache2/moodle-access.log',
        );
        $this->addExampleUsage(
            'Only consider entries from the last week',
            '--after="1 week ago" /var/log/apache2/moodle-access.log',
        );
    }

    protected function getActiveHandler(): BaseHandler
    {
        return $this->handler;
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }
}
