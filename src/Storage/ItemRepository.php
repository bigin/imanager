<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Domain\Item;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;

interface ItemRepository
{
    public function find(int $id): ?Item;

    /**
     * Paginated list of items in a category.
     *
     * `$limit = 0` means "no limit"; the offset is applied even with no limit.
     * The richer querying API (filters, sort, search) lands in Phase 5 via
     * `QueryBuilder`; this method covers the basic listing case.
     *
     * @return list<Item>
     */
    public function findByCategory(int $categoryId, int $offset = 0, int $limit = 0): array;

    public function countByCategory(int $categoryId): int;

    /**
     * Persist an item. Behavior mirrors {@see CategoryRepository::save()}.
     *
     * @throws StorageException On any persistence-layer failure.
     */
    public function save(Item $item): Item;

    /**
     * @throws NotFoundException When no item with that id exists.
     * @throws StorageException  On any persistence-layer failure.
     */
    public function delete(int $id): void;
}
