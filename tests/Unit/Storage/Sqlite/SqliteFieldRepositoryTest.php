<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Storage\Sqlite\SqliteFieldRepository;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\FieldRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SqliteStorage::class)]
#[CoversClass(SqliteFieldRepository::class)]
final class SqliteFieldRepositoryTest extends FieldRepositoryContract
{
    protected function createStorage(): Storage
    {
        return SqliteStorageFactory::inMemory();
    }
}
