<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown when {@see \Imanager\Field\FieldTypeRegistry::get()} is asked for a
 * type that wasn't registered — typically because a custom plugin from a
 * theme or module wasn't wired into the container.
 */
final class FieldTypeNotRegisteredException extends \OutOfBoundsException implements ImanagerException
{
    public static function forName(string $name): self
    {
        return new self(\sprintf('Field type "%s" is not registered', $name));
    }
}
