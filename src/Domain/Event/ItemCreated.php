<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

use Imanager\Domain\Item;

final readonly class ItemCreated implements DomainEvent
{
    public function __construct(
        public Item $item,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
