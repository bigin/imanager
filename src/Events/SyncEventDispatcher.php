<?php

declare(strict_types=1);

namespace Imanager\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * In-process PSR-14 dispatcher.
 *
 * Walks every listener returned by the {@see ListenerProviderInterface},
 * stopping early if the event is a {@see StoppableEventInterface} that
 * has been flagged "stopped". Listener exceptions propagate — there is
 * no swallowing — so misbehaving listeners surface loudly during
 * development and CI.
 *
 * Sync semantics are deliberate: iManager's storage layer fires events
 * inline (after a successful commit) and most consumers want
 * cache-invalidation / file-cleanup to land before the request finishes.
 * If async dispatch is needed later, swap the dispatcher in the container.
 */
final readonly class SyncEventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProviderInterface $provider) {}

    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }
}
