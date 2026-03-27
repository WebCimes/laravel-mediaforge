# webcimes/laravel-mediaforge

Store images and files directly in your existing model columns — no separate media library table required.

Powered by [Intervention Image](https://image.intervention.io/v3), this Laravel package lets you upload a file once and automatically generate multiple formats (thumbnail, WebP, watermark, etc.), each saved as a structured entry you can store in any JSON/text column of your choosing.

## Table of contents

- [Why this package?](#why-this-package)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Basic usage](#basic-usage)
- [ImageFormat reference](#imageformat-reference)
- [Responsive images (srcset)](#responsive-images-srcset)
- [Handle files (upload + delete + reorder in one call)](#handle-files-upload--delete--reorder-in-one-call)
- [Regenerate a format](#regenerate-a-format)
- [Delete files](#delete-files)
- [Custom base name](#custom-base-name)
- [Queue processing](#queue-processing)
  - [Enabling queue processing](#enabling-queue-processing)
  - [Listening for completion (advanced / fallback)](#listening-for-completion-advanced--fallback)
  - [Queue configuration](#queue-configuration)
  - [Filament + queue](#filament--queue)
- [Filament integration](#filament-integration)
- [Configuration](#configuration)

## Why this package?

Most media libraries (like Spatie Media Library) introduce a dedicated `media` table that links files to models through a polymorphic relationship. This works great for complex scenarios, but adds overhead when you just want to attach one or a few images to a model.

**With this package:**

- Image data (path, dimensions, format config) is stored directly in whichever column you choose — a JSON column, a `text` column, even inside a JSON API response.
- No extra table, no polymorphic join, no extra migration.
- The upload result is a plain PHP array — store it anywhere, serialize it however you like.
- Regenerate derivative formats at any time from the stored original.

## Features

- Fluent `ImageFormat` builder — chain transforms in a readable, IDE-friendly way
- Multi-format processing in one upload (default + thumb + WebP, all at once)
- Responsive image variants via `->srcset()` — base format + per-width variants with resize type inheritance
- All formats of the same upload grouped in a single folder for easy management
- ULID-based unique naming (collision-proof, chronologically sortable, URL-safe)
- Plain PHP array output — no model binding required
- Regenerate derivative formats from the stored original at any time
- Queue support — heavy format processing dispatched as a background job (opt-in)
- Works with any Laravel filesystem disk (local, S3, SFTP, …)

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Intervention Image 3.x — requires either the **GD** or **Imagick** PHP extension

## Installation

```bash
composer require webcimes/laravel-mediaforge
```

Publish the config file:

```bash
php artisan vendor:publish --tag="mediaforge-config"
```

## Basic usage

```php
use Webcimes\LaravelMediaforge\ImageFormat;
use Webcimes\LaravelMediaforge\Facades\MediaForge;

// Upload and generate two formats in one call:
$imageData = MediaForge::upload(
    $request->file('cover'),
    'public', // disk
    'products', // base path inside the disk
    [
        ImageFormat::make('default')->scaleDown(1920, 1080)->quality(80)->extension('webp'),
        ImageFormat::make('thumb')->cover(400, 300)->quality(65)->extension('webp'),
    ],
);

// Store $imageData directly in your model column (cast it to 'array' or 'json'):
$product->update(['cover' => $imageData]);
```

`$imageData` is a plain array — store it in any JSON column:

```php
// $imageData:
[
    'default' => [
        'disk'   => 'public',
        'path'   => 'products/my-cover_01jq8z.../default.webp',
        'width'  => 1920,
        'height' => 1080,
        'alt'    => 'my-cover',
    ],
    'thumb' => [
        'disk'             => 'public',
        'path'             => 'products/my-cover_01jq8z.../thumb.webp',
        'width'            => 400,
        'height'           => 300,
        'alt'              => 'my-cover',
        // 'customAttributes' only present when defined via ->customAttributes([...])
        'customAttributes' => ['role' => 'thumbnail'],
    ],
]
```

## ImageFormat reference

<!-- prettier-ignore-start -->
| Method                                 | Description                                           |
| -------------------------------------- | ----------------------------------------------------- |
| `->disk('s3')`                         | Override the storage disk for this format             |
| `->path('media/thumbs')`               | Override the storage directory                        |
| `->extension('webp')`                  | Convert to a specific format                          |
| `->quality(75)`                        | Encoding quality 1–100 (JPEG, WebP, AVIF, HEIC, TIFF) |
| `->filename('hero')`                   | Override the file name (without extension)            |
| `->suffix('_2x')`                      | Append a suffix before the extension                  |
| `->resize(w, h)`                       | Exact dimensions — no ratio preservation              |
| `->resizeDown(w, h)`                   | Same — only shrinks                                   |
| `->scale(w, h?)`                       | Proportional fit — preserves ratio                    |
| `->scaleDown(w, h?)`                   | Same — only shrinks                                   |
| `->cover(w, h)`                        | Center-crop to exact dimensions                       |
| `->coverDown(w, h)`                    | Same — only shrinks                                   |
| `->text('Draft', [...])`               | Text overlay (requires a TTF font — see config)       |
| `->watermark('/path/logo.png', [...])` | Image watermark overlay                               |
| `->srcset([1920, 1080, 720])`          | Responsive image variants — see [Responsive images](#responsive-images-srcset) |
| `->alt('My image')`                    | Override the alt text for this format (defaults to filename stem) |
| `->customAttributes([...])`            | Custom metadata stored alongside this format entry    |
<!-- prettier-ignore-end -->

## Responsive images (srcset)

`->srcset()` expands one `ImageFormat` into a **base format entry** plus **multiple width variants**, each saved as an independent format entry. This is the recommended way to produce responsive images for use with the HTML `srcset` attribute.

```php
$imageData = MediaForge::upload(
    $request->file('hero'),
    'public',
    'uploads',
    [
        ImageFormat::make('hero')
            ->srcset([1920, 1080, 720, 480])
            ->extension('webp')
            ->quality(80),
    ],
);
```

This produces **six** format keys (base + four variants + auto-injected `default`):

```php
[
    'default'    => ['disk' => 'public', 'path' => 'uploads/hero_xxx/default.jpg',      'width' => 2000, 'height' => 1000, 'alt' => 'hero'], // original — auto-injected, kept for regeneration
    'hero'       => ['disk' => 'public', 'path' => 'uploads/hero_xxx/hero.webp',         'width' => 2000, 'height' => 1000, 'alt' => 'hero'], // base: full size, webp+quality applied
    'hero_1920w' => ['disk' => 'public', 'path' => 'uploads/hero_xxx/hero_1920w.webp',  'width' => 1920, 'height' => 960,  'alt' => 'hero'],
    'hero_1080w' => ['disk' => 'public', 'path' => 'uploads/hero_xxx/hero_1080w.webp',  'width' => 1080, 'height' => 540,  'alt' => 'hero'],
    'hero_720w'  => ['disk' => 'public', 'path' => 'uploads/hero_xxx/hero_720w.webp',   'width' => 720,  'height' => 360,  'alt' => 'hero'],
    'hero_480w'  => ['disk' => 'public', 'path' => 'uploads/hero_xxx/hero_480w.webp',   'width' => 480,  'height' => 240,  'alt' => 'hero'],
]
```

**Resize type inheritance** — each variant inherits the resize method configured on the format:

| Configured on format | Variant behaviour |
| -------------------- | ----------------- |
| `cover(1000, 500)` | `cover(width, proportional_height)` — e.g. at 400w → `cover(400, 200)` |
| `scaleDown(1920)` | `scaleDown(width)` — height auto-computed by Intervention |
| *(none)* | falls back to `scaleDown(width)` — aspect ratio preserved, never upscales |

The **base format** (`hero`) always uses the dimensions you configured explicitly and is never filtered by `skipLarger`.

Example — square thumbnails at multiple sizes:

```php
ImageFormat::make('thumb')
    ->cover(1000, 1000)
    ->srcset([200, 400, 800])
    ->extension('webp')
    ->quality(75),
// Generates: thumb (1000×1000), thumb_200w (200×200), thumb_400w (400×400), thumb_800w (800×800)
```

Example — proportional banner (keep aspect ratio, only shrink):

```php
ImageFormat::make('banner')
    ->scaleDown(1920, 600)   // base: max 1920×600, ratio preserved
    ->srcset([960, 480])
    ->extension('webp'),
// Generates: banner (≤1920×600), banner_960w (≤960×300), banner_480w (≤480×150)
```

Example — free resize without ratio preservation:

```php
ImageFormat::make('og')
    ->resize(1200, 630)      // exact 1200×630 for Open Graph, no ratio preserved
    ->srcset([600])
    ->extension('jpg'),
// Generates: og (1200×630), og_600w (600×315)
```

**`skipLarger`** (default `true`) — variants whose target width exceeds the source image width are skipped (no file written, no entry created). The base format is never filtered.

```php
// Default — source 600px wide: only hero_480w is written
ImageFormat::make('hero')->srcset([1920, 1080, 720, 480])->extension('webp');

// skipLarger: false — all variants are written (scaleDown caps them at source width)
ImageFormat::make('hero')->srcset([1920, 1080, 720, 480], skipLarger: false)->extension('webp');
```

**All other options (`extension()`, `quality()`, `watermark()`, `text()`, `alt()`, `customAttributes()`) are inherited by the base format and all variants.**

**Building an `<img srcset="…">` attribute (in your Blade view):**

```php
$entry = $product->cover; // the stored array

$srcset = collect($entry)
    ->filter(fn($v, $k) => str_ends_with($k, 'w') && isset($v['width']))
    ->map(fn($v) => Storage::disk($v['disk'])->url($v['path']) . ' ' . $v['width'] . 'w')
    ->implode(', ');

// Use the base format (e.g. 'hero') as src — it has the right extension/quality applied.
// Do NOT use 'default' in src: it is the raw original (no conversion, no quality), kept only for regeneration.
// <img src="{{ Storage::disk($entry['hero']['disk'])->url($entry['hero']['path']) }}"
//      srcset="{{ $srcset }}"
//      sizes="100vw"
//      alt="{{ $entry['hero']['alt'] }}">
```



`handleFiles()` is designed to process the payload emitted by a file input component in a single call: upload new files, delete removed ones, and apply a global ordering across both existing and new items.

```php
$validated['images'] = MediaForge::handleFiles(
    diskName: 'public',
    path: 'products',
    uploadedFiles: $validated['images']['files'] ?? null,     // new UploadedFile[]
    filesToDeleteIndex: $validated['images']['deleted'] ?? null, // int[] — indexes into $existingFiles
    globalOrder: $validated['images']['globalOrder'] ?? null, // full ordered list of all items
    existingFiles: $product->images,                          // current DB value
    imageFormats: $this->imageFormats,                        // optional, same as upload()
);

$product->update(['images' => $validated['images']]);
```

The method returns the updated flat array ready to be stored, or `null` if the result is empty.

**`globalOrder`** is an array of ordering directives, one per surviving file:

```php
// Scenario: 3 existing files in DB (indexes 0, 1, 2), index 1 deleted, 2 new files uploaded.
// filesToDeleteIndex: [1]
// uploadedFiles: [$new0, $new1]
// Desired order: new1, existing-0, existing-2, new0

[
    ['type' => 'new',      'index' => 1], // new1      → position 0
    ['type' => 'existing', 'index' => 0], // existing0 → position 1
    ['type' => 'existing', 'index' => 2], // existing2 → position 2 (existing1 was deleted)
    ['type' => 'new',      'index' => 0], // new0      → position 3
]
```

- `type`: `'existing'` (already in DB) or `'new'` (just uploaded)
- `index` for `existing`: position in the `$existingFiles` array passed to the method
- `index` for `new`: position in the `$uploadedFiles` array (0-based, matches `DataTransfer` order in the browser)
- The **array order** defines the final position — the first item ends up at position 0, and so on.

When `globalOrder` is provided, any uploaded file whose index is **not** referenced is automatically deleted from disk to prevent orphaned files.

When `globalOrder` is omitted, existing files come first (in their original order), followed by newly uploaded files.

## Regenerate a format

Re-process derivatives from the stored original at any time — useful when you change the design:

```php
$updated = MediaForge::regenerate($product->cover, [
    ImageFormat::make('thumb')->cover(200, 200)->extension('avif'),
]);

$product->update(['cover' => $updated]);
```

## Delete files

```php
// Deletes all files referenced in the stored entry:
MediaForge::delete($product->cover, 'public');
```

## Custom base name

The upload folder name is `{slug}_{ulid}` by default. Override it:

```php
// Auto (default): slug + ULID  → my-photo_01jq8z...
MediaForge::upload($file, 'public', 'uploads', $formats);

// ULID only (no slug)          → 01jq8z...
MediaForge::upload($file, 'public', 'uploads', $formats, '');

// Custom prefix + ULID         → product-hero_01jq8z...
MediaForge::upload($file, 'public', 'uploads', $formats, 'product-hero');
```

## Queue processing

By default all formats are processed synchronously during the upload request. For large images or many format variants, you can dispatch the processing of non-`default` formats to a background queue job — keeping the HTTP response fast.

The `default` format is **always** processed synchronously so the caller always receives a valid file immediately. All other formats are dispatched as a `ProcessImageFormatsJob` and produce a `processing: true` stub in the meantime.

### Enabling queue processing

Pass `queued: true` to `upload()` or `handleFiles()`. Add `model:` and `modelColumn:` to let the job update the database column automatically once processing finishes — **no event listener required**.

**`upload()` — single file**

```php
$imageData = MediaForge::upload(
    $request->file('cover'),
    'public',
    'uploads',
    [
        ImageFormat::make('default')->scaleDown(1920, 1080)->extension('webp'),
        ImageFormat::make('thumb')->cover(400, 300)->extension('webp'),
        ImageFormat::make('hero')->srcset([1920, 1080, 720])->extension('webp'),
    ],
    queued: true,
    model: $product,       // Eloquent model instance
    modelColumn: 'cover',  // attribute name that stores the media data
);

// Store the partial result immediately (default = ready, others = stubs):
$product->update(['cover' => $imageData]);
// The job will call $product->cover = $fullEntry; $product->save(); automatically when done.
```

**`handleFiles()` — multi-file with queue**

```php
$media = MediaForge::handleFiles(
    diskName: 'public',
    path: 'products',
    uploadedFiles: $validated['images']['files'] ?? null,
    filesToDeleteIndex: $validated['images']['deleted'] ?? null,
    globalOrder: $validated['images']['globalOrder'] ?? null,
    existingFiles: $product->images,
    imageFormats: $this->imageFormats,
    queued: true,
    model: $product,       // Eloquent model instance
    modelColumn: 'images', // attribute name
);

$product->update(['images' => $media]);
// Non-default formats are processed in the background and the column is updated automatically.
```

> **`$afterCommit`** is enabled on the job — it only runs after the current database transaction commits, so the model row is guaranteed to exist when the job tries to update it. If no transaction is active (Filament does not wrap operations in transactions by default), the job is dispatched immediately — which is equally safe since the record has already been saved at that point.

> **Nested JSON columns** — `modelColumn` supports dot-notation to target a value nested inside a JSON column: `modelColumn: 'content.hero.image'`. The first segment is the DB column name (`content`); the rest is the path within the decoded JSON value (`hero.image`). See [Filament + queue](#filament--queue) for usage with the Filament component.

The `default` format is always synchronous; all other formats produce a `processing: true` stub:

```php
// $imageData right after upload(..., queued: true)
[
    'default'    => ['disk' => ..., 'path' => ..., 'width' => 1920, ...],  // ready
    'thumb'      => ['processing' => true, 'disk' => 'public'],             // pending
    'hero'       => ['processing' => true, 'disk' => 'public'],             // pending (base)
    'hero_1920w' => ['processing' => true, 'disk' => 'public'],             // pending
    'hero_1080w' => ['processing' => true, 'disk' => 'public'],             // pending
    'hero_720w'  => ['processing' => true, 'disk' => 'public'],             // pending
]
```

### Listening for completion (advanced / fallback)

The job always fires `ImageFormatsProcessed` once all formats are written to disk.
You only need to listen to this event for advanced use-cases — auto-update of the model column is handled automatically without any listener.

```php
use Webcimes\LaravelMediaforge\Events\ImageFormatsProcessed;

Event::listen(ImageFormatsProcessed::class, function (ImageFormatsProcessed $event) {
    // $event->defaultPath → path of the 'default' format, used as a lookup key
    // $event->entry       → complete entry with all format keys fully resolved
});
```

### Queue configuration

In `.env`:

```dotenv
MEDIAFORGE_QUEUE_CONNECTION=redis   # null = use the app's default QUEUE_CONNECTION
MEDIAFORGE_QUEUE_NAME=mediaforge    # Isolate MediaForge jobs on their own queue (defaults to 'default' if left empty)
```

Start a dedicated worker for MediaForge jobs:

```bash
php artisan queue:work --queue=mediaforge
```

If `MEDIAFORGE_QUEUE_NAME` is left as `default`, jobs run on the shared default queue — fine for simple setups, but a dedicated queue is recommended for production to prevent heavy image processing from blocking other jobs.

### Filament + queue

The Filament `MediaForgeFileUpload` component has a `->queued()` option:

```php
MediaForgeFileUpload::make('cover')
    ->imageFormats([...])
    ->queued()   // enable background processing
```

The component auto-detects the field name as the model column. For **simple top-level columns** (`cover`, `images`, …) no extra configuration is needed.

For **nested JSON columns** (e.g. `$model->content['hero']['image']`), override the column path with `->modelColumn()`:

```php
MediaForgeFileUpload::make('image')   // field name inside the form
    ->imageFormats([...])
    ->queued()
    ->modelColumn('content.hero.image')  // 'content' = DB column, 'hero.image' = path within JSON
```

On **edit forms** the record already exists when the upload fires — the component automatically passes `getRecord()` and the field name to the job. The model column is updated once the job completes, no listener needed.

On **create forms** Filament calls `saveUploadedFileUsing` during `form->getState()`, which runs *before* `model::create()`. `getRecord()` returns `null` at that point, but the component automatically passes the model class via `getModel()`. Because the job runs with `$afterCommit = true`, it only executes after the transaction commits and the record exists. It then locates the record by searching for the unique `defaultPath` in the column and updates it. Everything is automatic — no listener needed on create forms either.

## Filament integration

`MediaForgeFileUpload` is a drop-in replacement for Filament's `FileUpload` component. It transparently delegates storage to `MediaForge` and encodes the resulting format map as JSON in the database column.

All native `FileUpload` methods (`->multiple()`, `->reorderable()`, `->disk()`, `->directory()`, `->panelLayout()`, etc.) work exactly as in standard Filament. The MediaForge-specific additions are `->imageFormats()` and `->queued()`:

```php
use Webcimes\LaravelMediaforge\Filament\Forms\Components\MediaForgeFileUpload;
use Webcimes\LaravelMediaforge\ImageFormat;

MediaForgeFileUpload::make('cover')
    ->label('Cover image')
    ->imageFormats([
        ImageFormat::make('default')
            ->scaleDown(1920, 1080)
            ->quality(75)
            ->extension('webp'),
        ImageFormat::make('thumb')
            ->cover(400, 300)
            ->quality(60)
            ->extension('webp'),
    ])
    ->queued()   // optional — dispatch non-default formats to a background job
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->multiple()
    ->reorderable()
    ->appendFiles()
    ->openable()
    ->disk('public')
    ->directory('uploads')
    ->panelLayout('grid'),
```

The column value stored in the database is a plain array (cast it to `array` or `json` on the model):

```php
// Single upload (no ->multiple()):
[
    'default' => ['disk' => 'public', 'path' => 'uploads/hero_01jq8z.../default.webp', 'width' => 1920, 'height' => 1080, 'alt' => 'hero'],
    'thumb'   => ['disk' => 'public', 'path' => 'uploads/hero_01jq8z.../thumb.webp',   'width' => 400,  'height' => 300,  'alt' => 'hero'],
]

// Multiple uploads (->multiple()):
[
    [
        'default' => ['disk' => 'public', 'path' => 'uploads/img-a_01jq8z.../default.webp', ...],
        'thumb'   => ['disk' => 'public', 'path' => 'uploads/img-a_01jq8z.../thumb.webp',   ...],
    ],
    [
        'default' => ['disk' => 'public', 'path' => 'uploads/img-b_01jq8z.../default.webp', ...],
        'thumb'   => ['disk' => 'public', 'path' => 'uploads/img-b_01jq8z.../thumb.webp',   ...],
    ],
]
```

## Configuration

After publishing (`php artisan vendor:publish --tag="mediaforge-config"`):

```php
return [
    // Image processing driver:
    //   'gd'      — Built into PHP, works on every host. Default.
    //   'imagick' — Requires ext-imagick. Better quality, native AVIF/HEIC. Recommended if available.
    //   'vips'    — Requires: composer require intervention/image-driver-vips + libvips.
    //               Fastest and lowest memory, but rarely available on shared hosting.
    'driver' => 'gd', // switch to 'imagick' if available on your server

    // Text overlay defaults. Any key passed to ImageFormat::text([...]) overrides these.
    // The default font is Montserrat Regular (TTF) bundled in the package — no setup needed.
    // To use a custom font, set an absolute path to a TTF or OTF file:
    'text' => [
        'font' => null, // defaults to Montserrat TTF bundled in vendor; override: resource_path('fonts/my-font.ttf')
        'size' => 48,
        'color' => 'rgba(255, 255, 255, .75)',
        'align' => 'center',
        'valign' => 'middle',
        'angle' => 0,
        'wrap' => 0, // max line width in px before wrapping; 0 = no wrap
    ],

    // Watermark overlay defaults.
    'watermark' => [
        'position' => 'center',
        'x' => 0,
        'y' => 0,
        'opacity' => 75,
    ],

    // Queue configuration for background format processing (upload(..., queued: true)).
    // The 'default' format is always synchronous; all other formats are dispatched as a job.
    // Set MEDIAFORGE_QUEUE_NAME to a dedicated queue (e.g. 'mediaforge') to isolate processing
    // from your other jobs. Leave MEDIAFORGE_QUEUE_CONNECTION as null to use the app default.
    'queue' => [
        'connection' => null,      // null = app default (QUEUE_CONNECTION). Other values: 'redis', 'database', 'sqs', 'sync'
        'name'       => 'default', // queue name — use a dedicated name like 'mediaforge' in production
    ],
];
```

## License

MIT
