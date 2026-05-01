<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit;

use Imanager\Config;
use Imanager\Exception\ConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[CoversClass(ConfigException::class)]
final class ConfigTest extends TestCase
{
    public function testDefaultsAreReasonableForProduction(): void
    {
        $c = Config::default();

        self::assertFalse($c->debug, 'debug must default to false (production-safe)');
        self::assertSame(30, $c->maxFieldNameLength);
        self::assertSame(255, $c->maxItemNameLength);
        self::assertSame(10, $c->maxItemsPerPage);
        self::assertFalse($c->backupCategories);
        self::assertSame('position', $c->filterByItems);
        self::assertSame(0o755, $c->chmodDir);
        self::assertSame(0o644, $c->chmodFile);
        self::assertSame('Y-m-d H:i:s', $c->systemDateFormat);
        self::assertSame(['width' => 150, 'height' => 0], $c->thumbSize);
    }

    public function testDatabasePathDefaultsBelowDataPath(): void
    {
        $c = Config::default();

        self::assertSame($c->dataPath . '/imanager.db', $c->databasePath);
    }

    public function testMergeReturnsANewInstanceAndKeepsOriginalImmutable(): void
    {
        $original = Config::default();
        $merged = $original->merge(['debug' => true, 'maxItemsPerPage' => 25]);

        self::assertNotSame($original, $merged);
        self::assertFalse($original->debug);
        self::assertSame(10, $original->maxItemsPerPage);
        self::assertTrue($merged->debug);
        self::assertSame(25, $merged->maxItemsPerPage);
    }

    public function testMergeWithEmptyArrayReturnsTheSameInstance(): void
    {
        $original = Config::default();
        self::assertSame($original, $original->merge([]));
    }

    public function testMergeRejectsUnknownKeys(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unknown config keys: nonsense');

        $this->mergeUntyped(Config::default(), ['nonsense' => 1]);
    }

    public function testMergeRejectsWrongType(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "debug" must be of type bool, got string');

        $this->mergeUntyped(Config::default(), ['debug' => 'yes']);
    }

    public function testMergeAcceptsAValidThumbSize(): void
    {
        $c = Config::default()->merge(['thumbSize' => ['width' => 200, 'height' => 150]]);

        self::assertSame(['width' => 200, 'height' => 150], $c->thumbSize);
    }

    public function testMergeRejectsAMalformedThumbSize(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config key "thumbSize"');

        $this->mergeUntyped(Config::default(), ['thumbSize' => ['width' => 200]]);
    }

    public function testFromArrayIsAFactoryThatStartsFromDefault(): void
    {
        $c = Config::fromArray(['debug' => true]);

        self::assertTrue($c->debug);
        // Other defaults are preserved
        self::assertSame(10, $c->maxItemsPerPage);
    }

    public function testConstructorRejectsAnEmptyDataPath(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('dataPath');

        Config::default()->merge(['dataPath' => '']);
    }

    public function testConstructorRejectsZeroMaxItemsPerPage(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('maxItemsPerPage');

        Config::default()->merge(['maxItemsPerPage' => 0]);
    }

    /**
     * Test helper that intentionally bypasses the static-typed `ConfigArray`
     * shape on `Config::merge()`. Used to feed deliberately malformed input
     * into the validation paths without triggering static-analysis errors at
     * the call site (which is the exact behavior under test).
     *
     * @param array<string, mixed> $overrides
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    private function mergeUntyped(Config $config, array $overrides): Config
    {
        return $config->merge($overrides);
    }
}
