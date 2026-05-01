<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Exception;

use Imanager\Enum\InputErrorCode;
use Imanager\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationException::class)]
final class ValidationExceptionTest extends TestCase
{
    public function testCarriesFieldAndErrorCodeAsReadonlyProperties(): void
    {
        $e = new ValidationException('title', InputErrorCode::EmptyRequired);

        self::assertSame('title', $e->field);
        self::assertSame(InputErrorCode::EmptyRequired, $e->errorCode);
    }

    public function testGeneratesADefaultMessageWhenNoneIsProvided(): void
    {
        $e = new ValidationException('slug', InputErrorCode::WrongValueFormat);

        self::assertSame(
            'Validation failed for field "slug": WrongValueFormat',
            $e->getMessage(),
        );
    }

    public function testKeepsAnExplicitMessageWhenProvided(): void
    {
        $e = new ValidationException(
            'title',
            InputErrorCode::MaxLengthExceeded,
            'Title may not exceed 255 characters',
        );

        self::assertSame('Title may not exceed 255 characters', $e->getMessage());
    }

    public function testWrapsAPreviousException(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new ValidationException('title', InputErrorCode::EmptyRequired, '', $previous);

        self::assertSame($previous, $e->getPrevious());
    }
}
