<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Make;

use InvalidArgumentException;
use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\Make\Builder;
use Moosh2\Service\Make\ManifestParser;
use Moosh2\Service\PluginApiClient;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads a manifest, then either prints the plan (default) or assembles the
 * codebase under <destination> (with --run).
 */
class MakeBuild52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument(
                'manifest',
                InputArgument::REQUIRED,
                'Path to the make manifest (INI format)',
            )
            ->addArgument(
                'destination',
                InputArgument::REQUIRED,
                'Target directory for the assembled codebase (must be empty or non-existent)',
            )
            ->addOption(
                'proxy',
                null,
                InputOption::VALUE_REQUIRED,
                'Proxy URI for downloading plugins (e.g. tcp://user:pass@host:port)',
            );

        if ($command instanceof BaseCommand) {
            $command->addExampleUsage(
                'Preview the plan without writing anything',
                'site.make /tmp/moodle-new',
            );
            $command->addExampleUsage(
                'Build for real',
                'site.make /tmp/moodle-new --run',
            );
            $command->addExampleUsage(
                'Use a corporate proxy when downloading plugins',
                'site.make /tmp/moodle-new --proxy=http://user:pass@proxy:8080 --run',
            );
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $manifestPath = $input->getArgument('manifest');
        $destination = $input->getArgument('destination');
        $proxy = $input->getOption('proxy');
        $run = (bool) $input->getOption('run');

        try {
            $manifest = (new ManifestParser())->parseFile($manifestPath);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $builder = new Builder(new PluginApiClient($proxy));

        if (!$run) {
            $output->writeln('<comment>DRY RUN — pass --run to execute</comment>');
            $output->writeln('');
            foreach ($builder->describePlan($manifest, $destination) as $line) {
                $output->writeln($line);
            }
            return Command::SUCCESS;
        }

        try {
            $builder->assertDestinationUsable($destination);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $builder->run($manifest, $destination, $output);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>Done.</info> Codebase assembled at ' . rtrim($destination, '/'));
        return Command::SUCCESS;
    }
}
