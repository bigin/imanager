<?php

declare(strict_types=1);

namespace Imanager\Storage\InMemory;

use Imanager\Domain\Category;
use Imanager\Storage\CategoryRepository;

/**
 * Thin facade over {@see InMemoryStorage}'s category-shaped methods.
 *
 * All mutation logic, uniqueness checks, and cascade behavior live on
 * the storage class so transaction snapshots are simple and consistent.
 */
final readonly class InMemoryCategoryRepository implements CategoryRepository
{
    public function __construct(private InMemoryStorage $storage) {}

    public function find(int $id): ?Category
    {
        return $this->storage->getCategory($id);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->storage->findCategoryBySlug($slug);
    }

    public function findAll(): array
    {
        return $this->storage->allCategories();
    }

    public function save(Category $category): Category
    {
        return $this->storage->saveCategory($category);
    }

    public function ensure(Category $category): Category
    {
        if ($category->id !== null) {
            return $this->save($category);
        }
        return $this->findBySlug($category->slug) ?? $this->save($category);
    }

    public function delete(int $id): void
    {
        $this->storage->deleteCategory($id);
    }
}
