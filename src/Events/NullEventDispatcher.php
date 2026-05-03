<?php

declare(strict_types=1);

namespace Imanager\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Default `null` dispatcher used when storage is constructed without
 * any subscribers. Returning the event verbatim keeps PSR-14 semantics
 * intact (the dispatcher always returns the dispatched event).
 *
 * Lets storage code stay branchless — call `dispatcher->dispatch(...)`
 * unconditionally instead of checking for null on every save / delete.
 */
final readonly class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
