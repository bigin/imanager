<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\StorageTransactionContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SqliteStorage::class)]
final class SqliteStorageTransactionTest extends StorageTransactionContract
{
    protected function createStorage(): Storage
    {
        return SqliteStorageFactory::inMemory();
    }
}
