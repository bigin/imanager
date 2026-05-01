<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown when the runtime configuration is invalid or incomplete.
 *
 * Logic-level error: bad config is a programming/deployment mistake,
 * not a transient runtime condition.
 */
final class ConfigException extends \LogicException implements ImanagerException
{
    /**
     * @param list<string> $keys
     */
    public static function unknownKeys(array $keys): self
    {
        return new self('Unknown config keys: ' . implode(', ', $keys));
    }

    public static function invalidType(string $key, string $expected, string $actual): self
    {
        return new self(\sprintf(
            'Config key "%s" must be of type %s, got %s',
            $key,
            $expected,
            $actual,
        ));
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self(\sprintf('Config key "%s" is invalid: %s', $key, $reason));
    }
}
