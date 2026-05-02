<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Storage\Sqlite\SqliteItemRepository;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\ItemQueryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SqliteStorage::class)]
#[CoversClass(SqliteItemRepository::class)]
final class SqliteItemQueryTest extends ItemQueryContract
{
    protected function createStorage(): Storage
    {
        return SqliteStorageFactory::inMemory();
    }
}
