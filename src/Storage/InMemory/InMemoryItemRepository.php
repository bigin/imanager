<?php

declare(strict_types=1);

namespace Imanager\Storage\InMemory;

use Imanager\Domain\Item;
use Imanager\Storage\ItemRepository;

final readonly class InMemoryItemRepository implements ItemRepository
{
    public function __construct(private InMemoryStorage $storage) {}

    public function find(int $id): ?Item
    {
        return $this->storage->getItem($id);
    }

    public function findByCategory(int $categoryId, int $offset = 0, int $limit = 0): array
    {
        return $this->storage->itemsByCategory($categoryId, $offset, $limit);
    }

    public function countByCategory(int $categoryId): int
    {
        return $this->storage->countItemsByCategory($categoryId);
    }

    public function save(Item $item): Item
    {
        return $this->storage->saveItem($item);
    }

    public function delete(int $id): void
    {
        $this->storage->deleteItem($id);
    }
}
