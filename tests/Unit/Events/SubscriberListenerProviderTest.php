<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Events;

use Imanager\Events\SubscriberListenerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubscriberListenerProvider::class)]
final class SubscriberListenerProviderTest extends TestCase
{
    public function testYieldsListenersForExactEventClass(): void
    {
        $provider = new SubscriberListenerProvider();
        $calls = [];
        $provider->subscribe(StubEvent::class, static function (StubEvent $e) use (&$calls): void {
            $calls[] = 'a:' . $e->payload;
        });
        $provider->subscribe(StubEvent::class, static function (StubEvent $e) use (&$calls): void {
            $calls[] = 'b:' . $e->payload;
        });

        $listeners = iterator_to_array($provider->getListenersForEvent(new StubEvent('hi')), false);

        self::assertCount(2, $listeners);
        $listeners[0](new StubEvent('1'));
        $listeners[1](new StubEvent('2'));
        self::assertSame(['a:1', 'b:2'], $calls);
    }

    public function testYieldsListenersForParentClassOfEvent(): void
    {
        $provider = new SubscriberListenerProvider();
        $captured = [];
        // Subscribe to the parent — child events should match too.
        $provider->subscribe(StubEvent::class, static function (StubEvent $e) use (&$captured): void {
            $captured[] = $e::class;
        });

        $listeners = iterator_to_array($provider->getListenersForEvent(new StubChildEvent('child')), false);
        self::assertCount(1, $listeners);
        $listeners[0](new StubChildEvent('child'));
        self::assertSame([StubChildEvent::class], $captured);
    }

    public function testYieldsNothingForUnrelatedEvent(): void
    {
        $provider = new SubscriberListenerProvider();
        $provider->subscribe(StubEvent::class, static fn() => null);

        $listeners = iterator_to_array($provider->getListenersForEvent(new \stdClass()), false);
        self::assertSame([], $listeners);
    }
}
