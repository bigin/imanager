<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Domain\Item;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Query\Query;

interface ItemRepository
{
    public function find(int $id): ?Item;

    /**
     * Paginated list of items in a category.
     *
     * `$limit = 0` means "no limit"; the offset is applied even with no limit.
     * Convenience wrapper for the common "all items in this category" path —
     * for filters, sort, and JSON-field predicates use {@see query()}.
     *
     * @return list<Item>
     */
    public function findByCategory(int $categoryId, int $offset = 0, int $limit = 0): array;

    public function countByCategory(int $categoryId): int;

    /**
     * Run a {@see Query} and return the matching items.
     *
     * The Query AST is the canonical execution surface; both `findByCategory()`
     * above and the `SelectorParser` string DSL are sugar that produce a
     * `Query` and call this method.
     *
     * @return list<Item>
     */
    public function query(Query $query): array;

    /**
     * Total number of items matching the where-clause portion of `$query`.
     *
     * `limit` and `offset` on the query are ignored — the count reflects the
     * full result set. Use it together with `query()` to drive
     * {@see \Imanager\Query\Pagination}.
     */
    public function count(Query $query): int;

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
