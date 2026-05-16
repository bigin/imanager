<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Exception\StorageException;

/**
 * Builds a fully configured PDO connection to a SQLite database.
 *
 * The PRAGMAs applied here are the ones declared in the iManager 2.0 plan
 * (§6): foreign keys on, WAL journaling, NORMAL synchronous mode, in-memory
 * temp store. WAL is silently a no-op for `:memory:` databases — that's
 * intentional and fine for tests.
 */
final readonly class ConnectionFactory
{
    public function __construct(private string $databasePath) {}

    public static function inMemory(): self
    {
        return new self(':memory:');
    }

    public function create(): \PDO
    {
        try {
            $pdo = new \PDO('sqlite:' . $this->databasePath);
        } catch (\PDOException $e) {
            $parent = \dirname($this->databasePath);
            if ($this->databasePath !== ':memory:' && ! is_dir($parent)) {
                throw StorageException::fromPdo($e, \sprintf(
                    'Failed to open SQLite database at %s — parent directory %s does not exist (SQLite creates the .db file, not its directory). Create it first (e.g. `mkdir -p %s`) or boot via Imanager\\DefaultBootstrap, which mkdirs on your behalf.',
                    $this->databasePath,
                    $parent,
                    $parent,
                ));
            }
            throw StorageException::fromPdo($e, 'Failed to open SQLite database');
        }

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        return $pdo;
    }
}
