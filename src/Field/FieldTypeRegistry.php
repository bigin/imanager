<?php

declare(strict_types=1);

namespace Imanager\Field;

use Imanager\Enum\FieldType;
use Imanager\Exception\FieldTypeNotRegisteredException;

/**
 * Container for `FieldTypePlugin` instances, keyed by their canonical name.
 *
 * Built-in plugins are registered at boot (Phase 14 wires them through the
 * DI container); third-party plugins from themes or modules can register
 * additional types without recompiling iManager. A name collision overwrites
 * the previous registration — last writer wins, on purpose, so a project
 * can swap out a built-in by registering its own under the same name.
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldTypePlugin> */
    private array $plugins = [];

    public function register(FieldTypePlugin $plugin): void
    {
        $this->plugins[$plugin::name()] = $plugin;
    }

    public function has(FieldType|string $type): bool
    {
        $key = $type instanceof FieldType ? $type->value : $type;
        return isset($this->plugins[$key]);
    }

    public function get(FieldType|string $type): FieldTypePlugin
    {
        $key = $type instanceof FieldType ? $type->value : $type;
        if (! isset($this->plugins[$key])) {
            throw FieldTypeNotRegisteredException::forName($key);
        }
        return $this->plugins[$key];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->plugins);
    }
}
