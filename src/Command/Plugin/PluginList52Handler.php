<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Command\BaseHandler;
use Moosh2\Output\ResultFormatter;
use Moosh2\Service\PluginApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginList52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by plugin type prefix (e.g. mod, block, auth)')
            ->addOption('name-only', null, InputOption::VALUE_NONE, 'Display only frankenstyle plugin names')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Force re-download of the plugin cache')
            ->addOption('ensure-cache', null, InputOption::VALUE_NONE, 'Refresh plugins.json only if missing or stale, then exit (preflight for scripts)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw plugins.json from the moodle.org API (for piping to jq)')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port)');

        $command->addExampleUsage('List all available plugins', '');
        $command->addExampleUsage('List only activity module plugins', '--type=mod');
        $command->addExampleUsage('List plugin names only', '--name-only');
        $command->addExampleUsage('Refresh cached plugin list', '--refresh');
        $command->addExampleUsage('Preflight cache check for scripts (refresh if stale, no listing)', '--ensure-cache');
        $command->addExampleUsage('Pipe raw plugin directory JSON to jq', "--json | jq '.plugins[] | select(.component==\"mod_attendance\")'");
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('output');
        $typeFilter = $input->getOption('type');
        $nameOnly = $input->getOption('name-only');
        $refresh = $input->getOption('refresh');
        $ensureCache = $input->getOption('ensure-cache');
        $rawJson = $input->getOption('json');
        $proxy = $input->getOption('proxy');

        $client = new PluginApiClient($proxy);

        if ($ensureCache) {
            $refreshed = $client->ensureCacheFresh($refresh);
            $cachePath = PluginApiClient::getCachePath();
            $output->writeln($refreshed
                ? "Plugin cache refreshed: $cachePath"
                : "Plugin cache is up to date: $cachePath");
            return Command::SUCCESS;
        }

        if ($rawJson) {
            $client->ensureCacheFresh($refresh);
            $cachePath = PluginApiClient::getCachePath();
            $content = file_get_contents($cachePath);
            if ($content === false) {
                throw new \RuntimeException("Cannot read cache file: $cachePath");
            }
            $output->write($content, false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $data = $client->getPluginList($refresh);

        $rows = [];

        foreach ($data->plugins as $plugin) {
            if (empty($plugin->component)) {
                continue;
            }

            if ($typeFilter !== null) {
                $pluginType = explode('_', $plugin->component, 2)[0];
                if ($pluginType !== $typeFilter) {
                    continue;
                }
            }

            if ($nameOnly) {
                $output->writeln($plugin->component);
                continue;
            }

            $bestPerRelease = [];
            foreach ($plugin->versions as $version) {
                foreach ($version->supportedmoodles as $supported) {
                    $release = (string) $supported->release;
                    if (!isset($bestPerRelease[$release])
                        || $version->version > $bestPerRelease[$release]->version
                    ) {
                        $bestPerRelease[$release] = $version;
                    }
                }
            }

            $releases = array_keys($bestPerRelease);
            usort($releases, 'version_compare');

            foreach ($releases as $release) {
                $rows[] = [
                    'component' => $plugin->component,
                    'moodle_version' => $release,
                    'url' => $bestPerRelease[$release]->downloadurl,
                ];
            }
        }

        if ($nameOnly) {
            return Command::SUCCESS;
        }

        usort($rows, function (array $a, array $b): int {
            $cmp = strcmp($a['component'], $b['component']);
            return $cmp !== 0 ? $cmp : version_compare($a['moodle_version'], $b['moodle_version']);
        });

        $formatter = new ResultFormatter($output, $format);
        $formatter->display(['component', 'moodle_version', 'url'], $rows);

        return Command::SUCCESS;
    }
}
