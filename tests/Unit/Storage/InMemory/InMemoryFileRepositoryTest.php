<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage\InMemory;

use Imanager\Storage\InMemory\InMemoryFileRepository;
use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Storage\Storage;
use Imanager\Tests\Unit\Storage\FileRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryStorage::class)]
#[CoversClass(InMemoryFileRepository::class)]
final class InMemoryFileRepositoryTest extends FileRepositoryContract
{
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }
}
