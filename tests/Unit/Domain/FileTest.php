<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(File::class)]
final class FileTest extends TestCase
{
    public function testStoresAllConstructorArgumentsVerbatim(): void
    {
        $file = new File(
            id: 7,
            itemId: 3,
            fieldId: 5,
            name: 'photo.jpg',
            path: '3/5/photo.jpg',
            mime: 'image/jpeg',
            size: 12345,
            width: 800,
            height: 600,
            position: 2,
            created: 1700000000,
        );

        self::assertSame(7, $file->id);
        self::assertSame(3, $file->itemId);
        self::assertSame(5, $file->fieldId);
        self::assertSame('photo.jpg', $file->name);
        self::assertSame('3/5/photo.jpg', $file->path);
        self::assertSame('image/jpeg', $file->mime);
        self::assertSame(12345, $file->size);
        self::assertSame(800, $file->width);
        self::assertSame(600, $file->height);
        self::assertSame(2, $file->position);
        self::assertSame(1700000000, $file->created);
    }

    public function testWithIdReturnsACopyCarryingTheAssignedId(): void
    {
        $a = new File(null, 1, 1, 'x.txt', '1/1/x.txt', 'text/plain', 1);
        $b = $a->withId(99);

        self::assertNotSame($a, $b);
        self::assertNull($a->id);
        self::assertSame(99, $b->id);
    }

    public function testIsImageReflectsMimePrefix(): void
    {
        $image = new File(null, 1, 1, 'a.jpg', '1/1/a.jpg', 'image/jpeg', 1);
        $document = new File(null, 1, 1, 'a.pdf', '1/1/a.pdf', 'application/pdf', 1);

        self::assertTrue($image->isImage());
        self::assertFalse($document->isImage());
    }

    public function testRejectsZeroOrNegativeIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('itemId');
        new File(null, 0, 1, 'a.txt', '0/1/a.txt', 'text/plain', 1);
    }

    public function testRejectsEmptyNameOrPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new File(null, 1, 1, '', '1/1/x.txt', 'text/plain', 1);
    }

    public function testRejectsNegativeSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new File(null, 1, 1, 'a.txt', '1/1/a.txt', 'text/plain', -1);
    }
}
