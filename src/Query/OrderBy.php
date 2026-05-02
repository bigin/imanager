<?php

declare(strict_types=1);

namespace Imanager\Query;

final readonly class OrderBy
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Asc,
    ) {}
}
