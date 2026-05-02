<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

use Imanager\Domain\Category;

final readonly class CategoryUpdated implements DomainEvent
{
    public function __construct(
        public Category $previous,
        public Category $current,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
