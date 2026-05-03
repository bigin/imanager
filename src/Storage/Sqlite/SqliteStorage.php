<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Events\NullEventDispatcher;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\FileRepository;
use Imanager\Storage\ItemRepository;
use Imanager\Storage\Storage;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * SQLite-backed implementation of {@see Storage}.
 *
 * The constructor accepts a connection that's already configured by
 * {@see ConnectionFactory} and migrated by
 * {@see \Imanager\Storage\SchemaManager}; this class is a thin orchestrator
 * over the repository factories and the transaction wrapper.
 *
 * Nested `transactional()` calls are supported by re-using the outer
 * transaction (no automatic SAVEPOINT). The inner failure rolls back the
 * outer transaction as a whole — that's intentional, atomic-or-nothing.
 */
final class SqliteStorage implements Storage
{
    private readonly IndexedFields $indexedFields;
    private readonly EventDispatcherInterface $events;

    public function __construct(
        private readonly \PDO $connection,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->indexedFields = new IndexedFields($this->connection);
        $this->events = $events ?? new NullEventDispatcher();
    }

    public function categories(): CategoryRepository
    {
        return new SqliteCategoryRepository($this->connection, $this->events);
    }

    public function fields(): FieldRepository
    {
        return new SqliteFieldRepository($this->connection, $this->indexedFields, $this->events);
    }

    public function items(): ItemRepository
    {
        return new SqliteItemRepository($this->connection, $this->events);
    }

    public function files(): FileRepository
    {
        return new SqliteFileRepository($this->connection);
    }

    public function transactional(callable $work): mixed
    {
        $alreadyInTx = $this->connection->inTransaction();
        if (! $alreadyInTx) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $work();
            if (! $alreadyInTx) {
                $this->connection->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (! $alreadyInTx && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }
}
