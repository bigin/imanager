<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Enum;

use Imanager\Enum\FieldType;
use Imanager\Enum\SqliteAffinity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldType::class)]
#[CoversClass(SqliteAffinity::class)]
final class FieldTypeTest extends TestCase
{
    public function testShipsTheBuiltInFieldTypesFromIManager1x(): void
    {
        $names = array_map(static fn(FieldType $t): string => $t->value, FieldType::cases());

        // Order is deliberate; treat as a stable contract.
        self::assertSame(
            [
                'text',
                'longtext',
                'editor',
                'slug',
                'datepicker',
                'dropdown',
                'checkbox',
                'integer',
                'decimal',
                'money',
                'password',
                'hidden',
                'array',
                'filepicker',
                'fileupload',
                'imageupload',
            ],
            $names,
        );
    }

    public function testCanonicalValuesAreUnique(): void
    {
        $values = array_map(static fn(FieldType $t): string => $t->value, FieldType::cases());
        self::assertCount(\count($values), array_unique($values));
    }

    public function testTryFromAcceptsCanonicalValues(): void
    {
        self::assertSame(FieldType::Text, FieldType::tryFrom('text'));
        self::assertSame(FieldType::ArrayList, FieldType::tryFrom('array'));
        self::assertNull(FieldType::tryFrom('nonsense'));
    }

    /**
     * @return iterable<string, array{0: FieldType, 1: SqliteAffinity}>
     */
    public static function affinities(): iterable
    {
        yield 'integer is INTEGER'    => [FieldType::Integer, SqliteAffinity::Integer];
        yield 'datepicker is INTEGER' => [FieldType::Datepicker, SqliteAffinity::Integer];
        yield 'checkbox is INTEGER'   => [FieldType::Checkbox, SqliteAffinity::Integer];
        yield 'decimal is REAL'       => [FieldType::Decimal, SqliteAffinity::Real];
        yield 'money is REAL'         => [FieldType::Money, SqliteAffinity::Real];
        yield 'text is TEXT'          => [FieldType::Text, SqliteAffinity::Text];
        yield 'longtext is TEXT'      => [FieldType::LongText, SqliteAffinity::Text];
        yield 'slug is TEXT'          => [FieldType::Slug, SqliteAffinity::Text];
    }

    #[DataProvider('affinities')]
    public function testSqliteAffinityMappingIsStable(
        FieldType $type,
        SqliteAffinity $expected,
    ): void {
        self::assertSame($expected, $type->sqliteAffinity());
    }
}
