<?php

declare(strict_types=1);

namespace Imanager\Files;

use Imanager\Exception\ImanagerException;

/**
 * Raised by {@see FileStorage} implementations on I/O failures
 * (mkdir, rename, copy, unlink, read).
 */
final class FileStorageException extends \RuntimeException implements ImanagerException {}
