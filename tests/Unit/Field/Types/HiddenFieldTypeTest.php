<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\HiddenFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HiddenFieldType::class)]
final class HiddenFieldTypeTest extends TestCase
{
    private HiddenFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new HiddenFieldType(new Sanitizer());
    }

    public function testValidatePassesAnyStringValue(): void
    {
        $result = $this->plugin->validate('some-token', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('some-token', $result->coerced);
    }

    public function testValidateNeverComplainsAboutEmptyEvenWhenRequired(): void
    {
        // Hidden inputs aren't user-facing — required-true on a hidden field
        // is a smell that's not the storage layer's job to police.
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertTrue($result->isValid);
        self::assertSame('', $result->coerced);
    }

    public function testValidateTruncatesAtMaxLength(): void
    {
        $field = $this->field(config: ['maxLength' => 5]);
        $result = $this->plugin->validate('123456789', $field);

        self::assertSame('12345', $result->coerced);
    }

    public function testRenderEmitsHiddenInput(): void
    {
        $html = $this->plugin->render('abc', $this->field(), new RenderContext('item[token]'));

        self::assertSame('<input type="hidden" name="item[token]" value="abc">', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = [], bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'token',
            label: null,
            type: FieldType::Hidden,
            required: $required,
            config: $config,
        );
    }
}
