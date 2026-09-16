<?php

namespace Moosh2\Command\Plugin;

use Moosh2\Service\ClamavSignatureManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginClamscanUpdateSignaturesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('plugin:clamscan:update-signatures')
            ->setDescription('Download or update additional ClamAV signatures used by plugin:clamscan')
            ->setHelp('Downloads ClamAV signature files from InterServer (interserver256.hdb, interservertopline.db, shell.ldb, whitelist.fp) into ~/.moosh2/clamav-signatures/. The directory is created automatically on first run and requires no root access. Returns a non-zero exit code if any signature file fails to download. Requires network access to sigs.interserver.net.')
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = new ClamavSignatureManager();
        $output->writeln('Updating signatures in ' . $manager->getSignatureDir());

        $results = $manager->update();
        $exitCode = Command::SUCCESS;

        foreach ($results as $filename => $status) {
            if (str_starts_with($status, 'FAILED')) {
                $output->writeln("  [FAIL] $filename: $status");
                $exitCode = Command::FAILURE;
            } else {
                $output->writeln("  [ OK ] $filename: $status");
            }
        }

        if ($exitCode === Command::SUCCESS) {
            $output->writeln('All signatures updated successfully.');
        }

        return $exitCode;
    }
}