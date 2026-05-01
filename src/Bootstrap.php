<?php

declare(strict_types=1);

namespace Imanager;

use League\Container\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Boot the iManager DI container.
 *
 * The container returned from {@see boot()} is the canonical service locator
 * for the rest of the library. Subsequent phases will register additional
 * services (Storage, Repositories, FieldTypeRegistry, ...) on the same
 * container; for now only Config and a PSR-3 logger are wired up.
 *
 * @phpstan-import-type ConfigArray from Config
 */
final class Bootstrap
{
    /**
     * @param ConfigArray $configOverrides
     */
    public static function boot(array $configOverrides = []): Container
    {
        $container = new Container();

        $container->addShared(
            Config::class,
            static fn(): Config => Config::fromArray($configOverrides),
        );

        $container->addShared(
            LoggerInterface::class,
            static fn(): LoggerInterface => new NullLogger(),
        );

        return $container;
    }

    private function __construct()
    {
        // Static-only entry point; no instances.
    }
}
