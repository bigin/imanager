<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\ArrayListFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayListFieldType::class)]
final class ArrayListFieldTypeTest extends TestCase
{
    private ArrayListFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ArrayListFieldType(new Sanitizer());
    }

    public function testValidateSplitsTextareaOnNewlines(): void
    {
        $result = $this->plugin->validate("php\ncms\nopen-source\n", $this->field());

        self::assertTrue($result->isValid);
        self::assertSame(['php', 'cms', 'open-source'], $result->coerced);
    }

    public function testValidateAlsoSplitsOnCommas(): void
    {
        $result = $this->plugin->validate('one, two, three', $this->field());

        self::assertSame(['one', 'two', 'three'], $result->coerced);
    }

    public function testValidatePassesArrayInputThrough(): void
    {
        $result = $this->plugin->validate(['a', 'b', 'c'], $this->field());

        self::assertSame(['a', 'b', 'c'], $result->coerced);
    }

    public function testValidateEnforcesMaxItems(): void
    {
        $field = $this->field(['maxItems' => 2]);
        $result = $this->plugin->validate(['a', 'b', 'c', 'd'], $field);

        self::assertSame(['a', 'b'], $result->coerced);
    }

    public function testValidateOnRequiredEmptyFails(): void
    {
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::EmptyRequired, $result->errorCode);
    }

    public function testRenderEmitsTextareaWithNewlineSeparatedValues(): void
    {
        $html = $this->plugin->render(
            ['php', 'cms'],
            $this->field(),
            new RenderContext('item[tags]'),
        );

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString("php\ncms</textarea>", $html);
        self::assertStringContainsString('data-list="newline"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = [], bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'tags',
            label: 'Tags',
            type: FieldType::ArrayList,
            required: $required,
            config: $config,
        );
    }
}
