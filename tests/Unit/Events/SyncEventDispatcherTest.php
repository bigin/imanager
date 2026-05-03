<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Events;

use Imanager\Events\SubscriberListenerProvider;
use Imanager\Events\SyncEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

#[CoversClass(SyncEventDispatcher::class)]
final class SyncEventDispatcherTest extends TestCase
{
    public function testInvokesEveryRegisteredListenerInOrder(): void
    {
        $provider = new SubscriberListenerProvider();
        $calls = [];
        $provider->subscribe(StubEvent::class, static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $provider->subscribe(StubEvent::class, static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $dispatcher = new SyncEventDispatcher($provider);
        $event = new StubEvent('hi');
        $returned = $dispatcher->dispatch($event);

        self::assertSame($event, $returned);
        self::assertSame(['first', 'second'], $calls);
    }

    public function testStopsCallingListenersAfterPropagationStop(): void
    {
        $provider = new SubscriberListenerProvider();
        $calls = [];
        $provider->subscribe(StoppableStub::class, static function (StoppableStub $e) use (&$calls): void {
            $calls[] = 'first';
            $e->stop();
        });
        $provider->subscribe(StoppableStub::class, static function () use (&$calls): void {
            $calls[] = 'second';
        });

        (new SyncEventDispatcher($provider))->dispatch(new StoppableStub());

        self::assertSame(['first'], $calls);
    }

    public function testListenerExceptionsPropagate(): void
    {
        $provider = new SubscriberListenerProvider();
        $provider->subscribe(StubEvent::class, static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new SyncEventDispatcher($provider))->dispatch(new StubEvent('x'));
    }
}

final class StoppableStub implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
