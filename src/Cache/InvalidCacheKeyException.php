<?php

declare(strict_types=1);

namespace Imanager\Cache;

use Imanager\Exception\ImanagerException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * Raised when a key passed to the cache is empty or contains a character
 * PSR-16 reserves (`{`, `}`, `(`, `)`, `/`, `\`, `@`, `:`).
 *
 * Implements both PSR-16's `InvalidArgumentException` (so PSR-16 consumers
 * can catch it generically) and iManager's own `ImanagerException`.
 */
final class InvalidCacheKeyException extends \InvalidArgumentException implements
    Psr16InvalidArgumentException,
    ImanagerException {}
