<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Events;

use Imanager\Events\NullEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullEventDispatcher::class)]
final class NullEventDispatcherTest extends TestCase
{
    public function testReturnsTheEventVerbatim(): void
    {
        $event = new StubEvent('hi');
        self::assertSame($event, (new NullEventDispatcher())->dispatch($event));
    }
}
