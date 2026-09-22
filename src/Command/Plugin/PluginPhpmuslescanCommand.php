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

class PluginPhpmuslescanCommand extends BaseCommand
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
            ->setName('plugin:phpmuslescan')
            ->setDescription('Scan a plugin for malware using phpMussel')
            ->setHelp(
                'Scans either the plugin in the current directory (detected via version.php) ' .
                'or a plugin downloaded from the moodle.org plugin directory by frankenstyle name. ' .
                'Uses phpMussel\'s native signature formats via the Composer-installed phpmussel/core ' .
                'package. Signatures must be downloaded first with plugin:phpmuslescan:update-signatures. ' .
                'Exit codes mirror plugin:clamscan: 0 clean, 1 malware found, 2 error. ' .
                "\n\n" .
                'False positives can be whitelisted at three levels, all active at once: ' .
                'built-in (fixed, ships with moosh2 — known structural false positives such as ' .
                'moosh2\'s own .downloaded-non-core-plugin marker file), global ' .
                '(~/.moosh2/phpmuslescan-whitelist, applies to every scan), and per-plugin ' .
                '(.moosh-phpmuslescan-whitelist in the plugin\'s own root). --whitelist adds one more ' .
                'file on top, e.g. for a downloaded plugin you can\'t add a file into. ' .
                'Each is one entry per line, "#" for comments: "pattern" (relative to the ' .
                'plugin root) skips the whole file; "pattern | reason" only suppresses a detection ' .
                'whose message contains that reason as a substring, so anything else found on a ' .
                'matching file still fires. Patterns are globs by default ("*" within one path ' .
                'segment, "**" across any number of segments including zero — so "**/*.min.js" ' .
                'also matches a root-level file, not only a nested one), or prefix with "regex:" ' .
                'for a raw PCRE anchored to the whole relative path — e.g. "**/*.min.js | ' .
                'phpMussel-Suspect.DoubleExtension-00" for minified JS anywhere in the plugin, ' .
                'or "tests/behat/*.feature | PHP chameleon attack" for Gherkin scenarios.',
            );
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

    private function resolveHandler(?MoodleVersion $v): BaseHandler
    {
        return new PluginPhpmuslescan52Handler();
    }
}