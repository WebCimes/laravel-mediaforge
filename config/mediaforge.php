<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    | Supported: 'gd', 'imagick', 'vips'.
    |
    | 'gd'      — Built into PHP, available everywhere. Safe default.
    | 'imagick' — Recommended when available: better colour accuracy, native AVIF
    |             & HEIC support. Requires the PHP Imagick extension.
    | 'vips'    — Fastest, lowest memory, but rarely available on shared hosting.
    |             Requires: composer require intervention/image-driver-vips
    */
    'driver' => env('FILE_SERVICE_DRIVER', 'gd'), // switch to 'imagick' if available on your server

    /*
    |--------------------------------------------------------------------------
    | Text Overlay Defaults
    |--------------------------------------------------------------------------
    | Default options applied when using ImageFormat::text(). Any option
    | explicitly passed to text([...]) will override these defaults.
    |
    | The default font is Montserrat Regular (TTF), bundled with this package —
    | no setup required. To use a custom font, provide an absolute path to a
    | font file (TTF, OTF, WOFF, etc.).
    |
    | Without a font file, GD falls back to its internal bitmap pixel fonts
    | (sizes 1–5), which are tiny and pixelated — unusable for real watermarks.
    */
    'text' => [
        // Defaults to Montserrat TTF bundled in vendor. Override with an absolute path to a font file.
        'font' => null,
        'size' => 48,
        'color' => 'rgba(255, 255, 255, .75)',
        'align' => 'center',
        'valign' => 'middle',
        'angle' => 0,
        'wrap'  => null,   // max line width in px before text wraps; null = no wrap
    ],

    /*
    |--------------------------------------------------------------------------
    | Watermark Defaults
    |--------------------------------------------------------------------------
    | Default options applied when using ImageFormat::watermark(). Any option
    | explicitly passed to watermark([...]) will override these defaults.
    */
    'watermark' => [
        'position' => 'center',
        'x' => 0,
        'y' => 0,
        'opacity' => 75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Srcset Defaults
    |--------------------------------------------------------------------------
    | Default widths used when calling ->srcset() without any arguments.
    | These are the most common CSS breakpoints; adjust to match your design
    | system. Passing explicit widths to ->srcset([...]) always takes precedence.
    */
    'srcset' => [
        'widths' => [1920, 1440, 1280, 1024, 768, 480],
    ],
];
