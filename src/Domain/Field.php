<?php

declare(strict_types=1);

namespace Imanager\Domain;

use Imanager\Enum\FieldType;

/**
 * A field definition that belongs to a category.
 *
 * `categoryId` is the **owning** category — for the field to make sense, that
 * category must already exist; the constructor enforces `>= 1` accordingly,
 * the storage layer enforces FK referential integrity.
 *
 * The `config` array is intentionally untyped here; the FieldType plugin
 * decides its shape (Phase 7). For Phase 6 it round-trips as opaque data.
 */
final readonly class Field
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?int $id,
        public int $categoryId,
        public string $name,
        public ?string $label,
        public FieldType $type,
        public int $position = 0,
        public bool $required = false,
        public bool $indexed = false,
        public bool $searchable = false,
        public array $config = [],
        public int $created = 0,
        public int $updated = 0,
    ) {
        if ($id !== null && $id < 1) {
            throw new \InvalidArgumentException('Field id, when set, must be >= 1');
        }
        if ($categoryId < 1) {
            throw new \InvalidArgumentException('Field categoryId must be >= 1');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Field name must not be empty');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Field position must be >= 0');
        }
        if ($created < 0 || $updated < 0) {
            throw new \InvalidArgumentException('Field timestamps must be >= 0');
        }
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            categoryId: $this->categoryId,
            name: $this->name,
            label: $this->label,
            type: $this->type,
            position: $this->position,
            required: $this->required,
            indexed: $this->indexed,
            searchable: $this->searchable,
            config: $this->config,
            created: $this->created,
            updated: $this->updated,
        );
    }
}
