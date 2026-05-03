<?php

declare(strict_types=1);

namespace Imanager\Cli\Command;

use Imanager\Cli\Support\DatabaseFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dump',
    description: 'Emit a SQL dump of the database to stdout (pipe to a file)',
)]
final class DumpCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite database path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $input->getOption('db');
        if (! \is_string($db) || $db === '') {
            $output->writeln('<error>--db is required</error>');
            return Command::INVALID;
        }

        $pdo = DatabaseFactory::connect($db);

        $output->writeln('PRAGMA foreign_keys = OFF;');
        $output->writeln('BEGIN TRANSACTION;');

        foreach (self::tables($pdo) as $table) {
            $createSql = self::createSql($pdo, $table);
            if ($createSql === null) {
                continue;
            }
            $output->writeln('-- Table: ' . $table);
            $output->writeln($createSql . ';');
            foreach (self::rows($pdo, $table) as $row) {
                $output->writeln(self::insertStatement($pdo, $table, $row));
            }
            $output->writeln('');
        }

        $output->writeln('COMMIT;');
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function tables(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' "
                . "AND name NOT LIKE 'sqlite_%' "
                . "AND name NOT LIKE '%_fts%' "
                . 'ORDER BY name',
        );
        if ($stmt === false) {
            return [];
        }
        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if (\is_string($name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function createSql(\PDO $pdo, string $table): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name = :name",
        );
        $stmt->execute([':name' => $table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ($row !== false && \is_string($row['sql'] ?? null)) ? $row['sql'] : null;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private static function rows(\PDO $pdo, string $table): iterable
    {
        $stmt = $pdo->query('SELECT * FROM ' . self::quoteIdentifier($table));
        if ($stmt === false) {
            return [];
        }
        while (true) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            yield $row;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function insertStatement(\PDO $pdo, string $table, array $row): string
    {
        $columns = [];
        foreach (array_keys($row) as $column) {
            $columns[] = self::quoteIdentifier((string) $column);
        }
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }
            if (\is_int($value) || \is_float($value)) {
                $values[] = (string) $value;
                continue;
            }
            $values[] = $pdo->quote((string) $value);
        }
        return \sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            self::quoteIdentifier($table),
            implode(', ', $columns),
            implode(', ', $values),
        );
    }

    private static function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
