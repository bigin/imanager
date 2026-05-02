<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field;

use Imanager\Enum\InputErrorCode;
use Imanager\Field\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationResult::class)]
final class ValidationResultTest extends TestCase
{
    public function testOkCarriesTheCoercedValueAndNoError(): void
    {
        $r = ValidationResult::ok('hello');

        self::assertTrue($r->isValid);
        self::assertSame('hello', $r->coerced);
        self::assertNull($r->errorCode);
        self::assertSame('', $r->message);
    }

    public function testFailedCarriesTheErrorCodeAndOptionalMessage(): void
    {
        $r = ValidationResult::failed(InputErrorCode::EmptyRequired, 'Title is required');

        self::assertFalse($r->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $r->errorCode);
        self::assertSame('Title is required', $r->message);
        self::assertNull($r->coerced);
    }

    public function testFailedDefaultsToEmptyMessage(): void
    {
        $r = ValidationResult::failed(InputErrorCode::WrongValueFormat);

        self::assertSame('', $r->message);
    }

    public function testOkAcceptsAnyCoercedValueShape(): void
    {
        self::assertSame(0, ValidationResult::ok(0)->coerced);
        self::assertNull(ValidationResult::ok(null)->coerced);
        self::assertFalse(ValidationResult::ok(false)->coerced);
        self::assertSame([], ValidationResult::ok([])->coerced);
    }
}
