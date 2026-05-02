<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\IntegerFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntegerFieldType::class)]
final class IntegerFieldTypeTest extends TestCase
{
    private IntegerFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new IntegerFieldType(new Sanitizer());
    }

    public function testValidateCoercesNumericString(): void
    {
        $result = $this->plugin->validate('42', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame(42, $result->coerced);
    }

    public function testValidateClampsToConfiguredMinMax(): void
    {
        $field = $this->field(config: ['min' => 0, 'max' => 100]);

        self::assertSame(100, $this->plugin->validate(500, $field)->coerced);
        self::assertSame(0, $this->plugin->validate(-50, $field)->coerced);
    }

    public function testValidateRejectsNonNumericInput(): void
    {
        $result = $this->plugin->validate('not a number', $this->field());

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::WrongValueFormat, $result->errorCode);
    }

    public function testValidateOnNonRequiredAcceptsEmptyAsNull(): void
    {
        $result = $this->plugin->validate('', $this->field());

        self::assertTrue($result->isValid);
        self::assertNull($result->coerced);
    }

    public function testValidateOnRequiredRejectsEmpty(): void
    {
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderEmitsNumberInputWithMinMaxStep(): void
    {
        $html = $this->plugin->render(
            42,
            $this->field(config: ['min' => 0, 'max' => 100, 'step' => 5]),
            new RenderContext('item[count]'),
        );

        self::assertStringContainsString('type="number"', $html);
        self::assertStringContainsString('value="42"', $html);
        self::assertStringContainsString('min="0"', $html);
        self::assertStringContainsString('max="100"', $html);
        self::assertStringContainsString('step="5"', $html);
    }

    public function testRenderOmitsValueAttributeForNull(): void
    {
        $html = $this->plugin->render(null, $this->field(), new RenderContext('item[count]'));

        self::assertStringContainsString('value=""', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = [], bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'count',
            label: 'Count',
            type: FieldType::Integer,
            required: $required,
            config: $config,
        );
    }
}
