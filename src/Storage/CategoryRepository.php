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
     * Remove the category and cascade-delete its fields and items.
     *
     * @throws NotFoundException When no category with that id exists.
     * @throws StorageException  On any persistence-layer failure.
     */
    public function delete(int $id): void;
}
