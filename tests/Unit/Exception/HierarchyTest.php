<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Exception;

use Imanager\Enum\InputErrorCode;
use Imanager\Exception\ConfigException;
use Imanager\Exception\ImanagerException;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\SchemaException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(SchemaException::class)]
#[CoversClass(StorageException::class)]
#[CoversClass(ValidationException::class)]
final class HierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: \Throwable, 1: class-string<\Throwable>}>
     */
    public static function exceptions(): iterable
    {
        yield 'StorageException is RuntimeException' => [
            new StorageException('boom'),
            \RuntimeException::class,
        ];
        yield 'ValidationException is RuntimeException' => [
            new ValidationException('title', InputErrorCode::EmptyRequired),
            \RuntimeException::class,
        ];
        yield 'SchemaException is RuntimeException' => [
            new SchemaException('boom'),
            \RuntimeException::class,
        ];
        yield 'NotFoundException is RuntimeException' => [
            NotFoundException::category(1),
            \RuntimeException::class,
        ];
        yield 'ConfigException is LogicException' => [
            new ConfigException('boom'),
            \LogicException::class,
        ];
    }

    #[DataProvider('exceptions')]
    public function testEveryConcreteExceptionImplementsImanagerException(\Throwable $e): void
    {
        self::assertInstanceOf(ImanagerException::class, $e);
    }

    /**
     * @param class-string<\Throwable> $expectedSplBase
     */
    #[DataProvider('exceptions')]
    public function testEveryConcreteExceptionExtendsTheCorrectSplBase(
        \Throwable $e,
        string $expectedSplBase,
    ): void {
        self::assertInstanceOf($expectedSplBase, $e);
    }
}
