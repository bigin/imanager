<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Field::class)]
final class FieldTest extends TestCase
{
    public function testStoresAllConstructorArgumentsVerbatim(): void
    {
        $f = new Field(
            id: 5,
            categoryId: 1,
            name: 'title',
            label: 'Title',
            type: FieldType::Text,
            position: 2,
            required: true,
            indexed: true,
            searchable: true,
            config: ['maxLength' => 200],
            created: 1700000000,
            updated: 1700000100,
        );

        self::assertSame(5, $f->id);
        self::assertSame(1, $f->categoryId);
        self::assertSame('title', $f->name);
        self::assertSame('Title', $f->label);
        self::assertSame(FieldType::Text, $f->type);
        self::assertSame(2, $f->position);
        self::assertTrue($f->required);
        self::assertTrue($f->indexed);
        self::assertTrue($f->searchable);
        self::assertSame(['maxLength' => 200], $f->config);
        self::assertSame(1700000000, $f->created);
        self::assertSame(1700000100, $f->updated);
    }

    public function testFlagsDefaultToFalseAndConfigDefaultsToEmpty(): void
    {
        $f = new Field(null, 1, 'title', null, FieldType::Text);

        self::assertFalse($f->required);
        self::assertFalse($f->indexed);
        self::assertFalse($f->searchable);
        self::assertSame([], $f->config);
    }

    public function testWithIdReturnsACopyCarryingTheAssignedId(): void
    {
        $original = new Field(null, 1, 'title', null, FieldType::Text);
        $assigned = $original->withId(7);

        self::assertNotSame($original, $assigned);
        self::assertNull($original->id);
        self::assertSame(7, $assigned->id);
        self::assertSame('title', $assigned->name);
        self::assertSame(FieldType::Text, $assigned->type);
    }

    public function testRejectsZeroOrNegativeIdWhenSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field id');
        new Field(0, 1, 'title', null, FieldType::Text);
    }

    public function testRejectsZeroOrNegativeCategoryId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field categoryId');
        new Field(null, 0, 'title', null, FieldType::Text);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field name');
        new Field(null, 1, '   ', null, FieldType::Text);
    }

    public function testRejectsNegativePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('position');
        new Field(null, 1, 'title', null, FieldType::Text, position: -1);
    }

    // -----------------------------------------------------------------
    // Static factories — 16 cases, one per FieldType.
    // -----------------------------------------------------------------

    #[DataProvider('factories')]
    public function testFactoryReturnsFreshFieldOfExpectedType(
        callable $factory,
        FieldType $expectedType,
        bool $expectedSearchable,
    ): void {
        $f = $factory(7, 'col', 'Column');

        self::assertNull($f->id);
        self::assertSame(7, $f->categoryId);
        self::assertSame('col', $f->name);
        self::assertSame('Column', $f->label);
        self::assertSame($expectedType, $f->type);

        // required + indexed always default false from the factory; the
        // per-type `searchable` default is asserted via the provider.
        self::assertSame(0, $f->position);
        self::assertFalse($f->required);
        self::assertFalse($f->indexed);
        self::assertSame($expectedSearchable, $f->searchable);
        self::assertSame([], $f->config);
        self::assertSame(0, $f->created);
        self::assertSame(0, $f->updated);
    }

    public function testFactoryLabelDefaultsToNull(): void
    {
        $f = Field::text(1, 'name');
        self::assertNull($f->label);
    }

    /**
     * Each factory returns its per-type `searchable` default — prose-typed
     * fields (text, longText, editor, slug) opt INTO the FTS body; every
     * other type opts OUT. Callers always override via `->searchable()`.
     *
     * @return iterable<string, array{0: callable, 1: FieldType, 2: bool}>
     */
    public static function factories(): iterable
    {
        yield 'text'        => [Field::text(...),        FieldType::Text,       true];
        yield 'longText'    => [Field::longText(...),    FieldType::LongText,   true];
        yield 'editor'      => [Field::editor(...),      FieldType::Editor,     true];
        yield 'slug'        => [Field::slug(...),        FieldType::Slug,       true];
        yield 'password'    => [Field::password(...),    FieldType::Password,   false];
        yield 'integer'     => [Field::integer(...),     FieldType::Integer,    false];
        yield 'decimal'     => [Field::decimal(...),     FieldType::Decimal,    false];
        yield 'money'       => [Field::money(...),       FieldType::Money,      false];
        yield 'checkbox'    => [Field::checkbox(...),    FieldType::Checkbox,   false];
        yield 'dropdown'    => [Field::dropdown(...),    FieldType::Dropdown,   false];
        yield 'datepicker'  => [Field::datepicker(...),  FieldType::Datepicker, false];
        yield 'hidden'      => [Field::hidden(...),      FieldType::Hidden,     false];
        yield 'arrayList'   => [Field::arrayList(...),   FieldType::ArrayList,  false];
        yield 'file'        => [Field::file(...),        FieldType::Fileupload, false];
        yield 'image'       => [Field::image(...),       FieldType::Imageupload, false];
        yield 'filePicker'  => [Field::filePicker(...),  FieldType::Filepicker, false];
    }

    // -----------------------------------------------------------------
    // General fluent setters.
    // -----------------------------------------------------------------

    public function testRequiredFlagFlips(): void
    {
        $f = Field::text(1, 'x');
        $on = $f->required();
        $off = $on->required(false);

        self::assertFalse($f->required);
        self::assertTrue($on->required);
        self::assertFalse($off->required);
        // The other flags carry over untouched.
        self::assertSame($f->indexed, $on->indexed);
        self::assertSame($f->searchable, $on->searchable);
    }

    public function testIndexedFlagFlips(): void
    {
        $f = Field::text(1, 'x')->indexed();
        self::assertTrue($f->indexed);
        self::assertFalse($f->indexed(false)->indexed);
    }

    public function testSearchableFlagFlipsInBothDirections(): void
    {
        // Field::password() defaults to searchable:false — opt IN, then back OUT.
        $off = Field::password(1, 'pw');
        self::assertFalse($off->searchable);
        self::assertTrue($off->searchable()->searchable);
        self::assertFalse($off->searchable()->searchable(false)->searchable);

        // Field::text() defaults to searchable:true — opt OUT, then back IN.
        $on = Field::text(1, 'title');
        self::assertTrue($on->searchable);
        self::assertFalse($on->searchable(false)->searchable);
        self::assertTrue($on->searchable(false)->searchable()->searchable);
    }

    public function testPositionSetterReplacesValue(): void
    {
        $f = Field::text(1, 'x')->position(5);
        self::assertSame(5, $f->position);
        self::assertSame(0, $f->position(0)->position);
    }

    public function testLabelSetterReplacesValue(): void
    {
        $f = Field::text(1, 'x', 'Old');
        $renamed = $f->label('New');

        self::assertSame('Old', $f->label);
        self::assertSame('New', $renamed->label);
    }

    public function testConfigSetterReplacesWholeArray(): void
    {
        $f = Field::text(1, 'x')->maxLength(200)->placeholder('Type here');
        $replaced = $f->config(['custom' => 'value']);

        self::assertSame(['custom' => 'value'], $replaced->config);
    }

    // -----------------------------------------------------------------
    // Type-aware setters — each writes one documented config key,
    // preserves the rest.
    // -----------------------------------------------------------------

    public function testMaxLengthWritesIntoConfig(): void
    {
        $f = Field::text(1, 'title')->maxLength(200);
        self::assertSame(200, $f->config['maxLength']);
    }

    public function testMinLengthWritesIntoConfig(): void
    {
        $f = Field::text(1, 'title')->minLength(3);
        self::assertSame(3, $f->config['minLength']);
    }

    public function testPlaceholderWritesIntoConfig(): void
    {
        $f = Field::text(1, 'title')->placeholder('Type here');
        self::assertSame('Type here', $f->config['placeholder']);
    }

    public function testMaxBytesWritesIntoConfig(): void
    {
        $f = Field::image(1, 'cover')->maxBytes(5_000_000);
        self::assertSame(5_000_000, $f->config['maxBytes']);
    }

    public function testMimesWritesIntoConfig(): void
    {
        $f = Field::image(1, 'cover')->mimes('image/jpeg', 'image/png');
        self::assertSame(['image/jpeg', 'image/png'], $f->config['mimes']);
    }

    public function testOptionsWritesIntoConfig(): void
    {
        $f = Field::dropdown(1, 'status')->options(['draft' => 'Draft', 'published' => 'Published']);
        self::assertSame(['draft' => 'Draft', 'published' => 'Published'], $f->config['options']);
    }

    public function testFormatWritesIntoConfig(): void
    {
        $f = Field::datepicker(1, 'published_at')->format('Y-m-d');
        self::assertSame('Y-m-d', $f->config['format']);
    }

    public function testTypeAwareSettersPreserveOtherConfigKeys(): void
    {
        $f = Field::text(1, 'title')
            ->maxLength(200)
            ->minLength(3)
            ->placeholder('Type here');

        self::assertSame(200, $f->config['maxLength']);
        self::assertSame(3, $f->config['minLength']);
        self::assertSame('Type here', $f->config['placeholder']);
    }

    public function testTypeAwareSetterOverwritesItsOwnKeyOnly(): void
    {
        $f = Field::text(1, 'title')
            ->maxLength(200)
            ->placeholder('First')
            ->maxLength(500);   // overwrite

        self::assertSame(500, $f->config['maxLength']);
        self::assertSame('First', $f->config['placeholder']);
    }

    // -----------------------------------------------------------------
    // Chained setters compose; immutability is preserved end-to-end.
    // -----------------------------------------------------------------

    public function testChainedSettersComposeAndReturnNewInstances(): void
    {
        // Use a bare constructor so all flags start false — keeps the
        // "every setter flips its flag" demonstration independent of
        // per-factory smart defaults.
        $original = new Field(null, 1, 'title', null, FieldType::Text);
        $built = $original
            ->required()
            ->indexed()
            ->searchable()
            ->maxLength(200);

        // Every setter returned a new instance.
        self::assertNotSame($original, $built);

        // Original untouched.
        self::assertFalse($original->required);
        self::assertFalse($original->indexed);
        self::assertFalse($original->searchable);
        self::assertSame([], $original->config);

        // Built has every change.
        self::assertTrue($built->required);
        self::assertTrue($built->indexed);
        self::assertTrue($built->searchable);
        self::assertSame(['maxLength' => 200], $built->config);

        // Identity preserved.
        self::assertSame(1, $built->categoryId);
        self::assertSame('title', $built->name);
        self::assertSame(FieldType::Text, $built->type);
    }

    public function testWithIdContinuesToWorkOnAFactoryBuiltField(): void
    {
        $f = Field::text(1, 'title')->required()->withId(42);

        self::assertSame(42, $f->id);
        self::assertTrue($f->required);
    }
}
