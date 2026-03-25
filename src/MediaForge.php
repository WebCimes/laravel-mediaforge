<?php

namespace Webcimes\LaravelMediaforge;

use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\Interfaces\ImageInterface;

class MediaForge
{
    public function __construct(public Factory $filesystem, public ImageManager $imageManager) {}

    /**
     * Normalize an ImageFormat|ImageFormat[] input into a keyed array.
     * Auto-injects a plain 'default' format at the front if none is provided.
     *
     * @param  ImageFormat|array<ImageFormat>|null  $imageFormats
     * @return array<string, ImageFormat>|null  null when $imageFormats itself is null
     */
    private function normalizeFormats(ImageFormat|array|null $imageFormats): ?array
    {
        if ($imageFormats === null) {
            return null;
        }

        $formats = [];

        foreach (is_array($imageFormats) ? $imageFormats : [$imageFormats] as $format) {
            $formats[$format->getName()] = $format;
        }

        // Auto-inject a 'default' (no transforms = original copy) if not explicitly defined
        if (!isset($formats['default'])) {
            $formats = ['default' => ImageFormat::make('default')] + $formats;
        }

        return $formats;
    }

    /**
     * Apply all transforms defined on an ImageFormat and save the result to disk.
     * Returns the final ['width' => int, 'height' => int].
     *
     * Note: only called when hasTransforms() is true.
     * Intervention Image transforms modify the image object in place (mutating). If the same
     * object were reused across multiple formats, each format would start from an already-modified
     * state (e.g. already resized). upload() therefore reads the file fresh for each loop iteration.
     */
    private function applyAndSaveFormat(
        ImageInterface $image,
        ImageFormat $format,
        string $diskName,
        string $filePath,
    ): array {
        $disk = $this->filesystem->disk($diskName);

        // Resize — delegates to the Intervention method matching the resizeType (e.g. scaleDown, cover, …)
        if ($format->getResizeType()) {
            /** @var ImageInterface */
            $image = $image->{$format->getResizeType()}($format->getWidth(), $format->getHeight());
        }

        // Text overlay — unspecified options fall back to the defaults from config('mediaforge.text')
        if ($format->getText()) {
            $textOptions = array_merge(
                config('mediaforge.text', [
                    'font' => null,
                    'size' => 48,
                    'color' => 'rgba(255, 255, 255, .75)',
                    'align' => 'center',
                    'valign' => 'middle',
                    'angle' => 0,
                    'wrap' => null,
                ]),
                $format->getTextOptions(),
            );

            $image->text($format->getText(), $image->width() / 2, $image->height() / 2, function (
                $font,
            ) use ($textOptions) {
                // Only set a custom font if one is configured; otherwise Intervention uses its built-in font
                if (!empty($textOptions['font'])) {
                    $font->filename($textOptions['font']);
                }
                $font->size($textOptions['size']);
                $font->color($textOptions['color']);
                $font->align($textOptions['align']);
                $font->valign($textOptions['valign']);
                $font->angle($textOptions['angle']);
                if (!empty($textOptions['wrap'])) {
                    $font->wrap($textOptions['wrap']);
                }
            });
        }

        // Watermark — unspecified options fall back to the defaults from config('mediaforge.watermark')
        if ($format->getWatermark()) {
            $watermarkOptions = array_merge(
                config('mediaforge.watermark', [
                    'position' => 'center',
                    'x' => 0,
                    'y' => 0,
                    'opacity' => 75,
                ]),
                $format->getWatermarkOptions(),
            );

            $image->place(
                $format->getWatermark(),
                $watermarkOptions['position'],
                $watermarkOptions['x'],
                $watermarkOptions['y'],
                $watermarkOptions['opacity'],
            );
        }

        // Save — use disk->path() because Intervention needs a real filesystem path (not a stream)
        if ($format->getQuality()) {
            $image->save($disk->path($filePath), quality: $format->getQuality());
        } else {
            $image->save($disk->path($filePath));
        }

        return ['width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * Upload a file or image and return an associative array of format entries.
     *
     * Each entry contains: disk, path, width, height, alt.
     * If the format defines customAttributes(), they are stored under the 'customAttributes' key.
     * baseName and baseDirectory are not stored — they are derived from the default path when needed.
     *
     * For images with $imageFormats:
     * - A 'default' format is auto-injected if not explicitly provided (saves original).
     * - All formats of the same upload share one folder: $path/{baseName}/
     * - Each format file is named after the format by default (e.g. default.webp, thumb.webp).
     *   Use `ImageFormat::make('thumb')->filename('hero-thumb')` to override the filename.
     *
     * The $customBaseName controls the folder name (images) or file name prefix (other files):
     * - null   → auto-generated from the uploaded filename slug + ULID (e.g. 'hero_01jq8z...')
     * - ''     → ULID only, no slug prefix (e.g. '01jq8z...')
     * - 'hero' → custom prefix + ULID for uniqueness (e.g. 'hero_01jq8z...')
     *
     * Example result:
     * ```php
     * [
     *     'default' => ['disk' => 'public', 'path' => 'uploads/img_xxx/default.webp', 'width' => 1920, 'height' => 1080, 'alt' => 'img'],
     *     'thumb'   => ['disk' => 'public', 'path' => 'uploads/img_xxx/thumb.webp',   'width' => 400,  'height' => 300,  'alt' => 'img', 'customAttributes' => [...]],
     * ]
     * ```
     * `customAttributes` is only present when defined on the format via `->customAttributes([...])` or `->alt(...)`.
     *
     * @param  ImageFormat|array<ImageFormat>|null  $imageFormats
     * @param  string|null  $customBaseName  Folder/file name prefix: null = auto slug, '' = ULID only, 'name' = custom prefix
     */
    public function upload(
        \Illuminate\Http\UploadedFile|null $uploadedFile,
        string $diskName = 'public',
        string $path = '',
        ImageFormat|array|null $imageFormats = null,
        ?string $customBaseName = null,
    ): array|null {
        $disk = $this->filesystem->disk($diskName);

        if (!$uploadedFile || $uploadedFile->getError()) {
            return null;
        }

        if (!$disk->exists($path)) {
            $disk->makeDirectory($path);
        }

        $formats = $this->normalizeFormats($imageFormats);

        if (Str::startsWith($uploadedFile->getMimeType(), 'image/') && $formats !== null) {
            // Determine the base name prefix (= folder name for this image):
            // null → slug from original filename | '' → ULID only | 'name' → custom prefix + ULID
            $prefix =
                $customBaseName ??
                Str::limit(
                    Str::slug(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME)),
                    50,
                    '',
                );
            $baseName = ($prefix !== '' ? $prefix . '_' : '') . Str::lower(Str::ulid());
            $originalExtension = pathinfo(
                $uploadedFile->getClientOriginalName(),
                PATHINFO_EXTENSION,
            );
            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

            $result = [];

            foreach ($formats as $formatName => $format) {
                $imageDiskName = $format->getDisk() ?? $diskName;
                $imageDisk = $this->filesystem->disk($imageDiskName);

                // One folder per image: $path/$baseName — all formats live inside it
                $imageExtension = $format->getExtension() ?? $originalExtension;
                $imageDirectory = rtrim($format->getPath() ?? $path . '/' . $baseName, '/');
                $imageFileName =
                    ($format->getFilename() ?? $formatName) .
                    $format->getSuffix() .
                    '.' .
                    $imageExtension;
                $imageFilePath = $imageDirectory . '/' . $imageFileName;

                if (!$imageDisk->exists($imageDirectory)) {
                    $imageDisk->makeDirectory($imageDirectory);
                }

                // Read image fresh for each format (transforms are mutating)
                $image = $this->imageManager->read($uploadedFile);

                $entry = [
                    'disk' => $imageDiskName,
                    'path' => $imageFilePath,
                ];

                if (!$format->hasTransforms()) {
                    // No transforms: copy original bytes to avoid re-encoding
                    $imageDisk->put(
                        $imageFilePath,
                        file_get_contents($uploadedFile->getRealPath()),
                    );
                    $entry += [
                        'width' => $image->width(),
                        'height' => $image->height(),
                        'alt' => $format->getAlt() ?? $originalFilename,
                    ];
                } else {
                    $dimensions = $this->applyAndSaveFormat(
                        $image,
                        $format,
                        $imageDiskName,
                        $imageFilePath,
                    );
                    $entry += [
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                        'alt' => $format->getAlt() ?? $originalFilename,
                    ];
                }

                $customAttributes = $format->getCustomAttributes();
                if (!empty($customAttributes)) {
                    $entry['customAttributes'] = $customAttributes;
                }

                $result[$formatName] = $entry;
            }

            return $result;
        }

        // Non-image file (or image without formats): save as-is
        // Same prefix logic as for images: null → slug, '' → ULID only, 'name' → custom prefix + ULID
        $prefix =
            $customBaseName ??
            Str::limit(
                Str::slug(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME)),
                50,
                '',
            );
        $fileName =
            ($prefix !== '' ? $prefix . '_' : '') .
            Str::lower(Str::ulid()) .
            '.' .
            $uploadedFile->getClientOriginalExtension();

        $filePath = ($path ? rtrim($path, '/') . '/' : '') . $fileName;
        $disk->put($filePath, file_get_contents($uploadedFile->getRealPath()));

        return ['default' => ['disk' => $diskName, 'path' => $filePath]];
    }

    /**
     * Regenerate image formats from the stored entry.
     * Reads the default file from disk and re-processes the given formats.
     *
     * - 'default' can be included in $formats to re-process the default image itself.
     * - Any format key present in $storedEntry but absent from $formats is deleted from disk
     *   and removed from the returned entry ('default' is always kept).
     * - customAttributes defined on each ImageFormat are stored in the result entry.
     *
     * @param  array<string, array>            $storedEntry  Single image entry from the database.
     * @param  ImageFormat|array<ImageFormat>  $formats      Formats to regenerate.
     * @return array<string, array>            Updated entry.
     */
    public function regenerate(array $storedEntry, ImageFormat|array $formats): array
    {
        if (!isset($storedEntry['default'])) {
            throw new \InvalidArgumentException(
                "The 'default' format is required in storedEntry for regeneration.",
            );
        }

        $defaultEntry = $storedEntry['default'];
        $defaultDisk = $defaultEntry['disk'];
        $defaultPath = $defaultEntry['path'];

        // Derive baseName and baseDirectory from the default path — no need to store them.
        $baseName = basename(dirname($defaultPath));
        $baseDirectory = dirname(dirname($defaultPath));

        $formatsArray = is_array($formats) ? $formats : [$formats];
        $newFormatNames = array_map(fn($f) => $f->getName(), $formatsArray);

        // Delete formats that are no longer in the list (never removes 'default')
        $result = ['default' => $defaultEntry];
        foreach ($storedEntry as $existingName => $existingEntry) {
            if ($existingName === 'default') {
                continue;
            }
            if (!in_array($existingName, $newFormatNames, true)) {
                $this->filesystem->disk($existingEntry['disk'])->delete($existingEntry['path']);
            } else {
                $result[$existingName] = $existingEntry;
            }
        }

        foreach ($formatsArray as $format) {
            $formatName = $format->getName();
            $imageDiskName = $format->getDisk() ?? $defaultDisk;
            $imageDisk = $this->filesystem->disk($imageDiskName);

            $imageExtension = $format->getExtension() ?? pathinfo($defaultPath, PATHINFO_EXTENSION);
            $imageDirectory = rtrim($format->getPath() ?? $baseDirectory . '/' . $baseName, '/');
            $imageFileName =
                ($format->getFilename() ?? $formatName) .
                $format->getSuffix() .
                '.' .
                $imageExtension;
            $imageFilePath = $imageDirectory . '/' . $imageFileName;

            // Read source first — this matters when regenerating 'default' itself,
            // as the source and destination may be the same file.
            $image = $this->imageManager->read(
                $this->filesystem->disk($defaultDisk)->path($defaultPath),
            );

            // Delete the old file for this format if the path has changed
            if (isset($result[$formatName]) && $result[$formatName]['path'] !== $imageFilePath) {
                $this->filesystem
                    ->disk($result[$formatName]['disk'])
                    ->delete($result[$formatName]['path']);
            }

            if (!$imageDisk->exists($imageDirectory)) {
                $imageDisk->makeDirectory($imageDirectory);
            }

            if (!$format->hasTransforms()) {
                $imageDisk->put(
                    $imageFilePath,
                    $this->filesystem->disk($defaultDisk)->get($defaultPath),
                );
                $entry = [
                    'disk' => $imageDiskName,
                    'path' => $imageFilePath,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'alt' => $format->getAlt() ?? $defaultEntry['alt'] ?? '',
                ];
            } else {
                $dimensions = $this->applyAndSaveFormat(
                    $image,
                    $format,
                    $imageDiskName,
                    $imageFilePath,
                );
                $entry = [
                    'disk' => $imageDiskName,
                    'path' => $imageFilePath,
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'alt' => $format->getAlt() ?? $defaultEntry['alt'] ?? '',
                ];
            }

            $customAttributes = $format->getCustomAttributes();
            if (!empty($customAttributes)) {
                $entry['customAttributes'] = $customAttributes;
            }

            $result[$formatName] = $entry;
        }

        return $result;
    }

    /**
     * Delete file(s) or image(s).
     *
     * @param  array|string|null  $filesToDelete
     *   - string: single file path
     *   - array of strings: multiple file paths
     *   - associative array with 'disk' and 'path' keys (format entry structure)
     * @param  string  $diskName  Only used when $filesToDelete is a plain string/string[].
     */
    public function delete(array|string|null $filesToDelete, string $diskName = 'public'): void
    {
        if (!$filesToDelete) {
            return;
        }

        if (is_array($filesToDelete)) {
            foreach ($filesToDelete as $file) {
                if (is_array($file)) {
                    foreach ($file as $fileDetail) {
                        if (isset($fileDetail['disk'], $fileDetail['path'])) {
                            $this->filesystem
                                ->disk($fileDetail['disk'])
                                ->delete($fileDetail['path']);
                        }
                    }
                } elseif (is_string($file)) {
                    $this->filesystem->disk($diskName)->delete($file);
                }
            }
        } elseif (is_string($filesToDelete)) {
            $this->filesystem->disk($diskName)->delete($filesToDelete);
        }
    }

    /**
     * Handle files: upload new files, delete removed files, and apply a global order.
     *
     * @param  string                          $diskName          Target disk.
     * @param  string                          $path              Base path inside the disk.
     * @param  array<\Illuminate\Http\UploadedFile>|null  $uploadedFiles     New files to upload.
     * @param  array<int>|null                 $filesToDeleteIndex Indexes into $existingFiles to delete.
     * @param  array<array{type: string, index: int, globalPosition: int}>|null $globalOrder  Ordering directive.
     * @param  array|null                      $existingFiles     Current DB file list (for deletion + reorder).
     * @param  ImageFormat|array<ImageFormat>|null $imageFormats  Image processing formats.
     * @param  string|null                     $customBaseName    Folder/file prefix: null = auto slug, '' = ULID only.
     * @return array|null                      Updated file list, or null if empty.
     */
    public function handleFiles(
        string $diskName,
        string $path,
        array|null $uploadedFiles,
        ?array $filesToDeleteIndex,
        ?array $globalOrder = null,
        ?array $existingFiles = null,
        ImageFormat|array|null $imageFormats = null,
        ?string $customBaseName = null,
    ): array|null {
        $files = $existingFiles ?? [];
        $uploadedFilesArray = [];

        if ($filesToDeleteIndex && $existingFiles) {
            $filesToDelete = array_map(function ($index) use ($existingFiles) {
                return $existingFiles[$index];
            }, $filesToDeleteIndex);

            $this->delete($filesToDelete);

            $files = array_diff_key($files, array_flip($filesToDeleteIndex));
            $files = array_values($files);
        }

        if ($uploadedFiles) {
            foreach ($uploadedFiles as $uploadedFile) {
                $uploadedFilesArray[] = $this->upload(
                    $uploadedFile,
                    $diskName,
                    $path,
                    $imageFormats,
                    $customBaseName,
                );
            }
        }

        if ($globalOrder) {
            usort($globalOrder, function ($a, $b) {
                return ($a['globalPosition'] ?? 0) - ($b['globalPosition'] ?? 0);
            });

            $orderedFiles = [];
            foreach ($globalOrder as $orderItem) {
                if ($orderItem['type'] === 'existing' && isset($files[$orderItem['index']])) {
                    $orderedFiles[] = $files[$orderItem['index']];
                } elseif (
                    $orderItem['type'] === 'new' &&
                    isset($uploadedFilesArray[$orderItem['index']])
                ) {
                    $orderedFiles[] = $uploadedFilesArray[$orderItem['index']];
                }
            }

            return !empty($orderedFiles) ? $orderedFiles : null;
        }

        return !empty($files) || !empty($uploadedFilesArray)
            ? array_merge($files, $uploadedFilesArray)
            : null;
    }
}
