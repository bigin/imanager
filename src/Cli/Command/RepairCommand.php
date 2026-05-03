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
    name: 'repair',
    description: 'Run integrity_check and foreign_key_check; report findings',
)]
final class RepairCommand extends Command
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

        $io->section('PRAGMA integrity_check');
        $integrity = self::fetchColumn($pdo, 'PRAGMA integrity_check');
        foreach ($integrity as $line) {
            $io->writeln('  ' . $line);
        }
        $integrityOk = $integrity === ['ok'];

        $io->section('PRAGMA foreign_key_check');
        $fkRows = self::fetchAssoc($pdo, 'PRAGMA foreign_key_check');
        if ($fkRows === []) {
            $io->writeln('  <info>no violations</info>');
        } else {
            foreach ($fkRows as $row) {
                $io->writeln('  ' . self::formatFkRow($row));
            }
        }
        $fkOk = $fkRows === [];

        if ($integrityOk && $fkOk) {
            $io->success('Database is healthy.');
            return Command::SUCCESS;
        }

        $io->error('Database has issues; see report above.');
        return Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private static function fetchColumn(\PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            if (\is_string($value) || \is_int($value)) {
                $out[] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchAssoc(\PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (\is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function formatFkRow(array $row): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            $parts[] = $key . '=' . (\is_scalar($value) ? (string) $value : get_debug_type($value));
        }
        return implode(' ', $parts);
    }
}
