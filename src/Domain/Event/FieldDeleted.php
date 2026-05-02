<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

final readonly class FieldDeleted implements DomainEvent
{
    public function __construct(
        public int $fieldId,
        public int $categoryId,
        public string $name,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
