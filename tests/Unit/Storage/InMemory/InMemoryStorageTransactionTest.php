<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\InMemory;

use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\StorageTransactionContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorage::class)]
final class InMemoryStorageTransactionTest extends StorageTransactionContract
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }
}
