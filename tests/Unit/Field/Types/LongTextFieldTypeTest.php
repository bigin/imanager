<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\LongTextFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LongTextFieldType::class)]
final class LongTextFieldTypeTest extends TestCase
{
    private LongTextFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new LongTextFieldType(new Sanitizer());
    }

    public function testValidatePreservesLineBreaks(): void
    {
        $result = $this->plugin->validate("para one\n\npara two", $this->field());

        self::assertTrue($result->isValid);
        self::assertSame("para one\n\npara two", $result->coerced);
    }

    public function testValidateNormalizesCrlfToLf(): void
    {
        $result = $this->plugin->validate("a\r\nb\rc", $this->field());

        self::assertTrue($result->isValid);
        self::assertSame("a\nb\nc", $result->coerced);
    }

    public function testValidateOnRequiredFieldRejectsEmpty(): void
    {
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderProducesATextarea(): void
    {
        $html = $this->plugin->render(
            "first\nline",
            $this->field(config: ['rows' => 8, 'placeholder' => 'Body']),
            new RenderContext('item[body]'),
        );

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('name="item[body]"', $html);
        self::assertStringContainsString('rows="8"', $html);
        self::assertStringContainsString('placeholder="Body"', $html);
        self::assertStringContainsString("first\nline</textarea>", $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = [], bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'body',
            label: 'Body',
            type: FieldType::LongText,
            required: $required,
            config: $config,
        );
    }
}
