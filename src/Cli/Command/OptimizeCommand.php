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
    name: 'optimize',
    description: 'Run PRAGMA optimize and (optionally) VACUUM on the database',
)]
final class OptimizeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite database path')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Also run VACUUM (rewrites the file; can take a while)');
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

        $io->writeln('<info>Running PRAGMA optimize…</info>');
        $pdo->exec('PRAGMA optimize');

        if ($input->getOption('vacuum')) {
            $io->writeln('<info>Running VACUUM…</info>');
            $pdo->exec('VACUUM');
        }

        $io->success('Optimization complete.');
        return Command::SUCCESS;
    }
}
