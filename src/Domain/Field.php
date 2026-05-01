<?php

declare(strict_types=1);

namespace Imanager\Domain;

use Imanager\Enum\FieldType;

/**
 * Anemic data carrier for a field definition.
 *
 * The `config` array is intentionally untyped here; the `FieldType` plugin
 * decides the shape (Phase 7). For Phase 3 it round-trips as opaque data.
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
    ) {}

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
