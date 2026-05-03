<?php

declare(strict_types=1);

namespace Imanager\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * In-memory PSR-14 listener provider.
 *
 * Listeners subscribe by event class name and run in registration order.
 * Children of a registered class match too — a subscriber for
 * {@see \Imanager\Domain\Event\DomainEvent} sees every domain event,
 * which keeps the cross-cutting "log everything" / "audit" cases
 * trivial without forcing every concrete listener to repeat the
 * full union of event classes.
 *
 * Designed to be tiny: no priorities, no async dispatch, no event-bus
 * federation. Compose the dispatcher above this for richer routing.
 */
final class SubscriberListenerProvider implements ListenerProviderInterface
{
    /**
     * Listener entries keyed by the event class they subscribe to.
     *
     * @var array<class-string, list<callable>>
     */
    private array $listeners = [];

    /**
     * Subscribe `$listener` to be invoked for `$eventClass` and any
     * subclass of it.
     *
     * The listener parameter is typed as a plain `callable` rather than
     * `callable(EventType): void` so subscribers can declare the
     * concrete event class in their own signature without tripping
     * PHPStan's callable-variance check (a closure accepting a
     * narrower type is not a subtype of `callable(object): void`).
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $registered => $entries) {
            if ($event instanceof $registered) {
                foreach ($entries as $listener) {
                    yield $listener;
                }
            }
        }
    }
}
