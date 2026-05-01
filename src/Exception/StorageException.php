<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown for any failure in the persistence layer:
 * PDO errors, transaction aborts, atomic-write failures, lock contention, etc.
 */
final class StorageException extends \RuntimeException implements ImanagerException
{
    public static function fromPdo(\PDOException $previous, string $context = ''): self
    {
        $msg = $context !== ''
            ? \sprintf('%s: %s', $context, $previous->getMessage())
            : $previous->getMessage();

        return new self($msg, 0, $previous);
    }

    public static function transactionFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self('Transaction failed: ' . $reason, 0, $previous);
    }
}
