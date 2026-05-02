<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\DatepickerFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatepickerFieldType::class)]
final class DatepickerFieldTypeTest extends TestCase
{
    private DatepickerFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new DatepickerFieldType(new Sanitizer());
    }

    public function testValidateParsesIsoDateToTimestamp(): void
    {
        $result = $this->plugin->validate('2024-06-15', $this->field());

        self::assertTrue($result->isValid);
        self::assertIsInt($result->coerced);
        self::assertSame('2024-06-15', date('Y-m-d', $result->coerced));
    }

    public function testValidatePassesIntTimestampThrough(): void
    {
        $result = $this->plugin->validate(1700000000, $this->field());

        self::assertTrue($result->isValid);
        self::assertSame(1700000000, $result->coerced);
    }

    public function testValidateRejectsUnparseableInput(): void
    {
        $result = $this->plugin->validate('not a date', $this->field());

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::WrongValueFormat, $result->errorCode);
    }

    public function testValidateOnNonRequiredEmptyReturnsNull(): void
    {
        $result = $this->plugin->validate('', $this->field());

        self::assertTrue($result->isValid);
        self::assertNull($result->coerced);
    }

    public function testValidateOnRequiredEmptyFails(): void
    {
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderFormatsIntegerTimestampAsIsoDate(): void
    {
        $ts = mktime(0, 0, 0, 6, 15, 2024);
        $html = $this->plugin->render($ts, $this->field(), new RenderContext('item[date]'));

        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('value="2024-06-15"', $html);
    }

    public function testRenderHandlesNullValue(): void
    {
        $html = $this->plugin->render(null, $this->field(), new RenderContext('item[date]'));

        self::assertStringContainsString('value=""', $html);
    }

    private function field(bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'date',
            label: 'Date',
            type: FieldType::Datepicker,
            required: $required,
        );
    }
}
