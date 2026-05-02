<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\CheckboxFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckboxFieldType::class)]
final class CheckboxFieldTypeTest extends TestCase
{
    private CheckboxFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new CheckboxFieldType(new Sanitizer());
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function inputs(): iterable
    {
        yield 'unchecked (null)' => [null,  false];
        yield 'string "1"'        => ['1',   true];
        yield 'string "0"'        => ['0',   false];
        yield 'string "on"'       => ['on',  true];
        yield 'string "yes"'      => ['yes', true];
        yield 'bool true'         => [true,  true];
        yield 'bool false'        => [false, false];
    }

    #[DataProvider('inputs')]
    public function testValidateCoercesToBool(mixed $input, bool $expected): void
    {
        $result = $this->plugin->validate($input, $this->field());

        self::assertTrue($result->isValid);
        self::assertSame($expected, $result->coerced);
    }

    public function testRenderEmitsCheckedWhenValueIsTruthy(): void
    {
        $html = $this->plugin->render(true, $this->field(), new RenderContext('item[active]'));

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString(' checked', $html);
    }

    public function testRenderOmitsCheckedWhenValueIsFalsy(): void
    {
        $html = $this->plugin->render(false, $this->field(), new RenderContext('item[active]'));

        self::assertStringNotContainsString(' checked', $html);
    }

    public function testRenderWrapsInLabelWhenLabelConfigured(): void
    {
        $html = $this->plugin->render(
            true,
            $this->field(config: ['label' => 'Make active']),
            new RenderContext('item[active]'),
        );

        self::assertStringContainsString('<label>', $html);
        self::assertStringContainsString('Make active</label>', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = []): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'active',
            label: 'Active',
            type: FieldType::Checkbox,
            config: $config,
        );
    }
}
