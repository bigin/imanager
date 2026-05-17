<?php

declare(strict_types=1);

namespace Imanager\Cli\Command;

use Imanager\Cli\Support\DatabaseFactory;
use Imanager\Search\FullTextSearch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'fts:rebuild',
    description: 'Drop and rebuild the full-text search index from items',
)]
final class FtsRebuildCommand extends Command
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

        // Apply pending migrations first — rebuilding against a stale
        // schema (e.g. before 2.2.0's migration 0005 has run) silently
        // produces an incorrect index.
        DatabaseFactory::migrateIfNeeded($pdo, $output);

        $search = new FullTextSearch($pdo);
        $search->rebuild();

        $stmt = $pdo->query('SELECT COUNT(*) FROM items_fts');
        $count = $stmt === false ? 0 : (int) $stmt->fetchColumn();
        $io->success(\sprintf('FTS index rebuilt; %d row(s) indexed.', $count));
        return Command::SUCCESS;
    }
}
