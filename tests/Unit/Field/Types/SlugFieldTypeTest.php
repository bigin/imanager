<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\SlugFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlugFieldType::class)]
final class SlugFieldTypeTest extends TestCase
{
    private SlugFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new SlugFieldType(new Sanitizer());
    }

    public function testValidateSlugifies(): void
    {
        $result = $this->plugin->validate('Hello World!', $this->field());

        self::assertTrue($result->isValid);
        self::assertSame('hello-world', $result->coerced);
    }

    public function testValidateOnRequiredEmptyAfterSanitizationRejects(): void
    {
        $result = $this->plugin->validate('---', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderEmitsSlugPattern(): void
    {
        $html = $this->plugin->render('hello-world', $this->field(), new RenderContext('item[slug]'));

        self::assertStringContainsString('pattern="[a-z0-9-]+"', $html);
        self::assertStringContainsString('value="hello-world"', $html);
    }

    private function field(bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'slug',
            label: 'Slug',
            type: FieldType::Slug,
            required: $required,
        );
    }
}
