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
            $isVirtual = self::startsWithCi($createSql, 'CREATE VIRTUAL TABLE');
            $output->writeln('-- Table: ' . $table);
            $output->writeln($createSql . ';');
            foreach (self::rows($pdo, $table, $isVirtual) as $row) {
                $output->writeln(self::insertStatement($pdo, $table, $row));
            }
            $output->writeln('');
        }

        $output->writeln('COMMIT;');
        return Command::SUCCESS;
    }

    /**
     * Returns user-relevant table names: regular tables + virtual tables
     * (FTS5, RTREE, …) — but not the shadow tables a virtual-table module
     * auto-creates next to its parent (e.g. for an FTS5 table named
     * `items_fts`: `items_fts_data`, `items_fts_idx`, `items_fts_config`,
     * `items_fts_docsize`, `items_fts_content`). On the destination, the
     * shadows are re-created automatically when the parent virtual-table
     * CREATE runs — including them here would conflict on restore.
     *
     * Modern SQLite records CREATE statements for shadow tables in
     * sqlite_master, so a `sql IS NOT NULL` filter is not enough.
     * Instead we detect virtual-table parents first and exclude any
     * table whose name starts with `<parent>_`.
     *
     * @return list<string>
     */
    private static function tables(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type='table' "
                . "AND name NOT LIKE 'sqlite_%' "
                . 'AND sql IS NOT NULL '
                . 'ORDER BY name',
        );
        if ($stmt === false) {
            return [];
        }
        /** @var list<array{name: string, sql: string}> $candidates */
        $candidates = [];
        $virtuals = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $name = $row['name'] ?? null;
            $sql  = $row['sql']  ?? null;
            if (! \is_string($name) || ! \is_string($sql)) {
                continue;
            }
            $candidates[] = ['name' => $name, 'sql' => $sql];
            if (self::startsWithCi($sql, 'CREATE VIRTUAL TABLE')) {
                $virtuals[] = $name;
            }
        }

        $names = [];
        foreach ($candidates as $row) {
            if (self::isVirtualShadow($row['name'], $virtuals)) {
                continue;
            }
            $names[] = $row['name'];
        }
        return $names;
    }

    /**
     * @param list<string> $virtuals
     */
    private static function isVirtualShadow(string $name, array $virtuals): bool
    {
        foreach ($virtuals as $virtual) {
            if ($name === $virtual) {
                return false;
            }
            if (str_starts_with($name, $virtual . '_')) {
                return true;
            }
        }
        return false;
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
    private static function rows(\PDO $pdo, string $table, bool $isVirtual): iterable
    {
        // Virtual tables (FTS5, RTREE, …) hide their primary key behind the
        // implicit `rowid`, which `SELECT *` does not include. Without it
        // the dump round-trip would lose the link between e.g. items_fts
        // rows and the items they index. Regular tables expose their
        // INTEGER PRIMARY KEY through `*` so don't need the extra column.
        $sql = $isVirtual
            ? 'SELECT rowid AS rowid, * FROM ' . self::quoteIdentifier($table)
            : 'SELECT * FROM ' . self::quoteIdentifier($table);
        $stmt = $pdo->query($sql);
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

    private static function startsWithCi(string $haystack, string $needle): bool
    {
        return strncasecmp(ltrim($haystack), $needle, \strlen($needle)) === 0;
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
