<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\DecimalFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecimalFieldType::class)]
final class DecimalFieldTypeTest extends TestCase
{
    private DecimalFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new DecimalFieldType(new Sanitizer());
    }

    public function testValidateRoundsToConfiguredPrecision(): void
    {
        $result = $this->plugin->validate('3.14159', $this->field(['precision' => 2]));

        self::assertTrue($result->isValid);
        self::assertSame(3.14, $result->coerced);
    }

    public function testValidateClampsToMinMax(): void
    {
        $field = $this->field(['min' => 0, 'max' => 10]);

        self::assertSame(10.0, $this->plugin->validate(99.5, $field)->coerced);
        self::assertSame(0.0, $this->plugin->validate(-5.0, $field)->coerced);
    }

    public function testValidateRejectsNonNumeric(): void
    {
        $result = $this->plugin->validate('not a number', $this->field());

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::WrongValueFormat, $result->errorCode);
    }

    public function testValidateOnNonRequiredEmptyReturnsNull(): void
    {
        $result = $this->plugin->validate('', $this->field());

        self::assertTrue($result->isValid);
        self::assertNull($result->coerced);
    }

    public function testRenderEmitsNumberInputWithStepFromPrecision(): void
    {
        $html = $this->plugin->render(
            3.14,
            $this->field(['precision' => 2]),
            new RenderContext('item[price]'),
        );

        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('step="0.01"', $html);
        self::assertStringContainsString('value="3.14"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = []): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'amount',
            label: 'Amount',
            type: FieldType::Decimal,
            config: $config,
        );
    }
}
