<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\FileuploadFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileuploadFieldType::class)]
final class FileuploadFieldTypeTest extends TestCase
{
    private FileuploadFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new FileuploadFieldType(new Sanitizer());
    }

    public function testValidateOnEmptyReturnsEmptyList(): void
    {
        self::assertSame([], $this->plugin->validate(null, $this->field())->coerced);
        self::assertSame([], $this->plugin->validate('', $this->field())->coerced);
        self::assertSame([], $this->plugin->validate([], $this->field())->coerced);
    }

    public function testValidatePassesArrayOfRecordsThrough(): void
    {
        $records = [
            ['name' => 'a.pdf', 'size' => 1024],
            ['name' => 'b.zip', 'size' => 2048],
        ];

        $result = $this->plugin->validate($records, $this->field());

        self::assertSame($records, $result->coerced);
    }

    public function testRenderEmitsFileInputWithMultipleAndAcceptHints(): void
    {
        $html = $this->plugin->render(
            [],
            $this->field(['acceptedExtensions' => 'pdf|zip', 'maxFiles' => 5]),
            new RenderContext('item[files]'),
        );

        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('name="item[files][]"', $html);
        self::assertStringContainsString(' multiple', $html);
        self::assertStringContainsString('data-accept="pdf|zip"', $html);
        self::assertStringContainsString('data-max-files="5"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = []): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'files',
            label: 'Files',
            type: FieldType::Fileupload,
            config: $config,
        );
    }
}
