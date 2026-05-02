<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\DropdownFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DropdownFieldType::class)]
final class DropdownFieldTypeTest extends TestCase
{
    private DropdownFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new DropdownFieldType(new Sanitizer());
    }

    public function testValidateAcceptsKnownKey(): void
    {
        $result = $this->plugin->validate('draft', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('draft', $result->coerced);
    }

    public function testValidateRejectsUnknownKey(): void
    {
        $result = $this->plugin->validate('nonsense', $this->field());

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

    public function testRenderEmitsSelectWithEachOption(): void
    {
        $html = $this->plugin->render('draft', $this->field(), new RenderContext('item[status]'));

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="draft" selected>Draft</option>', $html);
        self::assertStringContainsString('<option value="published">Published</option>', $html);
    }

    public function testRenderInsertsBlankOptionForOptionalDropdown(): void
    {
        $html = $this->plugin->render(null, $this->field(), new RenderContext('item[status]'));

        self::assertStringContainsString('<option value=""></option>', $html);
    }

    public function testRenderOmitsBlankOptionForRequiredDropdown(): void
    {
        $html = $this->plugin->render(
            null,
            $this->field(required: true),
            new RenderContext('item[status]'),
        );

        self::assertStringNotContainsString('<option value=""></option>', $html);
        self::assertStringContainsString(' required', $html);
    }

    private function field(bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'status',
            label: 'Status',
            type: FieldType::Dropdown,
            required: $required,
            config: [
                'options' => [
                    'draft' => 'Draft',
                    'published' => 'Published',
                    'archived' => 'Archived',
                ],
            ],
        );
    }
}
