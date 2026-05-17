# Upload files and generate thumbnails

You'll build a tiny photo gallery: one `Gallery` item per photo,
with a single `Imageupload` field. Along the way you'll see how
the upload pipeline splits responsibilities, where the validation
happens, what metadata you get back, how to generate thumbnails
on demand, and what a host application has to keep in sync that
iManager *doesn't* check for you.

## Why uploads are split into four pieces

A naïve "save uploaded file" function does five things at once:
validates the HTTP upload, sniffs the MIME, decides the storage
path, writes the bytes, records the metadata. iManager splits
them across four small types so each one has a single concern:

| Type | Concern | Lives in |
|---|---|---|
| `UploadedFile` | The source: wraps either `$_FILES[...]` or a local path. HTTP-agnostic. | `src/Files/UploadedFile.php` |
| `UploadConstraints` | The policy: max bytes, allowed MIME types, allowed extensions. Caller-built; not derived from `Field::config`. | `src/Files/UploadConstraints.php` |
| `UploadHandler` | The orchestrator: validates against constraints, stores bytes, records metadata. Stateless. | `src/Files/UploadHandler.php` |
| `FileStorage` / `FileRepository` | The destinations: bytes go through `FileStorage` (default: local disk), metadata goes through `FileRepository`. | `src/Files/FileStorage.php`, `src/Storage/FileRepository.php` |

Decoupling these means you can:

- Test the handler without spinning up `$_FILES` (use
  `UploadedFile::fromPath()` for local files).
- Swap the storage backend without touching the handler (S3,
  CAS store: anything that implements `FileStorage`).
- Reuse the same handler from an HTTP endpoint, a CLI import
  script, or a CI seed.

## Setup

```php
require __DIR__ . '/vendor/autoload.php';

use Imanager\DefaultBootstrap;
use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Files\FileStorage;
use Imanager\Files\ImageProcessor;
use Imanager\Files\UploadConstraints;
use Imanager\Files\UploadHandler;
use Imanager\Files\UploadedFile;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\FileRepository;
use Imanager\Storage\ItemRepository;
use Imanager\Validation\Sanitizer;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/data/gallery.db',
    uploadsPath:  __DIR__ . '/data/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/data/cache',
);

$categories = $container->get(CategoryRepository::class);
$fields     = $container->get(FieldRepository::class);
$items      = $container->get(ItemRepository::class);
$files      = $container->get(FileRepository::class);

$gallery = $categories->ensure(new Category(null, 'Gallery', 'gallery'));
$fields->ensure(Field::text($gallery->id, 'title', 'Title')->required()->maxLength(200));
$photoField = $fields->ensure(
    Field::image($gallery->id, 'photo', 'Photo')
        ->maxBytes(2_000_000)
        ->mimes('image/jpeg', 'image/png'),
);
```

The `photo` field is `FieldType::Imageupload`. The
`->maxBytes()` and `->mimes()` setters write into the field's
config. That config is **documentation, not enforcement**. The
actual enforcement comes from `UploadConstraints`, which you build
separately. See the "Constraints in two places" callout below.

## A first upload

Imagine your HTTP layer has put a multipart upload on `$_FILES['photo']`:

```php
$upload = UploadedFile::fromPhpUpload($_FILES['photo']);

$constraints = new UploadConstraints(
    maxSizeBytes:      2_000_000,
    allowedExtensions: ['jpg', 'jpeg', 'png'],
    allowedMimes:      ['image/jpeg', 'image/png'],
);
// Or, for the common image case:
$constraints = UploadConstraints::images(maxSizeBytes: 2_000_000);
```

`UploadedFile::fromPhpUpload()` runs `is_uploaded_file()` under the
hood — the PHP-mandated guard against arbitrary local files being
passed off as uploads — so passing a `$_FILES` entry that didn't
arrive via HTTP POST throws `UploadException`.

You also need an item to attach the file to:

```php
$item = $items->save(new Item(
    null, $gallery->id, 'sunset-2026', 'Sunset, May 2026',
    data: ['title' => 'Sunset, May 2026'],
));
```

Now build a handler and hand it everything:

```php
$uploader = new UploadHandler(
    storage:    $container->get(FileStorage::class),
    repository: $container->get(FileRepository::class),
    sanitizer:  $container->get(Sanitizer::class),
    images:     $container->get(ImageProcessor::class),   // optional, see below
);

$file = $uploader->handle(
    upload:      $upload,
    itemId:      $item->id,
    fieldId:     $photoField->id,
    constraints: $constraints,
);
```

> **Why `UploadHandler` isn't in the container.** Most of iManager's
> wiring lives in `DefaultBootstrap`, but `UploadHandler` does not.
> It's a four-line `new` and the dependencies are all explicit
> (storage / repository / sanitizer / image processor). Adding it
> to the container would just hide that. Construct it where you
> need it; one or two lines max.

What you get back is the persisted `File` value object:

```php
echo "Stored {$file->name} ({$file->size} bytes, {$file->width}×{$file->height})\n";
echo "Path: {$file->path}\n";          // e.g. "7/3/sunset-2026.jpg"
echo "MIME: {$file->mime}\n";           // sniffed, not trusted from the browser
```

A few things to notice:

- **`$file->path` is storage-relative**: `7/3/sunset-2026.jpg`,
  not `/var/www/uploads/7/3/sunset-2026.jpg`. The format is
  `<itemId>/<fieldId>/<safeName>`. Storage-relative paths let you
  swap backends without rewriting URLs.
- **The MIME is sniffed, not declared.** The browser-supplied
  `$upload->declaredMime` is the *fallback*; the handler runs
  `mime_content_type()` on the actual bytes first. Defends against
  the "user renames `malware.php` to `cat.jpg`" attack at the type
  layer (though you should still set `allowedMimes` to lock down
  what's actually allowed).
- **Filename collisions are resolved automatically.** Upload
  `sunset.jpg` twice to the same item/field and you get
  `sunset.jpg` + `sunset-2.jpg`. The handler walks `-2`, `-3`, …
  until it finds a free slot (gives up at 1000).
- **Image dimensions are filled in if `images` was passed.** Width
  / height come from `ImageProcessor::dimensions()`; non-images
  get `0, 0`. Pass `null` for the `images` parameter if you don't
  need dimensions or don't want the GD/Imagick dependency.

## Constraints in two places — read this twice

This is the most common source of "I set the limit, why isn't it
enforced?" confusion in iManager today.

**What `Field::image()->maxBytes(2_000_000)` does:**
writes `['maxBytes' => 2_000_000]` into the field's `config`
array. Stored on the field row in the `fields` table.

**What `Field::image()->maxBytes(2_000_000)` does NOT do:**
flow into `UploadHandler::handle()`. The handler reads from
`UploadConstraints`, not from `Field::$config`.

So enforcement requires *both*:

```php
// Schema metadata: documents the intent, persists on the field row:
$fields->ensure(
    Field::image($gallery->id, 'photo', 'Photo')
        ->maxBytes(2_000_000)
        ->mimes('image/jpeg', 'image/png'),
);

// Runtime enforcement: what the handler actually checks:
$constraints = new UploadConstraints(
    maxSizeBytes:      2_000_000,
    allowedExtensions: ['jpg', 'jpeg', 'png'],
    allowedMimes:      ['image/jpeg', 'image/png'],
);
```

Keep the two in sync yourself. A small helper avoids drift:

```php
function constraintsFromField(Field $field): UploadConstraints
{
    return new UploadConstraints(
        maxSizeBytes:      (int) ($field->config['maxBytes'] ?? 10 * 1024 * 1024),
        allowedExtensions: [],   // field config doesn't track extensions today
        allowedMimes:      (array) ($field->config['mimes'] ?? []),
    );
}
```

(A future iManager release may bake this in, e.g. a
`UploadConstraints::fromField(Field $field)` factory. Until then,
the helper is on you.)

## Uploads from somewhere other than `$_FILES`

CLI imports, CI seeds, queue-driven re-processing — anything that
already has the file as a local path — use `UploadedFile::fromPath()`:

```php
$upload = UploadedFile::fromPath('/tmp/imported/photo.jpg');
$file = $uploader->handle($upload, $item->id, $photoField->id, $constraints);
```

`fromPath()` bypasses the `is_uploaded_file()` HTTP-guard because
there's nothing HTTP-shaped about the input. Same handler, same
constraints, same `File` output. Only the source differs.

## Thumbnails

`ImageProcessor` is a thin wrapper over `intervention/image` v3.
The default driver is GD (universally available); pass an
`ImageManager` constructed with `Imagick` if you need EXIF
rotation or animated WebP.

Generate a thumbnail and store it next to the original:

```php
$images  = $container->get(ImageProcessor::class);
$storage = $container->get(FileStorage::class);

// Read original from storage, resize, write back to storage.
$sourcePath = $storage->absolutePath($file->path);
$thumbBytes = $images->thumbnail($sourcePath, width: 320);   // height auto-fits

$thumbRelativePath = \sprintf(
    '%d/%d/thumb-320_%s',
    $file->itemId,
    $file->fieldId,
    $file->name,
);
$storage->write($thumbRelativePath, $thumbBytes);
```

The handler does **not** generate thumbnails for you: it only
fills in `$file->width` / `$file->height` if you passed an
`ImageProcessor` to it. Thumbnail generation is a caller-side
decision because there's no one-size-fits-all answer (lazy vs eager,
single size vs responsive set, where the thumbs live).

A common pattern: generate thumbnails **lazily**, on first request,
behind a URL like `/uploads/7/3/thumb-320_sunset-2026.jpg`. Your
web server's rewrite rule sends 404s to a PHP handler that:

1. Parses the path → original path + target size.
2. Reads the original via `$storage->read()`.
3. Generates the thumbnail with `$images->thumbnail()`.
4. Writes it via `$storage->write()`.
5. Returns the bytes.

Every subsequent request hits the now-existing static file directly,
no PHP roundtrip until the next size is asked for. The whole
thumbnail tree lives next to the original under `<uploadsPath>`,
no separate cache directory to manage.

For eager generation (e.g. you need three responsive sizes
immediately on upload), call `$images->thumbnail()` three times
right after `$uploader->handle()` returns, and `$storage->write()`
each result. The cost is upfront, the thumbnails are ready before
the first page render.

## Reading files back

Walk an item's files via `FileRepository::findByItem()`:

```php
foreach ($files->findByItem($item->id) as $file) {
    $url = $storage->url($file->path);   // browser-fetchable URL
    echo "<img src=\"{$url}\" alt=\"{$file->title}\" width=\"{$file->width}\" height=\"{$file->height}\">\n";
}
```

If you have multiple file fields and want one specifically (say,
the cover image vs the gallery thumbnails), use
`findByItemAndField()` for a field-scoped slice:

```php
$covers = $files->findByItemAndField($item->id, $photoField->id);
$gallery = $files->findByItemAndField($item->id, $galleryField->id);
```

Files within a field come back in `position` order, useful when
the editor lets users drag-reorder a gallery. The
`$file->withPosition($n)` value-object helper lets you generate a
reorder write quickly:

```php
foreach ($reorderedIds as $position => $fileId) {
    $existing = $files->find($fileId);
    $files->save($existing->withPosition($position));
}
```

## File titles (caption-shaped metadata)

`File::$title` is a typed column (separate from `name`, which is
the on-disk filename). Use it for human-readable captions:
`$file->name` will be the sanitized filename
(`portrait-jane-doe.jpg`), while `$file->title` is whatever the
editor typed ("Jane Doe at the conference, 2026").

```php
$files->save($file->withTitle('Sunset over the harbor, May 2026'));
```

Useful for `<figcaption>` rendering, alt-text fallback, search-side
labelling without forcing the filename to carry semantics it
shouldn't.

## Cleanup is somebody else's job

The lifecycle chapter covers this in detail. Short version: when
you `$items->delete($itemId)`, the file METADATA cascades away via
the FK relationship, but the **physical bytes** on disk do not.
That's intentional (FileStorage backends may not support O(1)
delete; some are append-only), so cleanup is a host-supplied
listener subscribed to `ItemDeleted`. See
[`lifecycle.md`](lifecycle.md#wiring-file-cleanup-yourself) for
the canonical pattern.

## Swapping the storage backend

`LocalFileStorage` is the default: bytes go to a directory under
`uploadsPath`, URLs use `uploadsUrl` as the prefix. For S3, CDN
origins, or a content-addressed store, implement
`FileStorage`'s seven-method interface:

```php
interface FileStorage
{
    public function put(string $relativePath, string $sourcePath): string;
    public function write(string $relativePath, string $bytes): string;
    public function exists(string $relativePath): bool;
    public function delete(string $relativePath): void;
    public function read(string $relativePath): string;
    public function url(string $relativePath): string;
    public function absolutePath(string $relativePath): string;
}
```

Register it in the container after boot, overwriting the
`FileStorage::class` binding:

```php
$container->add(FileStorage::class, static fn(): FileStorage
    => new S3FileStorage(/* client, bucket, prefix */));
```

`UploadHandler`, `ImageProcessor`, and every listener that calls
`$storage->delete()` will use the new backend transparently. The
one wrinkle: `ImageProcessor` needs to *read* the original bytes
to resize them, and `dimensions()` / `thumbnail()` work against a
local filesystem path. For non-local backends, fetch + temp-file
first:

```php
$tmp = tempnam(sys_get_temp_dir(), 'imanager-thumb-');
file_put_contents($tmp, $storage->read($file->path));
$thumbBytes = $images->thumbnail($tmp, width: 320);
unlink($tmp);

$thumbRelative = sprintf('%d/%d/thumb-320_%s', $file->itemId, $file->fieldId, $file->name);
$storage->write($thumbRelative, $thumbBytes);
```

Slightly awkward; a future iManager release may add a
stream-friendly `ImageProcessor::thumbnailFromBytes()` overload.

## What just happened, in one paragraph

You learned that iManager splits the upload pipeline into four
small types — `UploadedFile` (the source), `UploadConstraints`
(the policy), `UploadHandler` (the orchestrator), and
`FileStorage` + `FileRepository` (the destinations) — and that
`UploadHandler` isn't in the container because it's a four-line
`new` with explicit dependencies. You handled both an HTTP-shaped
upload (`fromPhpUpload`) and a programmatic one (`fromPath`)
through the same handler. You also learned the most common
gotcha: `Field::image()->maxBytes(...)` is *metadata*, not
enforcement. Runtime enforcement lives in `UploadConstraints`,
and the two have to be kept in sync (a future release may
bridge them, but today it's your job). You saw thumbnail
generation as a caller-side concern (`ImageProcessor::thumbnail`
returns bytes; `$storage->write()` puts them somewhere), the
lazy-thumbs-via-404-handler pattern that keeps thumb storage
co-located with originals, and how to swap `LocalFileStorage` for
an S3-shaped backend by overwriting one container binding.

## Reference

- [`src/Files/UploadHandler.php`](../../src/Files/UploadHandler.php),
  the orchestrator, fully commented; the file is short and
  worth reading once.
- [`src/Files/UploadConstraints.php`](../../src/Files/UploadConstraints.php),
  the policy value object, with the `::images()` convenience.
- [`src/Files/FileStorage.php`](../../src/Files/FileStorage.php),
  the backend interface; `LocalFileStorage` is the default
  implementation.
- [`src/Files/ImageProcessor.php`](../../src/Files/ImageProcessor.php),
  the intervention/image v3 wrapper with `dimensions()` and
  `thumbnail()`.
- [`docs/api/domain.md`](../api/domain.md#file), the `File`
  value object.
- [`lifecycle.md`](lifecycle.md#wiring-file-cleanup-yourself),
  the cleanup listener pattern.
