<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\SqliteAffinity;
use Imanager\Exception\FieldTypeNotRegisteredException;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\FieldTypeRegistry;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\TextFieldType;
use Imanager\Field\ValidationResult;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(FieldTypeNotRegisteredException::class)]
final class FieldTypeRegistryTest extends TestCase
{
    public function testRegisterMakesAPluginRetrievableByEnum(): void
    {
        $registry = new FieldTypeRegistry();
        $plugin = new TextFieldType(new Sanitizer());

        $registry->register($plugin);

        self::assertSame($plugin, $registry->get(FieldType::Text));
    }

    public function testRegisterMakesAPluginRetrievableByStringName(): void
    {
        $registry = new FieldTypeRegistry();
        $plugin = new TextFieldType(new Sanitizer());

        $registry->register($plugin);

        self::assertSame($plugin, $registry->get('text'));
    }

    public function testHasReturnsFalseForUnregisteredTypes(): void
    {
        $registry = new FieldTypeRegistry();

        self::assertFalse($registry->has(FieldType::Text));
        self::assertFalse($registry->has('text'));
    }

    public function testHasReturnsTrueAfterRegistration(): void
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new TextFieldType(new Sanitizer()));

        self::assertTrue($registry->has(FieldType::Text));
    }

    public function testGetThrowsForUnregisteredType(): void
    {
        $registry = new FieldTypeRegistry();

        $this->expectException(FieldTypeNotRegisteredException::class);
        $this->expectExceptionMessage('Field type "text" is not registered');
        $registry->get(FieldType::Text);
    }

    public function testReregisteringTheSameNameOverwritesTheEarlierPlugin(): void
    {
        $registry = new FieldTypeRegistry();
        $first = new TextFieldType(new Sanitizer());
        $second = new TextFieldType(new Sanitizer());

        $registry->register($first);
        $registry->register($second);

        self::assertSame($second, $registry->get(FieldType::Text));
    }

    public function testNamesListsRegisteredPluginsInInsertionOrder(): void
    {
        $registry = new FieldTypeRegistry();
        $sanitizer = new Sanitizer();
        $registry->register(new TextFieldType($sanitizer));
        $registry->register($this->fakePlugin('custom'));

        self::assertSame(['text', 'custom'], $registry->names());
    }

    private function fakePlugin(string $name): FieldTypePlugin
    {
        return new class ($name) implements FieldTypePlugin {
            // @phpstan-ignore-next-line we want a per-instance name on this fake
            public function __construct(private string $instanceName) {}

            public static function name(): string
            {
                return 'custom';
            }

            public static function affinity(): SqliteAffinity
            {
                return SqliteAffinity::Text;
            }

            public function defaultConfig(): array
            {
                return [];
            }

            public function validate(mixed $rawValue, Field $field): ValidationResult
            {
                return ValidationResult::ok($rawValue);
            }

            public function render(mixed $value, Field $field, RenderContext $context): string
            {
                return '';
            }
        };
    }
}
