<?php

declare(strict_types=1);

namespace Imanager\Storage;

/**
 * The persistence-layer entry point.
 *
 * `Storage` is a small umbrella over the three repositories plus a generic
 * transaction-scope helper. Concrete implementations:
 *
 * - `Imanager\Storage\InMemory\InMemoryStorage` — Phase 3, used in tests.
 * - `Imanager\Storage\Sqlite\SqliteStorage`     — Phase 4, production.
 *
 * @see CategoryRepository
 * @see FieldRepository
 * @see ItemRepository
 */
interface Storage
{
    public function categories(): CategoryRepository;

    public function fields(): FieldRepository;

    public function items(): ItemRepository;

    /**
     * Run `$work` inside a transaction. The callback's return value becomes
     * the method's return value. Any exception thrown inside `$work` aborts
     * the transaction and propagates up.
     *
     * Implementations must guarantee atomicity: either every mutation made
     * inside `$work` is visible afterwards, or none is.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;
}
