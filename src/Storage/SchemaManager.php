<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Exception\SchemaException;

/**
 * Applies a sequence of forward migrations to a PDO connection idempotently.
 *
 * The schema's current version is tracked in a single-row `schema_version`
 * table that's created on demand. Calling {@see migrate()} repeatedly is a
 * no-op once all known migrations are applied.
 *
 * SQL dialect is intentionally minimal so SQLite, PostgreSQL and MySQL all
 * accept the housekeeping table; the migrations themselves can use any
 * dialect-specific feature they need.
 */
final class SchemaManager
{
    /**
     * @var list<Migration>
     */
    private array $migrations;

    /**
     * @param iterable<Migration> $migrations
     */
    public function __construct(
        private readonly \PDO $connection,
        iterable $migrations,
    ) {
        $sorted = [];
        foreach ($migrations as $m) {
            $sorted[] = $m;
        }
        usort($sorted, static fn(Migration $a, Migration $b): int => $a->version() <=> $b->version());

        $seen = [];
        foreach ($sorted as $m) {
            $v = $m->version();
            if (isset($seen[$v])) {
                throw new SchemaException(\sprintf(
                    'Duplicate migration version %d (descriptions: "%s" and "%s")',
                    $v,
                    $seen[$v],
                    $m->description(),
                ));
            }
            $seen[$v] = $m->description();
        }

        $this->migrations = $sorted;
    }

    /**
     * Highest applied migration version, or 0 if no migrations have run yet.
     */
    public function currentVersion(): int
    {
        $this->ensureRegistryTable();

        $stmt = $this->connection->query('SELECT MAX(version) AS v FROM schema_version');
        if ($stmt === false) {
            throw new SchemaException('Failed to read schema_version table');
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row['v'] === null) {
            return 0;
        }
        return (int) $row['v'];
    }

    /**
     * @return list<Migration>
     */
    public function pending(): array
    {
        $current = $this->currentVersion();
        $pending = [];
        foreach ($this->migrations as $m) {
            if ($m->version() > $current) {
                $pending[] = $m;
            }
        }
        return $pending;
    }

    /**
     * Apply every pending migration in version order. Returns the number of
     * migrations applied during this call.
     */
    public function migrate(): int
    {
        $applied = 0;
        foreach ($this->pending() as $m) {
            $this->applyOne($m);
            $applied++;
        }
        return $applied;
    }

    private function applyOne(Migration $migration): void
    {
        $started = $this->connection->beginTransaction();

        try {
            $migration->apply($this->connection);

            $stmt = $this->connection->prepare(
                'INSERT INTO schema_version (version, description, applied_at) VALUES (:v, :d, :t)',
            );
            $stmt->execute([
                ':v' => $migration->version(),
                ':d' => $migration->description(),
                ':t' => time(),
            ]);

            if ($started) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw SchemaException::migrationFailed(
                $migration->version(),
                $e->getMessage(),
                $e,
            );
        }
    }

    private function ensureRegistryTable(): void
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS schema_version (
                version     INTEGER PRIMARY KEY,
                description TEXT    NOT NULL,
                applied_at  INTEGER NOT NULL
            )',
        );
    }
}
