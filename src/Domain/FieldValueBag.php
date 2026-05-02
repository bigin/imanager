<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * Immutable typed wrapper around an item's dynamic field values.
 *
 * Replaces the bare `array<string, mixed>` that {@see Item::$data} carried in
 * Phase 3. The value-object form gives us:
 *
 *  - a single place to evolve data semantics (e.g. nested-key access, type
 *    coercion via the FieldType plugin in Phase 7),
 *  - explicit `with`/`without`/`merge` operations that don't mutate,
 *  - a clear boundary between in-memory representation and the JSON blob
 *    that's stored in `items.data`.
 *
 * The bag is intentionally untyped at the value level: a Field plugin decides
 * the PHP type its value takes.
 */
final readonly class FieldValueBag
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public array $values = []) {}

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->values);
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return \array_key_exists($field, $this->values) ? $this->values[$field] : $default;
    }

    public function with(string $field, mixed $value): self
    {
        return new self([...$this->values, $field => $value]);
    }

    public function without(string $field): self
    {
        $copy = $this->values;
        unset($copy[$field]);
        return new self($copy);
    }

    /**
     * @param self|array<string, mixed> $other
     */
    public function merge(self|array $other): self
    {
        $rhs = $other instanceof self ? $other->values : $other;
        return new self([...$this->values, ...$rhs]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function count(): int
    {
        return \count($this->values);
    }
}
