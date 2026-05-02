<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Cache;

use Imanager\Cache\FilesystemCache;
use Imanager\Cache\InvalidCacheKeyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemCache::class)]
#[CoversClass(InvalidCacheKeyException::class)]
final class FilesystemCacheTest extends TestCase
{
    private string $cacheDir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/imanager-cache-' . uniqid();
        $this->cache = new FilesystemCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->wipe($this->cacheDir);
    }

    public function testGetMissReturnsDefault(): void
    {
        self::assertNull($this->cache->get('absent'));
        self::assertSame('fallback', $this->cache->get('absent', 'fallback'));
    }

    public function testSetAndGetRoundTripsString(): void
    {
        $this->cache->set('homepage_nav', '<nav>...</nav>');

        self::assertSame('<nav>...</nav>', $this->cache->get('homepage_nav'));
    }

    public function testSetReturnsTrueOnSuccess(): void
    {
        self::assertTrue($this->cache->set('k', 'v'));
    }

    public function testRoundTripsArbitraryPhpValues(): void
    {
        $this->cache->set('list', [1, 2, 3]);
        $this->cache->set('null', null);
        $this->cache->set('bool', false);
        $this->cache->set('struct', ['name' => 'iManager', 'pages' => 7]);

        self::assertSame([1, 2, 3], $this->cache->get('list'));
        self::assertNull($this->cache->get('null'));
        self::assertFalse($this->cache->get('bool'));
        self::assertSame(['name' => 'iManager', 'pages' => 7], $this->cache->get('struct'));
    }

    public function testHasDistinguishesStoredNullFromMissingKey(): void
    {
        $this->cache->set('explicit-null', null);

        self::assertTrue($this->cache->has('explicit-null'));
        self::assertFalse($this->cache->has('truly-absent'));
    }

    public function testTtlInSecondsExpiresEntry(): void
    {
        // ttl=1 → expires within 1 second; sleeping 2 seconds is brittle but
        // reliable. We instead set a *negative* effective TTL by passing 0 —
        // the implementation puts the expiry one second in the past.
        $this->cache->set('short', 'value', ttl: 0);

        self::assertNull($this->cache->get('short'));
    }

    public function testTtlAsDateIntervalIsHonoured(): void
    {
        $this->cache->set('with-interval', 'value', ttl: new \DateInterval('PT1H'));

        self::assertSame('value', $this->cache->get('with-interval'));
    }

    public function testDefaultTtlAppliesWhenNoneIsPassed(): void
    {
        $cache = new FilesystemCache($this->cacheDir, defaultTtlSeconds: 0);
        $cache->set('relies-on-default', 'value');

        self::assertNull($cache->get('relies-on-default'));
    }

    public function testNullDefaultTtlMeansNeverExpires(): void
    {
        $cache = new FilesystemCache($this->cacheDir);
        $cache->set('no-ttl', 'value');

        self::assertSame('value', $cache->get('no-ttl'));
    }

    public function testDeleteRemovesEntry(): void
    {
        $this->cache->set('victim', 'v');
        self::assertTrue($this->cache->delete('victim'));
        self::assertNull($this->cache->get('victim'));
    }

    public function testDeleteMissingKeyReturnsTrue(): void
    {
        self::assertTrue($this->cache->delete('never-set'));
    }

    public function testClearWipesEverything(): void
    {
        $this->cache->set('a', '1');
        $this->cache->set('b', '2');
        $this->cache->set('homepage_nav', '<nav/>');

        self::assertTrue($this->cache->clear());

        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
        self::assertNull($this->cache->get('homepage_nav'));
        // Root cache directory still exists for subsequent writes.
        self::assertDirectoryExists($this->cacheDir);
    }

    public function testGetMultipleAndSetMultipleAndDeleteMultiple(): void
    {
        $this->cache->setMultiple(['a' => '1', 'b' => '2', 'c' => '3']);

        $hits = [];
        foreach ($this->cache->getMultiple(['a', 'b', 'c']) as $key => $value) {
            $hits[(string) $key] = $value;
        }
        self::assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $hits);

        $this->cache->deleteMultiple(['a', 'b']);

        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
        self::assertSame('3', $this->cache->get('c'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function reservedKeys(): iterable
    {
        yield 'curly braces' => ['has{brace}'];
        yield 'parens'       => ['has(paren)'];
        yield 'slash'        => ['has/slash'];
        yield 'backslash'    => ['has\\backslash'];
        yield 'at sign'      => ['has@at'];
        yield 'colon'        => ['has:colon'];
    }

    #[DataProvider('reservedKeys')]
    public function testRejectsKeysWithReservedCharacters(string $key): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->cache->get($key);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->cache->get('');
    }

    public function testCorruptedFileIsTreatedAsMissAndDropped(): void
    {
        $this->cache->set('legit', 'value');
        // Corrupt the on-disk entry by overwriting it with garbage.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isFile()) {
                file_put_contents($entry->getPathname(), 'junk');
                break;
            }
        }

        self::assertNull($this->cache->get('legit'));
    }

    public function testWritesAreAtomicAcrossConcurrentLookalike(): void
    {
        // Smoke check that the tmp+rename dance leaves no .tmp residue once
        // a write completes.
        $this->cache->set('atomic', str_repeat('A', 1024));
        self::assertSame(str_repeat('A', 1024), $this->cache->get('atomic'));

        $tmps = glob($this->cacheDir . '/**/*.tmp');
        self::assertSame([], $tmps ?: []);
    }

    private function wipe(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
