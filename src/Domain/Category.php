<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * Anemic data carrier for a category.
 *
 * Phase 3 keeps this intentionally lightweight; Phase 6 will enrich it with
 * domain-level invariants, factory methods, and `CategoryId` value-object
 * wrappers (if we decide to introduce them).
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
    ) {}

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
