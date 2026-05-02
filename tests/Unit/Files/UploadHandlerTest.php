<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Files;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Enum\FieldType;
use Imanager\Files\LocalFileStorage;
use Imanager\Files\UploadConstraints;
use Imanager\Files\UploadedFile;
use Imanager\Files\UploadException;
use Imanager\Files\UploadHandler;
use Imanager\Storage\InMemory\InMemoryStorage;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadHandler::class)]
#[CoversClass(UploadedFile::class)]
#[CoversClass(UploadConstraints::class)]
#[CoversClass(UploadException::class)]
final class UploadHandlerTest extends TestCase
{
    private string $storageRoot;
    private InMemoryStorage $storage;
    private LocalFileStorage $files;
    private UploadHandler $handler;
    private int $itemId;
    private int $fieldId;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/imanager-upload-' . uniqid();
        $this->storage = new InMemoryStorage();
        $this->files = new LocalFileStorage($this->storageRoot);

        $this->handler = new UploadHandler(
            storage: $this->files,
            repository: $this->storage->files(),
            sanitizer: new Sanitizer(),
        );

        $cat = $this->storage->categories()->save(new Category(null, 'Blog', 'blog'));
        \assert($cat->id !== null);
        $field = $this->storage->fields()->save(new Field(
            id: null,
            categoryId: $cat->id,
            name: 'attachments',
            label: 'Attachments',
            type: FieldType::Fileupload,
        ));
        \assert($field->id !== null);
        $this->fieldId = $field->id;

        $item = $this->storage->items()->save(new Item(null, $cat->id));
        \assert($item->id !== null);
        $this->itemId = $item->id;
    }

    protected function tearDown(): void
    {
        $this->wipe($this->storageRoot);
    }

    public function testHappyPathPersistsFileAndRecord(): void
    {
        $upload = $this->seedUpload('hello.txt', 'text/plain', 'hello world');

        $file = $this->handler->handle(
            $upload,
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(allowedExtensions: ['txt']),
        );

        self::assertNotNull($file->id);
        self::assertSame('hello.txt', $file->name);
        self::assertSame($this->itemId, $file->itemId);
        self::assertSame($this->fieldId, $file->fieldId);
        self::assertSame('text/plain', $file->mime);
        self::assertSame(11, $file->size);
        self::assertTrue($this->files->exists($file->path));
        self::assertSame('hello world', $this->files->read($file->path));
    }

    public function testRejectsExtensionOutsideAllowList(): void
    {
        $upload = $this->seedUpload('script.exe', 'application/x-dosexec', 'binary');

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Extension "exe"');
        $this->handler->handle(
            $upload,
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(allowedExtensions: ['txt', 'pdf']),
        );
    }

    public function testRejectsMimeOutsideAllowList(): void
    {
        $upload = $this->seedUpload('photo.jpg', 'application/octet-stream', 'fake');

        // mime_content_type sniffs from content; "fake" looks like text/plain.
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Mime');
        $this->handler->handle(
            $upload,
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(allowedMimes: ['image/jpeg', 'image/png']),
        );
    }

    public function testRejectsOversizedUpload(): void
    {
        $upload = new UploadedFile(
            name: 'big.txt',
            declaredMime: 'text/plain',
            tmpPath: $this->seedTmpFile(str_repeat('A', 1024)),
            size: 1024,
        );

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('1024 bytes; max allowed is 100');
        $this->handler->handle(
            $upload,
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(maxSizeBytes: 100),
        );
    }

    public function testCollidingFilenamesGetSuffixed(): void
    {
        $first = $this->handler->handle(
            $this->seedUpload('photo.txt', 'text/plain', 'one'),
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(),
        );
        $second = $this->handler->handle(
            $this->seedUpload('photo.txt', 'text/plain', 'two'),
            $this->itemId,
            $this->fieldId,
            new UploadConstraints(),
        );

        self::assertSame('photo.txt', $first->name);
        self::assertSame('photo.txt', $second->name);
        self::assertNotSame($first->path, $second->path);
        self::assertStringContainsString('photo-2.txt', $second->path);
    }

    public function testRespectsUploadErrorCode(): void
    {
        $upload = new UploadedFile(
            name: 'bad.txt',
            declaredMime: 'text/plain',
            tmpPath: '',
            size: 0,
            errorCode: \UPLOAD_ERR_INI_SIZE,
        );

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('upload_max_filesize');
        $this->handler->handle($upload, $this->itemId, $this->fieldId, new UploadConstraints());
    }

    public function testUploadConstraintsForImagesShorthand(): void
    {
        $constraints = UploadConstraints::images();

        self::assertTrue($constraints->permitsExtension('jpg'));
        self::assertTrue($constraints->permitsExtension('JPG'));
        self::assertFalse($constraints->permitsExtension('exe'));
        self::assertTrue($constraints->permitsMime('image/jpeg'));
        self::assertFalse($constraints->permitsMime('text/plain'));
    }

    public function testFromPathConstructorWorksOutsideHttpContext(): void
    {
        $tmp = $this->seedTmpFile('cli-content');
        $upload = UploadedFile::fromPath($tmp, 'manual.txt');

        self::assertSame('manual.txt', $upload->name);
        self::assertSame(\UPLOAD_ERR_OK, $upload->errorCode);
    }

    private function seedUpload(string $name, string $mime, string $bytes): UploadedFile
    {
        return new UploadedFile(
            name: $name,
            declaredMime: $mime,
            tmpPath: $this->seedTmpFile($bytes),
            size: \strlen($bytes),
        );
    }

    private function seedTmpFile(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imanager-upload-src-');
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
