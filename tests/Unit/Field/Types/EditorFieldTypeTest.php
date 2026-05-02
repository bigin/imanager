<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\EditorFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditorFieldType::class)]
final class EditorFieldTypeTest extends TestCase
{
    private EditorFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new EditorFieldType(new Sanitizer());
    }

    public function testValidateInMarkdownModeStoresMultilineSource(): void
    {
        $field = $this->field();
        $result = $this->plugin->validate("# Title\n\nBody **bold**", $field);

        self::assertTrue($result->isValid);
        self::assertSame("# Title\n\nBody **bold**", $result->coerced);
    }

    public function testValidateInHtmlModePurifiesDangerousTags(): void
    {
        $field = $this->field(['mode' => 'html']);
        $result = $this->plugin->validate('<p>safe</p><script>alert(1)</script>', $field);

        self::assertTrue($result->isValid);
        self::assertStringContainsString('<p>safe</p>', $result->coerced);
        self::assertStringNotContainsString('<script', $result->coerced);
    }

    public function testValidateOnRequiredEmptyFails(): void
    {
        $result = $this->plugin->validate('   ', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderProducesTextareaWithModeAttribute(): void
    {
        $html = $this->plugin->render(
            "first\nline",
            $this->field(['rows' => 8]),
            new RenderContext('item[body]'),
        );

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('data-editor-mode="markdown"', $html);
        self::assertStringContainsString('rows="8"', $html);
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
            type: FieldType::Editor,
            required: $required,
            config: $config,
        );
    }
}
