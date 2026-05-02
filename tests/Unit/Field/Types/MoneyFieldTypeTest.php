<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\MoneyFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoneyFieldType::class)]
final class MoneyFieldTypeTest extends TestCase
{
    private MoneyFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new MoneyFieldType(new Sanitizer());
    }

    /**
     * @return iterable<string, array{0: string, 1: float}>
     */
    public static function moneyStrings(): iterable
    {
        yield 'plain'             => ['1234.56',    1234.56];
        yield 'german formatting' => ['1.234,56',   1234.56];
        yield 'us with thousands' => ['1,234.56',   1234.56];
        yield 'comma decimal'     => ['12,50',      12.50];
        yield 'with euro symbol'  => ['€ 1.234,56', 1234.56];
        yield 'with dollar sign'  => ['$1,234.56',  1234.56];
    }

    #[DataProvider('moneyStrings')]
    public function testValidateNormalizesLocaleSpecificFormatting(
        string $input,
        float $expected,
    ): void {
        $result = $this->plugin->validate($input, $this->field());

        self::assertTrue($result->isValid);
        self::assertSame($expected, $result->coerced);
    }

    public function testValidateRoundsToConfiguredPrecision(): void
    {
        $result = $this->plugin->validate('3.14159', $this->field(['precision' => 2]));

        self::assertSame(3.14, $result->coerced);
    }

    public function testRenderEmitsCurrencyAttribute(): void
    {
        $html = $this->plugin->render(
            12.50,
            $this->field(['currency' => 'USD']),
            new RenderContext('item[price]'),
        );

        self::assertStringContainsString('data-currency="USD"', $html);
        self::assertStringContainsString('value="12.50"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = []): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'price',
            label: 'Price',
            type: FieldType::Money,
            config: $config,
        );
    }
}
