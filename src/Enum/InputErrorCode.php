<?php

declare(strict_types=1);

namespace Imanager\Enum;

/**
 * Validation error codes returned by Field/Input validators.
 *
 * Replaces the integer constants `EMPTY_REQUIRED`, `ERR_MIN_LENGTH`, ...
 * from iManager 1.x's InputInterface. The integer values are kept stable
 * so that any consumer that persisted them numerically still works.
 */
enum InputErrorCode: int
{
    case EmptyRequired = -1;
    case MinLengthExceeded = -2;
    case MaxLengthExceeded = -3;
    case WrongValueFormat = -4;
    case ComparisonFailed = -5;
    case UndefinedCategoryId = -6;
}
