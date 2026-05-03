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
    name: 'schema:status',
    description: 'Show the applied schema version and any pending migrations',
)]
final class SchemaStatusCommand extends Command
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

        $io->writeln(\sprintf('<info>Current version:</info> %d', $manager->currentVersion()));

        $pending = $manager->pending();
        if ($pending === []) {
            $io->writeln('<info>No pending migrations.</info>');
            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('<comment>Pending migrations (%d):</comment>', \count($pending)));
        foreach ($pending as $migration) {
            $io->writeln(\sprintf('  %04d — %s', $migration->version(), $migration->description()));
        }
        return Command::SUCCESS;
    }
}
