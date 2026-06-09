<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\File;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FileStatsCommand extends BaseCommand
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
            ->setName('file:stats')
            ->setDescription('Show file storage statistics')
            ->setHelp(<<<'HELP'
                Show file storage statistics.

                By default only the overall totals are shown. Add flags to
                include extra breakdowns, or --all to show every section.

                On installations with a very large {files} table some sections
                are inherently heavy: --top sorts on the unindexed filesize
                column, --by-area-component deduplicates the whole table, and
                --disk-usage walks the entire filedir with du. Enable them
                individually rather than relying on --all on such sites.

                Examples:
                  file:stats
                  file:stats --by-component
                  file:stats --by-filearea
                  file:stats --by-area-component
                  file:stats --by-course
                  file:stats --backups
                  file:stats --disk-usage
                  file:stats --all
                  file:stats --top=20
                HELP);
        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler { return $this->handler; }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $moodleVersion): BaseHandler
    {
        return new FileStats52Handler();
    }
}
