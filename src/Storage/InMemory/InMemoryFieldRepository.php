<?php

declare(strict_types=1);

namespace Imanager\Storage\InMemory;

use Imanager\Domain\Field;
use Imanager\Storage\FieldRepository;

final readonly class InMemoryFieldRepository implements FieldRepository
{
    public function __construct(private InMemoryStorage $storage) {}

    public function find(int $id): ?Field
    {
        return $this->storage->getField($id);
    }

    public function findByName(int $categoryId, string $name): ?Field
    {
        return $this->storage->findFieldByName($categoryId, $name);
    }

    public function findByCategory(int $categoryId): array
    {
        return $this->storage->fieldsByCategory($categoryId);
    }

    public function save(Field $field): Field
    {
        return $this->storage->saveField($field);
    }

    public function ensure(Field $field): Field
    {
        if ($field->id !== null) {
            return $this->save($field);
        }
        return $this->findByName($field->categoryId, $field->name) ?? $this->save($field);
    }

    public function delete(int $id): void
    {
        $this->storage->deleteField($id);
    }
}
