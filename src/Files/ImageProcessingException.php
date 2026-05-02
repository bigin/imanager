<?php

declare(strict_types=1);

namespace Imanager\Files;

use Imanager\Exception\ImanagerException;

/**
 * Raised by {@see ImageProcessor} when the underlying image library
 * (GD or Imagick via intervention/image) cannot decode, resize or
 * encode the input.
 */
final class ImageProcessingException extends \RuntimeException implements ImanagerException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
