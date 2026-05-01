<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\InMemory;

use Imanager\Storage\InMemory\InMemoryItemRepository;
use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\ItemRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorage::class)]
#[CoversClass(InMemoryItemRepository::class)]
final class InMemoryItemRepositoryTest extends ItemRepositoryContract
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }
}
