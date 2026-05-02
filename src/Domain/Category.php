<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * A content category — the top-level grouping that owns fields and items.
 *
 * `Category` is a value object. Constructor invariants enforce structural
 * sanity (non-empty name and slug, non-negative position, monotonically
 * non-negative timestamps); business rules like uniqueness or slug format
 * live at the storage / Sanitizer boundary, not here.
 */
final readonly class Category
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $slug,
        public int $position = 0,
        public int $created = 0,
        public int $updated = 0,
    ) {
        if ($id !== null && $id < 1) {
            throw new \InvalidArgumentException('Category id, when set, must be >= 1');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Category name must not be empty');
        }
        if (trim($slug) === '') {
            throw new \InvalidArgumentException('Category slug must not be empty');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Category position must be >= 0');
        }
        if ($created < 0 || $updated < 0) {
            throw new \InvalidArgumentException('Category timestamps must be >= 0');
        }
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            name: $this->name,
            slug: $this->slug,
            position: $this->position,
            created: $this->created,
            updated: $this->updated,
        );
    }
}
