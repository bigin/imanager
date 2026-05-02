<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
