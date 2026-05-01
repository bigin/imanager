<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Enum;

use Imanager\Enum\InputErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputErrorCode::class)]
final class InputErrorCodeTest extends TestCase
{
    public function testIntegerValuesAreStableAcrossThe1xMigration(): void
    {
        // Identical to the integer constants on iManager 1.x's InputInterface;
        // any consumer that persisted them numerically still works.
        self::assertSame(-1, InputErrorCode::EmptyRequired->value);
        self::assertSame(-2, InputErrorCode::MinLengthExceeded->value);
        self::assertSame(-3, InputErrorCode::MaxLengthExceeded->value);
        self::assertSame(-4, InputErrorCode::WrongValueFormat->value);
        self::assertSame(-5, InputErrorCode::ComparisonFailed->value);
        self::assertSame(-6, InputErrorCode::UndefinedCategoryId->value);
    }

    public function testValuesAreUnique(): void
    {
        $values = array_map(static fn(InputErrorCode $c): int => $c->value, InputErrorCode::cases());
        self::assertCount(\count($values), array_unique($values));
    }
}
