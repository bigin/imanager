<?php

declare(strict_types=1);

namespace Imanager\Field;

use Imanager\Enum\InputErrorCode;

/**
 * Result of a {@see FieldTypePlugin::validate()} call.
 *
 * Combines validation outcome and coerced value into a single discriminated
 * record so callers don't have to remember a "validate then coerce" sequence
 * — and so a batch validator can collect failures across many fields without
 * intermediate state.
 *
 * Construct via the named factories: `ValidationResult::ok($coerced)` or
 * `ValidationResult::failed($code, $message)`. The constructor itself is
 * private; pattern-match on `$result->isValid` to consume.
 */
final readonly class ValidationResult
{
    private function __construct(
        public bool $isValid,
        public mixed $coerced = null,
        public ?InputErrorCode $errorCode = null,
        public string $message = '',
    ) {}

    public static function ok(mixed $coerced): self
    {
        return new self(isValid: true, coerced: $coerced);
    }

    public static function failed(InputErrorCode $code, string $message = ''): self
    {
        return new self(isValid: false, errorCode: $code, message: $message);
    }
}
