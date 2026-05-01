<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown when a requested entity (Category, Field, Item) does not exist.
 *
 * Use the named factories instead of the bare constructor to keep error
 * messages consistent across the codebase.
 */
final class NotFoundException extends \RuntimeException implements ImanagerException
{
    public static function category(int|string $idOrSlug): self
    {
        return new self(\sprintf('Category "%s" not found', (string) $idOrSlug));
    }

    public static function field(int $categoryId, int|string $idOrName): self
    {
        return new self(\sprintf(
            'Field "%s" not found in category %d',
            (string) $idOrName,
            $categoryId,
        ));
    }

    public static function item(int $categoryId, int $id): self
    {
        return new self(\sprintf('Item %d not found in category %d', $id, $categoryId));
    }
}
