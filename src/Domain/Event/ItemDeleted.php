<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

final readonly class ItemDeleted implements DomainEvent
{
    public function __construct(
        public int $itemId,
        public int $categoryId,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
