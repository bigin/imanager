<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Files;

use Imanager\Files\FileStorageException;
use Imanager\Files\LocalFileStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalFileStorage::class)]
#[CoversClass(FileStorageException::class)]
final class LocalFileStorageTest extends TestCase
{
    private string $root;
    private LocalFileStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/imanager-storage-' . uniqid();
        $this->storage = new LocalFileStorage($this->root, '/uploads');
    }

    protected function tearDown(): void
    {
        $this->wipe($this->root);
    }

    public function testPutMovesSourceIntoRoot(): void
    {
        $source = $this->seedTmpFile('hello world');

        $absolute = $this->storage->put('1/2/photo.txt', $source);

        self::assertSame($this->root . '/1/2/photo.txt', $absolute);
        self::assertFileExists($this->root . '/1/2/photo.txt');
        self::assertFileDoesNotExist($source);
        self::assertSame('hello world', file_get_contents($this->root . '/1/2/photo.txt'));
    }

    public function testWriteAtomicallyCreatesFileFromBytes(): void
    {
        $absolute = $this->storage->write('1/2/data.bin', "\x00\x01\x02");

        self::assertFileExists($absolute);
        self::assertSame("\x00\x01\x02", file_get_contents($absolute));
        // No leftover .tmp residue.
        self::assertSame([], glob($this->root . '/1/2/*.tmp') ?: []);
    }

    public function testReadReturnsBytes(): void
    {
        $this->storage->write('a/b/c.txt', 'content');

        self::assertSame('content', $this->storage->read('a/b/c.txt'));
    }

    public function testReadOnMissingPathThrows(): void
    {
        $this->expectException(FileStorageException::class);
        $this->storage->read('nope.txt');
    }

    public function testExistsReflectsPresence(): void
    {
        self::assertFalse($this->storage->exists('a.txt'));
        $this->storage->write('a.txt', 'x');
        self::assertTrue($this->storage->exists('a.txt'));
    }

    public function testDeleteRemovesFile(): void
    {
        $this->storage->write('victim.txt', 'x');

        $this->storage->delete('victim.txt');

        self::assertFalse($this->storage->exists('victim.txt'));
    }

    public function testDeleteOnMissingFileIsNoOp(): void
    {
        // Pre-condition: file truly absent.
        self::assertFalse($this->storage->exists('never-existed'));

        $this->storage->delete('never-existed');

        // Post-condition: still absent, no exception thrown.
        self::assertFalse($this->storage->exists('never-existed'));
    }

    public function testUrlPrependsPublicBase(): void
    {
        self::assertSame('/uploads/1/2/photo.jpg', $this->storage->url('1/2/photo.jpg'));
    }

    public function testRejectsAbsoluteRelativePath(): void
    {
        $this->expectException(FileStorageException::class);
        $this->storage->write('/etc/passwd', 'oops');
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(FileStorageException::class);
        $this->storage->write('a/../../../escape.txt', 'oops');
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(FileStorageException::class);
        $this->storage->write('', 'oops');
    }

    private function seedTmpFile(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imanager-source-');
        \assert(\is_string($tmp));
        file_put_contents($tmp, $bytes);
        return $tmp;
    }

    private function wipe(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
