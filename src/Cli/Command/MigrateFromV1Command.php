<?php

declare(strict_types=1);

namespace Imanager\Cli\Command;

use Imanager\Cli\Support\DatabaseFactory;
use Imanager\Migration\JsonV1Importer;
use Imanager\Migration\V1FileParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate:from-v1',
    description: 'Migrate iManager 1.x flat-file data into a 2.0 SQLite database',
)]
final class MigrateFromV1Command extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, '1.x data/ directory')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target SQLite database path')
            ->addOption('upload-target', null, InputOption::VALUE_OPTIONAL, 'Where to copy uploads/ (defaults to <target-dir>/uploads)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate only; roll the transaction back at the end');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getOption('source');
        $target = $input->getOption('target');
        if (! \is_string($source) || $source === '') {
            $io->error('--source is required');
            return Command::INVALID;
        }
        if (! \is_string($target) || $target === '') {
            $io->error('--target is required');
            return Command::INVALID;
        }
        if (! is_dir($source)) {
            $io->error(\sprintf('Source directory "%s" does not exist', $source));
            return Command::FAILURE;
        }

        $uploadTarget = $input->getOption('upload-target');
        if (! \is_string($uploadTarget) || $uploadTarget === '') {
            $uploadTarget = \dirname($target) . '/uploads';
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title($dryRun ? 'iManager migration — dry run' : 'iManager migration');
        $io->text([
            "Source:        {$source}",
            "Target DB:     {$target}",
            "Upload target: {$uploadTarget}",
        ]);

        $pdo = DatabaseFactory::connect($target);
        $manager = DatabaseFactory::schemaManager($pdo);
        $applied = $manager->migrate();
        if ($applied > 0) {
            $io->note(\sprintf('Applied %d schema migration(s) to target', $applied));
        }

        $storage = DatabaseFactory::storage($pdo);
        $importer = new JsonV1Importer(new V1FileParser(), $storage);

        $report = $importer->import($source, $uploadTarget, $dryRun);

        $io->section('Result');
        $io->definitionList(
            ['Categories' => (string) $report->categoriesImported],
            ['Fields'     => (string) $report->fieldsImported],
            ['Items'      => (string) $report->itemsImported],
            ['Assets'     => (string) $report->assetsCopied],
            ['Errors'     => (string) \count($report->errors)],
            ['Warnings'   => (string) \count($report->warnings)],
            ['Rolled back' => $report->rolledBack ? 'yes' : 'no'],
        );

        if ($report->warnings !== []) {
            $io->section('Warnings');
            foreach ($report->warnings as $warning) {
                $io->writeln('  - ' . $warning);
            }
        }

        if ($report->hasErrors()) {
            $io->section('Errors');
            foreach ($report->errors as $error) {
                $io->writeln('  - ' . $error);
            }
            return Command::FAILURE;
        }

        $io->success($report->summary());
        return Command::SUCCESS;
    }
}
