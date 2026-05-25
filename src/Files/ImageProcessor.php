<?php

declare(strict_types=1);

namespace Imanager\Files;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

/**
 * Thin wrapper over `intervention/image` v3 with the operations iManager
 * actually uses: dimension lookup and aspect-preserving resize for thumbnails.
 *
 * The default constructor binds the GD driver because GD is universally
 * available on the PHP hosts iManager targets. Pass a custom `ImageManager`
 * (e.g. `ImageManager::imagick()`) to swap drivers.
 */
final readonly class ImageProcessor
{
    public function __construct(private ImageManager $manager) {}

    public static function default(): self
    {
        return new self(new ImageManager(new GdDriver()));
    }

    /**
     * @return array{width: int, height: int}
     */
    public function dimensions(string $path): array
    {
        try {
            $image = $this->manager->read($path);
        } catch (\Throwable $e) {
            throw new ImageProcessingException(
                \sprintf('Cannot read image "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }
        return [
            'width' => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * Aspect-preserving resize. Pass `$height = 0` to derive height from
     * the source aspect ratio (the common "fit to width" case). Same for
     * `$width = 0`.
     *
     * The source image is read from `$sourcePath`, resized in memory and
     * the encoded bytes returned. The caller decides where the result
     * lands — typically through `FileStorage::write()`.
     */
    public function thumbnail(string $sourcePath, int $width, int $height = 0): string
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Thumbnail dimensions must be >= 0');
        }
        if ($width === 0 && $height === 0) {
            throw new \InvalidArgumentException('At least one of width / height must be > 0');
        }

        try {
            $image = $this->manager->read($sourcePath);

            // Validation above guarantees both dimensions are >= 0 and at
            // least one is > 0, so the `=== 0` checks alone pick the
            // single-axis paths without redundant `> 0` companions.
            if ($height === 0) {
                $image = $image->scale(width: $width);
            } elseif ($width === 0) {
                $image = $image->scale(height: $height);
            } else {
                $image = $image->scaleDown(width: $width, height: $height);
            }

            return (string) $image->encode();
        } catch (\Throwable $e) {
            throw new ImageProcessingException(
                \sprintf('Cannot generate thumbnail for "%s": %s', $sourcePath, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
