<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Storage;

use Imanager\Domain\Category;
use Imanager\Storage\Storage;
use PHPUnit\Framework\TestCase;

abstract class StorageTransactionContract extends TestCase
{
    protected Storage $storage;

    abstract protected function createStorage(): Storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testTransactionalReturnsTheCallbacksReturnValue(): void
    {
        $result = $this->storage->transactional(static fn(): int => 42);

        self::assertSame(42, $result);
    }

    public function testTransactionalCommitsSuccessfulMutations(): void
    {
        $this->storage->transactional(function (): void {
            $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        });

        self::assertCount(1, $this->storage->categories()->findAll());
    }

    public function testTransactionalRollsBackOnException(): void
    {
        $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));

        $thrown = null;
        try {
            $this->storage->transactional(function (): void {
                $this->storage->categories()->save(new Category(null, 'News', 'news'));
                throw new \RuntimeException('rollback please');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertSame('rollback please', $thrown->getMessage());

        // Pre-transaction state preserved, post-transaction mutation reverted.
        self::assertCount(1, $this->storage->categories()->findAll());
        self::assertNotNull($this->storage->categories()->findBySlug('blog'));
        self::assertNull($this->storage->categories()->findBySlug('news'));
    }
}
