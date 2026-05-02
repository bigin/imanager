<?php

declare(strict_types=1);

namespace Imanager\Query;

/**
 * Immutable query AST and fluent builder rolled into one.
 *
 * `Query` is a value object: every mutator (`where()`, `orderBy()`, `limit()`,
 * `inCategory()`) returns a new instance, leaving the original untouched.
 * Storage backends consume the resulting AST via the repository's `query()`
 * method — they never re-walk a builder, so query construction is fully
 * decoupled from execution.
 *
 * Example:
 * ```
 * $items = $repo->query(
 *     (new Query())
 *         ->inCategory(5)
 *         ->where('active', '=', true)
 *         ->where('position', '>=', 3)
 *         ->orderBy('created', 'desc')
 *         ->limit(20)
 * );
 * ```
 */
final readonly class Query
{
    /**
     * @param list<Clause>  $where
     * @param list<OrderBy> $orderBy
     */
    public function __construct(
        public ?int $categoryId = null,
        public array $where = [],
        public array $orderBy = [],
        public int $limit = 0,
        public int $offset = 0,
    ) {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit must be >= 0 (0 means no limit)');
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset must be >= 0');
        }
    }

    public function inCategory(int $id): self
    {
        return new self(
            categoryId: $id,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            offset: $this->offset,
        );
    }

    public function where(string $field, string|Operator $op, mixed $value): self
    {
        $opEnum = $op instanceof Operator ? $op : Operator::from($op);
        return new self(
            categoryId: $this->categoryId,
            where: [...$this->where, new Clause($field, $opEnum, $value)],
            orderBy: $this->orderBy,
            limit: $this->limit,
            offset: $this->offset,
        );
    }

    public function orderBy(string $field, string|Direction $direction = Direction::Asc): self
    {
        $dirEnum = $direction instanceof Direction
            ? $direction
            : Direction::coerce($direction);

        return new self(
            categoryId: $this->categoryId,
            where: $this->where,
            orderBy: [...$this->orderBy, new OrderBy($field, $dirEnum)],
            limit: $this->limit,
            offset: $this->offset,
        );
    }

    public function limit(int $n): self
    {
        return new self(
            categoryId: $this->categoryId,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $n,
            offset: $this->offset,
        );
    }

    public function offset(int $n): self
    {
        return new self(
            categoryId: $this->categoryId,
            where: $this->where,
            orderBy: $this->orderBy,
            limit: $this->limit,
            offset: $n,
        );
    }
}
