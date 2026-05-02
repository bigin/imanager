<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\TextFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFieldType::class)]
final class TextFieldTypeTest extends TestCase
{
    private TextFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new TextFieldType(new Sanitizer());
    }

    public function testReportsItsCanonicalNameAndAffinity(): void
    {
        self::assertSame(FieldType::Text->value, TextFieldType::name());
        self::assertSame(SqliteAffinity::Text, TextFieldType::affinity());
    }

    public function testValidatePassesAndCoercesText(): void
    {
        $field = $this->field();
        $result = $this->plugin->validate("hello   world\n", $field);

        self::assertTrue($result->isValid);
        self::assertSame('hello world', $result->coerced);
    }

    public function testValidateOnRequiredFieldRejectsEmpty(): void
    {
        $field = $this->field(required: true);
        $result = $this->plugin->validate('', $field);

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testValidateRejectsBelowMinLength(): void
    {
        $field = $this->field(config: ['minLength' => 5]);
        $result = $this->plugin->validate('hi', $field);

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::MinLengthExceeded, $result->errorCode);
    }

    public function testValidateTruncatesAtMaxLengthSilently(): void
    {
        $field = $this->field(config: ['maxLength' => 5]);
        $result = $this->plugin->validate('123456789', $field);

        self::assertTrue($result->isValid);
        self::assertSame('12345', $result->coerced);
    }

    public function testValidateOnNonRequiredEmptyReturnsEmptyString(): void
    {
        $result = $this->plugin->validate('', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('', $result->coerced);
    }

    public function testRenderProducesAnInputElementWithEscapedAttributes(): void
    {
        $field = $this->field();
        $html = $this->plugin->render(
            'hello "world"',
            $field,
            new RenderContext('item[title]'),
        );

        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('name="item[title]"', $html);
        self::assertStringContainsString('value="hello &quot;world&quot;"', $html);
        self::assertStringContainsString('maxlength="255"', $html);
    }

    public function testRenderEmitsRequiredAttributeWhenFieldIsRequired(): void
    {
        $html = $this->plugin->render(
            '',
            $this->field(required: true),
            new RenderContext('item[title]'),
        );

        self::assertStringContainsString(' required', $html);
    }

    public function testRenderEmitsPlaceholderWhenConfigured(): void
    {
        $html = $this->plugin->render(
            '',
            $this->field(config: ['placeholder' => 'Enter title']),
            new RenderContext('item[title]'),
        );

        self::assertStringContainsString('placeholder="Enter title"', $html);
    }

    public function testRenderHandlesNullValueAsEmpty(): void
    {
        $html = $this->plugin->render(null, $this->field(), new RenderContext('item[title]'));

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
            name: 'title',
            label: 'Title',
            type: FieldType::Text,
            required: $required,
            config: $config,
        );
    }
}
