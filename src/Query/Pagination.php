<?php

declare(strict_types=1);

namespace Imanager\Query;

/**
 * Immutable pagination metadata.
 *
 * Thrown together by the calling layer once the total row count for a query
 * is known. Templates / API responses derive the rest (`lastPage`, `hasMore`,
 * `offset`) from these three numbers.
 */
final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('page must be >= 1');
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException('perPage must be >= 1');
        }
        if ($total < 0) {
            throw new \InvalidArgumentException('total must be >= 0');
        }
    }

    public function lastPage(): int
    {
        if ($this->total === 0) {
            return 1;
        }
        return (int) ceil($this->total / $this->perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isFirstPage(): bool
    {
        return $this->page === 1;
    }

    public function isLastPage(): bool
    {
        return $this->page >= $this->lastPage();
    }
}
