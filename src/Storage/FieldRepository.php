<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Domain\Field;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;

interface FieldRepository
{
    public function find(int $id): ?Field;

    public function findByName(int $categoryId, string $name): ?Field;

    /**
     * @return list<Field>
     */
    public function findByCategory(int $categoryId): array;

    /**
     * Persist a field definition. Behavior mirrors {@see CategoryRepository::save()}:
     * id-less inputs get a fresh id, id-bearing inputs are upserted.
     *
     * Implementations must enforce uniqueness on `(category_id, name)`.
     *
     * @throws ValidationException When the name is already in use within the category.
     * @throws StorageException    On any persistence-layer failure.
     */
    public function save(Field $field): Field;

    /**
     * @throws NotFoundException When no field with that id exists.
     * @throws StorageException  On any persistence-layer failure.
     */
    public function delete(int $id): void;
}
