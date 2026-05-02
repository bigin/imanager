<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

use Imanager\Domain\Field;

final readonly class FieldUpdated implements DomainEvent
{
    public function __construct(
        public Field $previous,
        public Field $current,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
