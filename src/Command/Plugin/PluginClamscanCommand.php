<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Bootstrap\BootstrapLevel;
use Moosh2\Bootstrap\MoodleVersion;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginClamscanCommand extends BaseCommand
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
            ->setName('plugin:clamscan')
            ->setDescription('Scan a plugin for malware using a custom ClamAV/YARA ruleset')
            ->setHelp(
                'Scans either the plugin in the current directory (detected via version.php) ' .
                'or a plugin downloaded from the moodle.org plugin directory by frankenstyle name. ' .
                'Exit codes mirror clamscan itself: 0 clean, 1 malware found, 2 error. ' .
                "\n\n" .
                'False positives can be whitelisted at three levels, all active at once: ' .
                'built-in (fixed, ships with moosh2), global (~/.moosh2/clamscan-whitelist, ' .
                'applies to every scan), and per-plugin (.moosh-clamscan-whitelist in the ' .
                'plugin\'s own root). --whitelist adds one more file on top. Every file is ' .
                'still scanned by clamscan itself; a matching detection is filtered out of the ' .
                'results afterward (unlike plugin:phpmuslescan, which can skip a whole-file ' .
                'entry before scanning). Each is one entry per line, "#" for comments: ' .
                '"pattern" suppresses every detection on a matching file; "pattern | reason" ' .
                'only suppresses a detection whose signature name contains that reason as a ' .
                'substring, so anything else found on a matching file still fires. Patterns ' .
                'are globs by default ("*" within one path segment, "**" across any number ' .
                'including zero), or prefix with "regex:" for a raw PCRE anchored to the ' .
                'whole relative path — e.g. "vendor/jsdiff.min.js | Eicar-Test-Signature" or ' .
                '"**/*.min.js | Some-Noisy-Heuristic-Signature".',
            );
        $this->handler->configureCommand($this);
    }

    protected function getActiveHandler(): BaseHandler { return $this->handler; }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        return $this->handler->handle($input, $output);
    }

    private function resolveHandler(?MoodleVersion $v): BaseHandler
    {
        return new PluginClamscan52Handler();
    }
}
