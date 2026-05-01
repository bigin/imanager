<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * Anemic data carrier for an item.
 *
 * `data` holds field-name → value pairs; the field type plugin decides the
 * value's PHP type (Phase 7). For Phase 3 the bag round-trips as-is.
 */
final readonly class Item
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public ?int $id,
        public int $categoryId,
        public ?string $name = null,
        public ?string $label = null,
        public int $position = 0,
        public bool $active = true,
        public array $data = [],
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
            position: $this->position,
            active: $this->active,
            data: $this->data,
            created: $this->created,
            updated: $this->updated,
        );
    }
}
