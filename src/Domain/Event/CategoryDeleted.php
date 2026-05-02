<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

final readonly class CategoryDeleted implements DomainEvent
{
    public function __construct(
        public int $categoryId,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
