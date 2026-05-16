<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Domain\Category;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;

interface CategoryRepository
{
    public function find(int $id): ?Category;

    public function findBySlug(string $slug): ?Category;

    /**
     * @return list<Category>
     */
    public function findAll(): array;

    /**
     * Persist a category. If `$category->id === null`, an id is assigned and
     * a fresh `Category` instance with that id is returned. If the id is
     * non-null, an upsert against that id is performed.
     *
     * Implementations must enforce uniqueness on `name` and `slug`.
     *
     * @throws ValidationException When a uniqueness constraint is violated.
     * @throws StorageException    On any persistence-layer failure.
     */
    public function save(Category $category): Category;

    /**
     * Insert-on-miss, return-existing-on-hit upsert by natural key (slug).
     *
     * - `$category->id !== null` → equivalent to {@see save()}.
     * - `$category->id === null` → look up by `$category->slug`:
     *   - **Hit**: returns the existing category as-is. **No update** is
     *     performed; the input's other fields (name, position, …) are
     *     ignored. Callers wanting upsert-with-update should do an
     *     explicit `findBySlug()` + `save()`.
     *   - **Miss**: inserts as if `save()` had been called.
     *
     * Designed for idempotent schema-setup scripts that re-run safely.
     * Emits `CategoryCreated` only when a row is actually inserted; no
     * event fires on the hit path.
     *
     * @throws StorageException On any persistence-layer failure.
     */
    public function ensure(Category $category): Category;

    /**
     * Remove the category and cascade-delete its fields and items.
     *
     * @throws NotFoundException When no category with that id exists.
     * @throws StorageException  On any persistence-layer failure.
     */
    public function delete(int $id): void;
}
