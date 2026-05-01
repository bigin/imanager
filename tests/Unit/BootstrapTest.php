<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit;

use Imanager\Bootstrap;
use Imanager\Config;
use League\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    public function testBootReturnsAContainer(): void
    {
        self::assertInstanceOf(Container::class, Bootstrap::boot());
    }

    public function testContainerResolvesADefaultConfigWhenNoOverridesGiven(): void
    {
        $container = Bootstrap::boot();
        $config    = $container->get(Config::class);

        self::assertInstanceOf(Config::class, $config);
        self::assertFalse($config->debug);
    }

    public function testContainerAppliesConfigOverrides(): void
    {
        $container = Bootstrap::boot(['debug' => true, 'maxItemsPerPage' => 50]);
        $config    = $container->get(Config::class);

        self::assertInstanceOf(Config::class, $config);
        self::assertTrue($config->debug);
        self::assertSame(50, $config->maxItemsPerPage);
    }

    public function testConfigIsResolvedAsASharedSingleton(): void
    {
        $container = Bootstrap::boot();

        self::assertSame($container->get(Config::class), $container->get(Config::class));
    }

    public function testContainerResolvesAPsr3LoggerByDefault(): void
    {
        $container = Bootstrap::boot();
        $logger    = $container->get(LoggerInterface::class);

        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::assertInstanceOf(NullLogger::class, $logger);
    }

    public function testLoggerIsResolvedAsASharedSingleton(): void
    {
        $container = Bootstrap::boot();

        self::assertSame(
            $container->get(LoggerInterface::class),
            $container->get(LoggerInterface::class),
        );
    }
}
