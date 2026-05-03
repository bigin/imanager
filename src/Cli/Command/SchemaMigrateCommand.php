<?php

declare(strict_types=1);

namespace Imanager\Cli\Command;

use Imanager\Cli\Support\DatabaseFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'schema:migrate',
    description: 'Apply every pending schema migration to the target database',
)]
final class SchemaMigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite database path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $db = $input->getOption('db');
        if (! \is_string($db) || $db === '') {
            $io->error('--db is required');
            return Command::INVALID;
        }

        $pdo = DatabaseFactory::connect($db);
        $manager = DatabaseFactory::schemaManager($pdo);

        $pending = $manager->pending();
        if ($pending === []) {
            $io->success('Schema is already up to date.');
            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('<comment>Applying %d migration(s)…</comment>', \count($pending)));
        foreach ($pending as $migration) {
            $io->writeln(\sprintf('  %04d — %s', $migration->version(), $migration->description()));
        }

        $applied = $manager->migrate();
        $io->success(\sprintf('Applied %d migration(s); current version is %d.', $applied, $manager->currentVersion()));
        return Command::SUCCESS;
    }
}
