<?php

namespace Moosh2\Command\Plugin;

use Moosh2\Service\PhpMusselSignatureManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginPhpmuslescanUpdateSignaturesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('plugin:phpmuslescan:update-signatures')
            ->setDescription('Download or update phpMussel signature files used by plugin:phpmuslescan')
            ->setHelp('Downloads phpMussel signature files (phpmussel.hdb, phpmussel.ndb, phpmussel.db, phpmussel.fdb) into ~/.moosh2/phpmussel-signatures/. Files are served gzipped by GitHub and are decompressed on download; the phpMussel header is preserved because phpMussel\'s loader requires it. A phpmussel.ini is generated alongside the signatures to activate them — without it, phpMussel loads no signatures and every scan reports clean. The directory is created automatically on first run and requires no root access. Returns a non-zero exit code if any signature file fails to download. Requires network access to raw.githubusercontent.com.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = new PhpMusselSignatureManager();
        $output->writeln('Updating phpMussel signatures in ' . $manager->getSignatureDir());

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
            $output->writeln('All phpMussel signatures updated successfully.');
            $output->writeln('Config written to ' . $manager->getConfigPath());
        }

        return $exitCode;
    }
}