<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

use Imanager\Domain\Field;

final readonly class FieldCreated implements DomainEvent
{
    public function __construct(
        public Field $field,
        public int $occurredAt,
    ) {}

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }
}
