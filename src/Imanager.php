<?php

declare(strict_types=1);

namespace Imanager;

final class Imanager
{
    /**
     * Bump together with every git tag. Verified by ReleaseConsistencyTest
     * so a forgotten bump fails CI rather than ships silently.
     */
    public const VERSION = '2.2.0';
}
