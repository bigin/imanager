<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Events;

class StubEvent
{
    public function __construct(public readonly string $payload) {}
}
