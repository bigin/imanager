<?php

declare(strict_types=1);

namespace Imanager\Domain\Event;

/**
 * Marker interface for every domain event raised by the iManager core.
 *
 * Phase 6 ships only the data classes — they're pure records carrying the
 * state changes that storage operations produced. A dispatcher and listener
 * registry land alongside a host hook system in a later phase; this
 * interface plus the concrete events are everything that needs to exist
 * in iManager itself.
 */
interface DomainEvent
{
    /**
     * Unix timestamp (seconds) at which the event happened.
     */
    public function occurredAt(): int;
}
