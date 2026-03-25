# webcimes/laravel-mediaforge

Store images and files directly in your existing model columns — no separate media library table required.

Powered by [Intervention Image](https://image.intervention.io/v3), this Laravel package lets you upload a file once and automatically generate multiple formats (thumbnail, WebP, watermark, etc.), each saved as a structured entry you can store in any JSON/text column of your choosing.

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
- All formats of the same upload grouped in a single folder for easy management
- ULID-based unique naming (collision-proof, chronologically sortable, URL-safe)
- Plain PHP array output — no model binding required
- Regenerate derivative formats from the stored original at any time
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
| `->alt('My image')`                    | Override the alt text for this format (defaults to filename stem) |
| `->customAttributes([...])`            | Custom metadata stored alongside this format entry    |
<!-- prettier-ignore-end -->

## Filament integration

`MediaForgeUpload` is a drop-in replacement for Filament's `FileUpload` component. It transparently delegates storage to `MediaForge` and encodes the resulting format map as JSON in the database column.

All native `FileUpload` methods (`->multiple()`, `->reorderable()`, `->disk()`, `->directory()`, `->panelLayout()`, etc.) work exactly as in standard Filament. The only addition is `->imageFormats()`:

```php
use Webcimes\LaravelMediaforge\Filament\Forms\Components\MediaForgeUpload;
use Webcimes\LaravelMediaforge\ImageFormat;

MediaForgeUpload::make('cover')
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
];
```

## License

MIT
