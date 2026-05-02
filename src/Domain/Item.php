<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * A single content item within a category.
 *
 * The dynamic field values are wrapped in {@see FieldValueBag} to give us a
 * single, evolvable touchpoint for value access. The constructor accepts both
 * a `FieldValueBag` and a plain `array<string, mixed>` so callers (tests,
 * adapters, repositories) can hand in whatever's most ergonomic.
 */
final readonly class Item
{
    public FieldValueBag $data;

    /**
     * @param FieldValueBag|array<string, mixed> $data
     */
    public function __construct(
        public ?int $id,
        public int $categoryId,
        public ?string $name = null,
        public ?string $label = null,
        public int $position = 0,
        public bool $active = true,
        FieldValueBag|array $data = [],
        public int $created = 0,
        public int $updated = 0,
    ) {
        if ($id !== null && $id < 1) {
            throw new \InvalidArgumentException('Item id, when set, must be >= 1');
        }
        if ($categoryId < 1) {
            throw new \InvalidArgumentException('Item categoryId must be >= 1');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Item position must be >= 0');
        }
        if ($created < 0 || $updated < 0) {
            throw new \InvalidArgumentException('Item timestamps must be >= 0');
        }

        $this->data = $data instanceof FieldValueBag ? $data : new FieldValueBag($data);
    }

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
