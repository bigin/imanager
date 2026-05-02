<?php

declare(strict_types=1);

namespace Imanager\Query;

enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public static function coerce(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        return self::from(strtolower($value));
    }
}
