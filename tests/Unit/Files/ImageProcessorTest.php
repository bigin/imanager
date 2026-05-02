<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Files;

use Imanager\Files\ImageProcessingException;
use Imanager\Files\ImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageProcessor::class)]
#[CoversClass(ImageProcessingException::class)]
final class ImageProcessorTest extends TestCase
{
    private ImageProcessor $processor;
    private string $tmpDir;

    protected function setUp(): void
    {
        if (! \extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required for ImageProcessor');
        }
        $this->processor = ImageProcessor::default();
        $this->tmpDir = sys_get_temp_dir() . '/imanager-img-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testReturnsExactDimensionsForSourceImage(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 200, 100);

        $dimensions = $this->processor->dimensions($path);

        self::assertSame(200, $dimensions['width']);
        self::assertSame(100, $dimensions['height']);
    }

    public function testThumbnailScalesToFitWidth(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 400, 200);

        $thumbBytes = $this->processor->thumbnail($path, width: 100);

        $thumbPath = $this->tmpDir . '/thumb.png';
        file_put_contents($thumbPath, $thumbBytes);
        $size = getimagesize($thumbPath);
        self::assertIsArray($size);
        self::assertSame(100, $size[0]);
        self::assertSame(50, $size[1]);
    }

    public function testThumbnailScalesToFitHeight(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 400, 200);

        $thumbBytes = $this->processor->thumbnail($path, width: 0, height: 100);

        $thumbPath = $this->tmpDir . '/thumb.png';
        file_put_contents($thumbPath, $thumbBytes);
        $size = getimagesize($thumbPath);
        self::assertIsArray($size);
        self::assertSame(200, $size[0]);
        self::assertSame(100, $size[1]);
    }

    public function testThumbnailScaleDownStaysWithinBoundsPreservingAspect(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 400, 200); // 2:1 aspect

        $thumbBytes = $this->processor->thumbnail($path, width: 100, height: 100);

        $thumbPath = $this->tmpDir . '/thumb.png';
        file_put_contents($thumbPath, $thumbBytes);
        $size = getimagesize($thumbPath);
        self::assertIsArray($size);
        // 2:1 source fits inside 100x100 by becoming 100x50.
        self::assertSame(100, $size[0]);
        self::assertSame(50, $size[1]);
    }

    public function testRejectsZeroByZeroDimensions(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 100, 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->processor->thumbnail($path, 0, 0);
    }

    public function testRejectsNegativeDimensions(): void
    {
        $path = $this->tmpDir . '/source.png';
        $this->createPng($path, 100, 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->processor->thumbnail($path, -1, 0);
    }

    public function testRaisesProcessingExceptionForUnreadableInput(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->processor->dimensions($this->tmpDir . '/does-not-exist.png');
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function createPng(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        \assert($img !== false);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200) ?: 0);
        imagepng($img, $path);
        imagedestroy($img);
    }
}
