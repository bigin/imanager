<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\ImageuploadFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageuploadFieldType::class)]
final class ImageuploadFieldTypeTest extends TestCase
{
    private ImageuploadFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ImageuploadFieldType(new Sanitizer());
    }

    public function testValidatePassesArrayOfRecordsThrough(): void
    {
        $records = [['name' => 'a.png'], ['name' => 'b.jpg']];

        $result = $this->plugin->validate($records, $this->field());

        self::assertSame($records, $result->coerced);
    }

    public function testRenderEmitsImageOnlyFileInput(): void
    {
        $html = $this->plugin->render(
            [],
            $this->field(['maxFiles' => 3]),
            new RenderContext('item[gallery]'),
        );

        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('accept="image/*"', $html);
        self::assertStringContainsString('name="item[gallery][]"', $html);
        self::assertStringContainsString('data-max-files="3"', $html);
        self::assertStringContainsString('data-field="imageupload"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = []): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'gallery',
            label: 'Gallery',
            type: FieldType::Imageupload,
            config: $config,
        );
    }
}
