<?php

declare(strict_types=1);

namespace Imanager\Exception;

use Imanager\Enum\InputErrorCode;

/**
 * Thrown when user-supplied input fails validation against a Field or Category.
 *
 * Carries the offending field name and a typed error code so the calling layer
 * (admin UI, API endpoint) can render a precise, localizable message.
 */
final class ValidationException extends \RuntimeException implements ImanagerException
{
    public function __construct(
        public readonly string $field,
        public readonly InputErrorCode $errorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage($field, $errorCode),
            0,
            $previous,
        );
    }

    private static function defaultMessage(string $field, InputErrorCode $code): string
    {
        return \sprintf('Validation failed for field "%s": %s', $field, $code->name);
    }
}
