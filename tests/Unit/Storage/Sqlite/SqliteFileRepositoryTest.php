<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\Sqlite;

use Imanager\Storage\Sqlite\SqliteFileRepository;
use Imanager\Storage\Sqlite\SqliteStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\FileRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SqliteStorage::class)]
#[CoversClass(SqliteFileRepository::class)]
final class SqliteFileRepositoryTest extends FileRepositoryContract
{
    protected function createStorage(): Storage
    {
        return SqliteStorageFactory::inMemory();
    }
}
