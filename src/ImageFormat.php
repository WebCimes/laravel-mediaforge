<?php

namespace Webcimes\LaravelMediaforge;

class ImageFormat
{
    private ?string $disk = null;

    private ?string $path = null;

    private string $suffix = '';

    private ?string $extension = null;

    private ?string $filename = null;

    private ?string $resizeType = null;

    private ?int $width = null;

    private ?int $height = null;

    private ?int $quality = null;

    private ?string $text = null;

    private array $textOptions = [];

    private ?string $watermark = null;

    private array $watermarkOptions = [];

    private array $customAttributes = [];

    private ?string $alt = null;

    public function __construct(private readonly string $name = 'default') {}

    /**
     * Create a new ImageFormat instance.
     * The `$name` identifies the format (e.g. `'default'`, `'thumb'`) and is used
     * as the filename and as the key in the stored media JSON.
     *
     * Example: `ImageFormat::make('thumb')->cover(400, 300)->extension('webp')`
     */
    public static function make(string $name = 'default'): static
    {
        return new static($name);
    }

    /**
     * Override the storage disk for this format.
     * Defaults to the `$diskName` passed to `MediaForge::upload()` when not set.
     *
     * Example: `ImageFormat::make('thumb')->disk('s3')`
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Override the storage directory for this format.
     * Defaults to `{baseDirectory}/{baseName}/` (e.g. `uploads/hero-img_abc123/`) when not set.
     * The `{baseName}` folder groups all format files of the same upload together.
     *
     * Example: `ImageFormat::make('thumb')->path('media/thumbnails')`
     */
    public function path(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Suffix appended to the generated filename, before the extension.
     * Defaults to `''` (no suffix) when not set.
     *
     * Example: `ImageFormat::make('retina')->suffix('_2x')` → `photo_abc123_2x.webp`
     */
    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Force a specific output file extension, triggering format conversion.
     * Defaults to keeping the original file extension when not set.
     *
     * Example: `ImageFormat::make('default')->extension('webp')` converts any upload to WebP.
     *
     * @param 'jpg'|'jpeg'|'png'|'gif'|'webp'|'avif'|'bmp'|'tiff'|'heic'|'jp2' $extension
     */
    public function extension(string $extension): static
    {
        $this->extension = $extension;

        return $this;
    }

    /**
     * Override the output filename (without extension) for this format.
     * Defaults to the format name (e.g. `default`, `thumb`) when not set.
     *
     * Since the parent folder already contains a unique ID, there is no need
     * to add randomness to the filename — the full path is always unique.
     *
     * Example: `ImageFormat::make('default')->filename('hero')` → `hero.webp`
     */
    public function filename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Encoding quality from 1 (lowest) to 100 (best).
     * Supported formats: JPEG, JPEG 2000, WebP, AVIF, HEIC, TIFF.
     * PNG, GIF and BMP do not use this parameter.
     * Defaults to the Intervention Image encoder default (~90) when not set.
     *
     * Example: `ImageFormat::make('thumb')->quality(60)` for a lightweight thumbnail.
     */
    public function quality(int $quality): static
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Resize the image to exact dimensions.
     * Both width and height are required. Does NOT preserve aspect ratio — the image may distort
     * if the target proportions differ from the original. Use `scale()` or `cover()` instead
     * if distortion is not acceptable.
     */
    public function resize(int $width, int $height): static
    {
        $this->resizeType = 'resize';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Same as `resize()` but only shrinks — never enlarges.
     * Does NOT preserve aspect ratio. Smaller images are left untouched.
     */
    public function resizeDown(int $width, int $height): static
    {
        $this->resizeType = 'resizeDown';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Scale the image proportionally to fit within the given dimensions. Preserves aspect ratio.
     * Omit `$height` to scale by width only (height is computed automatically).
     * Both enlarging and shrinking are applied.
     *
     * Example: `ImageFormat::make('preview')->scale(1200)` keeps the aspect ratio.
     */
    public function scale(int $width, ?int $height = null): static
    {
        $this->resizeType = 'scale';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Same as `scale()` but only shrinks — never enlarges. Preserves aspect ratio.
     * The most common choice for a 'default' format to cap upload resolution.
     *
     * Example: `ImageFormat::make('default')->scaleDown(1920, 1080)`
     */
    public function scaleDown(int $width, ?int $height = null): static
    {
        $this->resizeType = 'scaleDown';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Crop and resize the image to fill the exact dimensions. Preserves aspect ratio via
     * center-crop — excess pixels are trimmed, no empty space, no distortion.
     * Both enlarging and shrinking are applied.
     *
     * Example: `ImageFormat::make('thumb')->cover(400, 300)`
     */
    public function cover(int $width, int $height): static
    {
        $this->resizeType = 'cover';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Same as `cover()` but only shrinks — never enlarges. Preserves aspect ratio via center-crop.
     * Smaller images are left untouched.
     */
    public function coverDown(int $width, int $height): static
    {
        $this->resizeType = 'coverDown';
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Add a text overlay to the image.
     * Any option not provided falls back to the defaults from `config('mediaforge.text')`.
     *
     * Default option values (configurable via config/mediaforge.php):
     * - `font`   (string|null): absolute path to a font file — defaults to Montserrat bundled in the package
     * - `size`   (int):         font size in pixels — defaults to `48`
     * - `color`  (string):      CSS color string — defaults to `'rgba(255, 255, 255, .75)'`
     * - `align`  (string):      horizontal alignment (`left`, `center`, `right`) — defaults to `'center'`
     * - `valign` (string):      vertical alignment (`top`, `middle`, `bottom`) — defaults to `'middle'`
     * - `angle`  (int):         rotation angle in degrees — defaults to `0`
     * - `wrap`   (int|null):    max line width in pixels before text wraps; `null` = no wrapping — defaults to `null`
     *
     * @param array{font?: string, size?: int, color?: string, align?: string, valign?: string, angle?: int, wrap?: int|null} $options
     */
    public function text(string $text, array $options = []): static
    {
        $this->text = $text;
        $this->textOptions = $options;

        return $this;
    }

    /**
     * Add a watermark image overlay.
     * Any option not provided falls back to the defaults from `config('mediaforge.watermark')`.
     *
     * Default option values (configurable via config/mediaforge.php):
     * - `position` (string): placement (`top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`) — defaults to `'center'`
     * - `x`        (int):    horizontal offset in pixels from the position anchor — defaults to `0`
     * - `y`        (int):    vertical offset in pixels from the position anchor — defaults to `0`
     * - `opacity`  (int):    watermark opacity from 0 (transparent) to 100 (opaque) — defaults to `75`
     *
     * @param array{position?: string, x?: int, y?: int, opacity?: int} $options
     */
    public function watermark(string $watermarkPath, array $options = []): static
    {
        $this->watermark = $watermarkPath;
        $this->watermarkOptions = $options;

        return $this;
    }

    /**
     * Custom attributes to store alongside this format's entry in the database.
     * Use this for any format-specific data that doesn't map to a built-in option
     * (e.g. caption, focal point, custom labels).
     *
     * Example: `ImageFormat::make('thumb')->customAttributes(['caption' => 'Hero image'])`
     */
    public function customAttributes(array $attributes): static
    {
        $this->customAttributes = $attributes;

        return $this;
    }

    /**
     * Override the alt text for this format.
     * Defaults to the uploaded filename stem when not set.
     *
     * Example: `ImageFormat::make('default')->alt('Product cover')`
     */
    public function alt(string $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDisk(): ?string
    {
        return $this->disk;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getSuffix(): string
    {
        return $this->suffix;
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function getResizeType(): ?string
    {
        return $this->resizeType;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getQuality(): ?int
    {
        return $this->quality;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getTextOptions(): array
    {
        return $this->textOptions;
    }

    public function getWatermark(): ?string
    {
        return $this->watermark;
    }

    public function getWatermarkOptions(): array
    {
        return $this->watermarkOptions;
    }

    public function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    /**
     * Returns true if any image transformation is configured.
     */
    public function hasTransforms(): bool
    {
        return $this->resizeType !== null ||
            $this->quality !== null ||
            $this->extension !== null ||
            $this->text !== null ||
            $this->watermark !== null;
    }

    /**
     * Serialize the format config (used internally for regeneration).
     * Only non-default/non-null values are included.
     */
    public function toConfigArray(): array
    {
        $config = [];

        if ($this->extension !== null) {
            $config['extension'] = $this->extension;
        }
        if ($this->filename !== null) {
            $config['filename'] = $this->filename;
        }
        if ($this->resizeType !== null) {
            $config['resizeType'] = $this->resizeType;
            $config['width'] = $this->width;
            $config['height'] = $this->height;
        }
        if ($this->quality !== null) {
            $config['quality'] = $this->quality;
        }
        if ($this->suffix !== '') {
            $config['suffix'] = $this->suffix;
        }
        if ($this->text !== null) {
            $config['text'] = $this->text;
            $config['textOptions'] = $this->textOptions;
        }
        if ($this->watermark !== null) {
            $config['watermark'] = $this->watermark;
            $config['watermarkOptions'] = $this->watermarkOptions;
        }
        if (!empty($this->customAttributes)) {
            $config['customAttributes'] = $this->customAttributes;
        }
        if ($this->alt !== null) {
            $config['alt'] = $this->alt;
        }

        return $config;
    }

    /**
     * Rebuild an ImageFormat from a config array.
     */
    public static function fromConfigArray(string $name, array $config): static
    {
        $format = static::make($name);

        if (isset($config['disk'])) {
            $format->disk = $config['disk'];
        }
        if (isset($config['path'])) {
            $format->path = $config['path'];
        }
        if (isset($config['suffix'])) {
            $format->suffix = $config['suffix'];
        }
        if (isset($config['extension'])) {
            $format->extension = $config['extension'];
        }
        if (isset($config['filename'])) {
            $format->filename = $config['filename'];
        }
        if (isset($config['quality'])) {
            $format->quality = $config['quality'];
        }
        if (isset($config['resizeType'])) {
            $format->resizeType = $config['resizeType'];
            $format->width = $config['width'] ?? null;
            $format->height = $config['height'] ?? null;
        }
        if (isset($config['text'])) {
            $format->text = $config['text'];
            $format->textOptions = $config['textOptions'] ?? [];
        }
        if (isset($config['watermark'])) {
            $format->watermark = $config['watermark'];
            $format->watermarkOptions = $config['watermarkOptions'] ?? [];
        }
        if (isset($config['customAttributes'])) {
            $format->customAttributes = $config['customAttributes'];
        }
        if (isset($config['alt'])) {
            $format->alt = $config['alt'];
        }

        return $format;
    }
}
