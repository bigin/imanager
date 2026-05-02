<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

use Imanager\Domain\Category;

final readonly class CategoryCreated implements DomainEvent
{
    public function __construct(
        public Category $category,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
