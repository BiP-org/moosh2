<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Plugin;

use Moosh2\Command\BaseHandler;
use Moosh2\Service\HttpRequestException;
use Moosh2\Service\MarketplaceReleaseNotes;
use Moosh2\Service\MarketplaceReleaseNotesClient;
use Moosh2\Service\MarketplaceScrapeException;
use Moosh2\Service\PluginApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginReleaseNotes52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument('plugin', InputArgument::REQUIRED, 'Frankenstyle plugin name (e.g. atto_wiris)')
            ->addArgument('version', InputArgument::REQUIRED, 'Exact plugin version build number to show notes up to (e.g. 2025041400)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, "Also include every version strictly newer than this build number and up to <version> (e.g. the version last deployed) - useful when a run may have skipped several releases; the plugin's known version list comes from moodle.org's plugins.json, same as plugin:list-update.")
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port)')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Moodle Marketplace API token, sent as a Bearer token. Defaults to env var MOODLE_MARKETPLACE_TOKEN.');

        $command->addExampleUsage('Show release notes for one version', 'atto_wiris 2025041400');
        $command->addExampleUsage('Machine-readable output for CI', 'atto_wiris 2025041400 --format=json');
        $command->addExampleUsage('Show every version skipped between a deployed build and the latest', 'atto_wiris 2025041400 --since=2024110400');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $component = $input->getArgument('plugin');
        $targetVersion = (string) $input->getArgument('version');
        $since = $input->getOption('since');
        $format = $input->getOption('format');
        $proxy = $input->getOption('proxy');
        $token = $input->getOption('token') ?: (getenv('MOODLE_MARKETPLACE_TOKEN') ?: null);

        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln("<error>Invalid --format '$format'. Use 'text' or 'json'.</error>");
            return Command::FAILURE;
        }

        $client = new MarketplaceReleaseNotesClient($proxy, $token);

        $versions = [$targetVersion];
        if ($since !== null) {
            $since = (string) $since;

            if ((int) $since >= (int) $targetVersion) {
                $output->writeln("No versions of '$component' between $since and $targetVersion (nothing newer than --since).");
                return Command::SUCCESS;
            }

            try {
                $apiClient = new PluginApiClient($proxy, $token);
                $plugin = $apiClient->findPlugin($component);
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . self::describeHttpFailure($e) . '</error>');
                return Command::FAILURE;
            }

            if ($plugin === null) {
                $output->writeln("<error>Plugin '$component' not found in the moodle.org plugin directory.</error>");
                return Command::FAILURE;
            }

            $inRange = self::selectVersionsInRange($plugin->versions, $since, $targetVersion);
            // If plugins.json doesn't list anything strictly between since and
            // target (e.g. a very new plugin, or plugins.json only ever
            // carries the latest version), fall back to just the target
            // version rather than showing nothing.
            $versions = $inRange !== [] ? $inRange : [$targetVersion];
        }

        $results = [];
        foreach ($versions as $buildNumber) {
            try {
                $results[] = $client->getReleaseNotes($component, $buildNumber);
            } catch (MarketplaceScrapeException) {
                // Expected for older versions Moodle Marketplace doesn't
                // list on the page even with ?show=all - record a
                // placeholder rather than failing the whole range.
                $results[] = new MarketplaceReleaseNotes(
                    releaseName: '(unknown)',
                    buildNumber: $buildNumber,
                    maturity: null,
                    supportedMoodle: null,
                    notes: '(release notes not available on Moodle Marketplace for this version)',
                );
            } catch (HttpRequestException $e) {
                // A real network/HTTP failure will fail identically for
                // every remaining version in the loop - no point
                // continuing, and silently swallowing it here would mask
                // an outage as "no notes available".
                $output->writeln('<error>' . self::describeHttpFailure($e) . '</error>');
                return Command::FAILURE;
            }
        }

        if ($format === 'json') {
            $payload = array_map(
                static fn (MarketplaceReleaseNotes $n): array => [
                    'version' => $n->buildNumber,
                    'release' => $n->releaseName,
                    'maturity' => $n->maturity,
                    'supportedMoodle' => $n->supportedMoodle,
                    'releaseNotes' => $n->notes,
                ],
                $results,
            );

            $output->writeln(json_encode(
                $since !== null
                    ? ['component' => $component, 'since' => $since, 'version' => $targetVersion, 'versions' => $payload]
                    : ['component' => $component] + $payload[0],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
            return Command::SUCCESS;
        }

        if ($since !== null) {
            $count = count($results);
            $output->writeln(sprintf(
                '%s: %d version%s between %s and %s',
                $component,
                $count,
                $count === 1 ? '' : 's',
                $since,
                $targetVersion,
            ));
            $output->writeln('');
        }

        foreach ($results as $i => $notes) {
            if ($since === null) {
                $output->writeln("$component {$notes->releaseName} ({$notes->buildNumber})");
            } else {
                $output->writeln("## {$notes->releaseName} ({$notes->buildNumber})");
            }
            if ($notes->maturity !== null) {
                $output->writeln("Maturity: {$notes->maturity}");
            }
            if ($notes->supportedMoodle !== null) {
                $output->writeln("Supported Moodle versions: {$notes->supportedMoodle}");
            }
            $output->writeln('');
            $output->writeln($notes->notes !== '' ? $notes->notes : '(no release notes provided for this version)');
            if ($i < count($results) - 1) {
                $output->writeln('');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Build numbers strictly greater than $since and up to and including
     * $upto, sorted ascending (oldest first) - the order a changelog
     * reads in.
     *
     * @param list<object{version: int|string}> $versions plugins.json's
     *   $plugin->versions array (may contain duplicate build numbers
     *   across differing Moodle-compatibility entries - deduplicated here)
     * @return list<string>
     */
    private static function selectVersionsInRange(array $versions, string $since, string $upto): array
    {
        $sinceInt = (int) $since;
        $uptoInt = (int) $upto;

        $inRange = [];
        foreach ($versions as $version) {
            $v = (int) $version->version;
            if ($v > $sinceInt && $v <= $uptoInt) {
                $inRange[(string) $v] = true;
            }
        }

        // array_keys() on a numeric-string-keyed array returns ints (PHP
        // auto-casts numeric string keys), so cast back to strings after
        // sorting numerically.
        $result = array_keys($inRange);
        sort($result, SORT_NUMERIC);

        return array_map('strval', $result);
    }

    private static function describeHttpFailure(\RuntimeException $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof HttpRequestException && $e->isRateLimited()) {
            $message .= ' - Moodle Marketplace is rate-limiting requests; wait a bit and try again.';
        }

        return $message;
    }
}
