<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh2\Command\Moodle;

use Moosh2\Command\BaseCommand;
use Moosh2\Command\BaseHandler;
use Moosh2\Service\Moodle\MoodleReleaseResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MoodleDownload52Handler extends BaseHandler
{
    public function configureCommand(Command $command): void
    {
        $command
            ->addArgument(
                'version',
                InputArgument::OPTIONAL,
                'Moodle version: X.Y for the latest point release on that branch (e.g. 5.2), or X.Y.Z for an exact release. Omit for latest stable.',
            )
            ->addOption('url', null, InputOption::VALUE_NONE, 'Only display the download URL, do not download')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Proxy URI (e.g. tcp://user:pass@host:port)');

        if ($command instanceof BaseCommand) {
            $command->addExampleUsage('Download the latest stable Moodle', '');
            $command->addExampleUsage('Download the latest 5.2.x release', '5.2');
            $command->addExampleUsage('Download an exact point release', '5.2.1');
            $command->addExampleUsage('Print the download URL only', '5.2 --url');
        }
    }

    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $version = $input->getArgument('version');
        $urlOnly = (bool) $input->getOption('url');
        $proxy = $input->getOption('proxy');

        $resolver = new MoodleReleaseResolver($proxy);

        try {
            $resolved = $resolver->resolve($version);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($urlOnly) {
            $output->writeln($resolved->url);
            return Command::SUCCESS;
        }

        $targetFile = getcwd() . '/' . basename($resolved->url);

        try {
            $resolver->download($resolved->url, $targetFile);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('Downloaded:');
        $output->writeln('  release: ' . $resolved->label);
        $output->writeln('  url:     ' . $resolved->url);
        $output->writeln('  file:    ' . $targetFile);

        return Command::SUCCESS;
    }
}
