<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\InMemory;

use Imanager\Storage\InMemory\InMemoryFieldRepository;
use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\FieldRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorage::class)]
#[CoversClass(InMemoryFieldRepository::class)]
final class InMemoryFieldRepositoryTest extends FieldRepositoryContract
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }
}
