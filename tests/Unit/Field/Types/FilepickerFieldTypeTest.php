<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\FilepickerFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilepickerFieldType::class)]
final class FilepickerFieldTypeTest extends TestCase
{
    private FilepickerFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new FilepickerFieldType(new Sanitizer());
    }

    public function testValidateStripsPathTraversal(): void
    {
        $result = $this->plugin->validate('/etc/passwd/../photo.jpg', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('photo.jpg', $result->coerced);
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

    public function testRenderEmitsTextInputWithUpgradeMarker(): void
    {
        $html = $this->plugin->render(
            'photo.jpg',
            $this->field(),
            new RenderContext('item[file]'),
        );

        self::assertStringContainsString('value="photo.jpg"', $html);
        self::assertStringContainsString('data-field="filepicker"', $html);
    }

    private function field(bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'file',
            label: 'File',
            type: FieldType::Filepicker,
            required: $required,
        );
    }
}
