<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Http;

use Imanager\Http\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArraySessionStore::class)]
final class ArraySessionStoreTest extends TestCase
{
    public function testSetAndGetRoundTrip(): void
    {
        $store = new ArraySessionStore();
        $store->set('foo', 'bar');

        self::assertSame('bar', $store->get('foo'));
        self::assertTrue($store->has('foo'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $store = new ArraySessionStore();

        self::assertNull($store->get('absent'));
        self::assertSame('fallback', $store->get('absent', 'fallback'));
        self::assertFalse($store->has('absent'));
    }

    public function testGetDistinguishesNullValueFromMissingKey(): void
    {
        $store = new ArraySessionStore();
        $store->set('explicit', null);

        self::assertTrue($store->has('explicit'));
        self::assertNull($store->get('explicit', 'fallback'));
    }

    public function testRemove(): void
    {
        $store = new ArraySessionStore();
        $store->set('foo', 'bar');
        $store->remove('foo');

        self::assertFalse($store->has('foo'));
        self::assertNull($store->get('foo'));
    }

    public function testInstancesAreIsolated(): void
    {
        $a = new ArraySessionStore();
        $b = new ArraySessionStore();
        $a->set('foo', 'bar');

        self::assertNull($b->get('foo'));
    }
}
