<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Marker interface for every exception thrown from the iManager library.
 *
 * Catch `ImanagerException` to handle any iManager error generically;
 * catch the concrete subtypes (StorageException, ValidationException, ...)
 * to handle specific failure modes. Each concrete class also extends one of
 * the SPL exceptions (`\RuntimeException` or `\LogicException`) so that
 * generic SPL-aware handlers continue to work.
 */
interface ImanagerException extends \Throwable {}
