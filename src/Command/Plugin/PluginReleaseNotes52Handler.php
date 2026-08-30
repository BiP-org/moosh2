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
use Moosh2\Service\MarketplaceReleaseNotesClient;
use Moosh2\Service\MarketplaceScrapeException;
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
            ->addArgument('version', InputArgument::REQUIRED, 'Exact plugin version build number (e.g. 2025041400)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port)')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Moodle Marketplace API token, sent as a Bearer token. Defaults to env var MOODLE_MARKETPLACE_TOKEN.');

        $command->addExampleUsage('Show release notes for one version', 'atto_wiris 2025041400');
        $command->addExampleUsage('Machine-readable output for CI', 'atto_wiris 2025041400 --format=json');
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $component = $input->getArgument('plugin');
        $buildNumber = $input->getArgument('version');
        $format = $input->getOption('format');
        $proxy = $input->getOption('proxy');
        $token = $input->getOption('token') ?: (getenv('MOODLE_MARKETPLACE_TOKEN') ?: null);

        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln("<error>Invalid --format '$format'. Use 'text' or 'json'.</error>");
            return Command::FAILURE;
        }

        $client = new MarketplaceReleaseNotesClient($proxy, $token);

        try {
            $notes = $client->getReleaseNotes($component, (string) $buildNumber);
        } catch (HttpRequestException $e) {
            $output->writeln('<error>' . self::describeHttpFailure($e) . '</error>');
            return Command::FAILURE;
        } catch (MarketplaceScrapeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln(json_encode(
                [
                    'component' => $component,
                    'version' => $notes->buildNumber,
                    'release' => $notes->releaseName,
                    'maturity' => $notes->maturity,
                    'supportedMoodle' => $notes->supportedMoodle,
                    'releaseNotes' => $notes->notes,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
            return Command::SUCCESS;
        }

        $output->writeln("$component {$notes->releaseName} ({$notes->buildNumber})");
        if ($notes->maturity !== null) {
            $output->writeln("Maturity: {$notes->maturity}");
        }
        if ($notes->supportedMoodle !== null) {
            $output->writeln("Supported Moodle versions: {$notes->supportedMoodle}");
        }
        $output->writeln('');
        $output->writeln($notes->notes !== '' ? $notes->notes : '(no release notes provided for this version)');

        return Command::SUCCESS;
    }

    private static function describeHttpFailure(HttpRequestException $e): string
    {
        $message = $e->getMessage();
        if ($e->isRateLimited()) {
            $message .= ' - Moodle Marketplace is rate-limiting requests; wait a bit and try again.';
        }

        return $message;
    }
}
