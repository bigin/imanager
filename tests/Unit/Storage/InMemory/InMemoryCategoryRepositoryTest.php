<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\InMemory;

use Imanager\Storage\InMemory\InMemoryCategoryRepository;
use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\CategoryRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorage::class)]
#[CoversClass(InMemoryCategoryRepository::class)]
final class InMemoryCategoryRepositoryTest extends CategoryRepositoryContract
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }
}
