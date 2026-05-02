<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Domain;

use Imanager\Domain\FieldValueBag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValueBag::class)]
final class FieldValueBagTest extends TestCase
{
    public function testEmptyBagReportsItself(): void
    {
        $bag = new FieldValueBag();

        self::assertTrue($bag->isEmpty());
        self::assertSame(0, $bag->count());
        self::assertSame([], $bag->toArray());
    }

    public function testHasReturnsTrueForExistingKeyEvenWhenValueIsNull(): void
    {
        $bag = new FieldValueBag(['title' => null]);

        self::assertTrue($bag->has('title'));
        self::assertFalse($bag->has('absent'));
    }

    public function testGetReturnsTheStoredValue(): void
    {
        $bag = new FieldValueBag(['title' => 'Hello', 'count' => 0]);

        self::assertSame('Hello', $bag->get('title'));
        self::assertSame(0, $bag->get('count'));
    }

    public function testGetReturnsTheDefaultForMissingKey(): void
    {
        $bag = new FieldValueBag(['title' => 'Hello']);

        self::assertNull($bag->get('absent'));
        self::assertSame('fallback', $bag->get('absent', 'fallback'));
    }

    public function testGetDoesNotConfuseANullValueWithAMissingKey(): void
    {
        $bag = new FieldValueBag(['title' => null]);

        self::assertNull($bag->get('title', 'fallback'));
    }

    public function testWithReturnsANewBagWithAnAddedField(): void
    {
        $bag = new FieldValueBag(['a' => 1]);
        $next = $bag->with('b', 2);

        self::assertNotSame($bag, $next);
        self::assertSame(['a' => 1], $bag->toArray());
        self::assertSame(['a' => 1, 'b' => 2], $next->toArray());
    }

    public function testWithOverwritesAnExistingField(): void
    {
        $bag = new FieldValueBag(['a' => 1]);
        $next = $bag->with('a', 99);

        self::assertSame(['a' => 99], $next->toArray());
    }

    public function testWithoutDropsAField(): void
    {
        $bag = new FieldValueBag(['a' => 1, 'b' => 2]);
        $next = $bag->without('a');

        self::assertSame(['a' => 1, 'b' => 2], $bag->toArray());
        self::assertSame(['b' => 2], $next->toArray());
    }

    public function testWithoutOnAMissingFieldIsANoOp(): void
    {
        $bag = new FieldValueBag(['a' => 1]);
        $next = $bag->without('nope');

        self::assertSame($bag->toArray(), $next->toArray());
    }

    public function testMergeAcceptsAnotherBag(): void
    {
        $a = new FieldValueBag(['a' => 1, 'b' => 2]);
        $b = new FieldValueBag(['b' => 99, 'c' => 3]);

        $merged = $a->merge($b);

        self::assertSame(['a' => 1, 'b' => 99, 'c' => 3], $merged->toArray());
    }

    public function testMergeAcceptsAnArray(): void
    {
        $a = new FieldValueBag(['a' => 1]);
        $merged = $a->merge(['b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $merged->toArray());
    }
}
