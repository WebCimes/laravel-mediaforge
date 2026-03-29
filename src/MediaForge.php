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
     * Srcset formats are kept as-is; their variants are processed inline in upload().
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
     * Process a single image format for an uploaded file and return the entry array.
     */
    private function processUploadFormat(
        \Illuminate\Http\UploadedFile $uploadedFile,
        ImageFormat $format,
        string $formatName,
        string $diskName,
        string $baseName,
        string $path,
        string $originalExtension,
        string $originalFilename,
    ): array {
        $imageDiskName = $format->getDisk() ?? $diskName;
        $imageDisk = $this->filesystem->disk($imageDiskName);

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

        $image = $this->imageManager->decode($uploadedFile);
        $entry = [
            'disk' => $imageDiskName,
            'path' => $imageFilePath,
        ];

        if (!$format->hasTransforms()) {
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

        return $entry;
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
                    $font->filepath($textOptions['font']);
                }
                $font->size($textOptions['size']);
                $font->color($textOptions['color']);
                $font->align($textOptions['align'], $textOptions['valign']);
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
                    'opacity' => 0.75,
                ]),
                $format->getWatermarkOptions(),
            );

            // V4 signature: insert($image, $x, $y, $alignment, $transparency)
            // transparency is 0.0–1.0, same as our opacity range.
            $image->insert(
                $format->getWatermark(),
                $watermarkOptions['x'],
                $watermarkOptions['y'],
                $watermarkOptions['position'],
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
     * Srcset formats produce a nested 'srcset' array inside the format entry:
     * ```php
     * [
     *     'default' => ['disk' => 'public', 'path' => '...default.webp', 'width' => 1920, 'height' => 1080, 'alt' => 'img'],
     *     'hero'    => [
     *         'disk' => 'public', 'path' => '...hero.webp', 'width' => 1920, 'height' => 1080, 'alt' => 'img',
     *         'srcset' => [
     *             ['disk' => 'public', 'path' => '...hero_1080w.webp', 'width' => 1080, 'height' => 607, 'alt' => 'img'],
     *             ['disk' => 'public', 'path' => '...hero_720w.webp',  'width' => 720,  'height' => 405, 'alt' => 'img'],
     *         ],
     *     ],
     * ]
     * ```
     *
     * @param  \Illuminate\Http\UploadedFile|null         $uploadedFile
     * @param  string                                     $diskName        Target storage disk.
     * @param  string                                     $path            Base directory inside the disk.
     * @param  ImageFormat|array<ImageFormat>|null        $imageFormats    Format definitions. Pass null (or omit) for non-image files.
     * @param  string|null                                $customBaseName  Folder/file name prefix: null = auto slug, '' = ULID only, 'name' = custom prefix.
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

            // Read source dimensions once if any format uses skipLarger
            $sourceWidth = null;
            foreach ($formats as $format) {
                if ($format->isSrcsetSkipLarger()) {
                    $sourceWidth = $this->imageManager->decode($uploadedFile)->width();
                    break;
                }
            }

            foreach ($formats as $formatName => $format) {
                if ($format->isSrcset()) {
                    // Process the base format (original dimensions / base transforms)
                    $baseFormat = $format->toBaseFormat();
                    $entry = $this->processUploadFormat(
                        $uploadedFile,
                        $baseFormat,
                        $formatName,
                        $diskName,
                        $baseName,
                        $path,
                        $originalExtension,
                        $originalFilename,
                    );

                    // Process each srcset width variant and nest under 'srcset'
                    $srcsetEntries = [];
                    foreach ($format->getSrcsetWidths() as $width) {
                        if ($format->isSrcsetSkipLarger() && $sourceWidth !== null && $width > $sourceWidth) {
                            continue;
                        }
                        $variantFormat = $format->expandForSrcset($width);
                        $srcsetEntries[] = $this->processUploadFormat(
                            $uploadedFile,
                            $variantFormat,
                            $variantFormat->getName(),
                            $diskName,
                            $baseName,
                            $path,
                            $originalExtension,
                            $originalFilename,
                        );
                    }

                    if (!empty($srcsetEntries)) {
                        $entry['srcset'] = $srcsetEntries;
                    }

                    $result[$formatName] = $entry;
                } else {
                    $result[$formatName] = $this->processUploadFormat(
                        $uploadedFile,
                        $format,
                        $formatName,
                        $diskName,
                        $baseName,
                        $path,
                        $originalExtension,
                        $originalFilename,
                    );
                }
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
                if (isset($existingEntry['disk'], $existingEntry['path'])) {
                    $this->filesystem->disk($existingEntry['disk'])->delete($existingEntry['path']);
                }
                // Also delete stored srcset variants
                foreach ($existingEntry['srcset'] ?? [] as $srcsetEntry) {
                    if (isset($srcsetEntry['disk'], $srcsetEntry['path'])) {
                        $this->filesystem->disk($srcsetEntry['disk'])->delete($srcsetEntry['path']);
                    }
                }
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
            $image = $this->imageManager->decode(
                $this->filesystem->disk($defaultDisk)->path($defaultPath),
            );

            // Delete the old base file for this format if the path has changed
            if (isset($result[$formatName]['path']) && $result[$formatName]['path'] !== $imageFilePath) {
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

            // Process srcset variants when defined
            if ($format->isSrcset()) {
                // Delete old srcset variant files
                foreach ($result[$formatName]['srcset'] ?? [] as $oldSrcsetEntry) {
                    if (isset($oldSrcsetEntry['disk'], $oldSrcsetEntry['path'])) {
                        $this->filesystem->disk($oldSrcsetEntry['disk'])->delete($oldSrcsetEntry['path']);
                    }
                }

                $srcsetEntries = [];
                foreach ($format->getSrcsetWidths() as $width) {
                    $variantFormat = $format->expandForSrcset($width);
                    $variantDiskName = $variantFormat->getDisk() ?? $defaultDisk;
                    $variantDisk = $this->filesystem->disk($variantDiskName);
                    $variantExtension = $variantFormat->getExtension() ?? pathinfo($defaultPath, PATHINFO_EXTENSION);
                    $variantDirectory = rtrim($variantFormat->getPath() ?? $baseDirectory . '/' . $baseName, '/');
                    $variantFileName =
                        ($variantFormat->getFilename() ?? $variantFormat->getName()) .
                        $variantFormat->getSuffix() .
                        '.' .
                        $variantExtension;
                    $variantFilePath = $variantDirectory . '/' . $variantFileName;

                    $variantImage = $this->imageManager->decode(
                        $this->filesystem->disk($defaultDisk)->path($defaultPath),
                    );

                    if (!$variantDisk->exists($variantDirectory)) {
                        $variantDisk->makeDirectory($variantDirectory);
                    }

                    $variantDimensions = $this->applyAndSaveFormat($variantImage, $variantFormat, $variantDiskName, $variantFilePath);
                    $srcsetEntries[] = [
                        'disk' => $variantDiskName,
                        'path' => $variantFilePath,
                        'width' => $variantDimensions['width'],
                        'height' => $variantDimensions['height'],
                        'alt' => $variantFormat->getAlt() ?? $defaultEntry['alt'] ?? '',
                    ];
                }

                if (!empty($srcsetEntries)) {
                    $entry['srcset'] = $srcsetEntries;
                }
            }

            $result[$formatName] = $entry;
        }

        return $result;
    }

    /**
     * Delete an image folder if it was created by this package and is now empty.
     * Image folders always end with a ULID (26 lowercase alphanumeric chars) — e.g.
     * 'hero_01jq8z...' or just '01jq8z...'. This is the reliable signal that the
     * directory was generated here and can be cleaned up, regardless of base path depth.
     */
    private function deleteImageFolderIfEmpty(string $diskName, string $dir): void
    {
        if (!preg_match('/(?:^|_)[0-9a-z]{26}$/', basename($dir))) {
            return;
        }
        $disk = $this->filesystem->disk($diskName);
        if ($disk->exists($dir) && count($disk->files($dir)) === 0) {
            $disk->deleteDirectory($dir);
        }
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
                    // Track directories per disk to clean up empty image folders after deletion
                    $directories = [];

                    foreach ($file as $fileDetail) {
                        if (isset($fileDetail['disk'], $fileDetail['path'])) {
                            $this->filesystem
                                ->disk($fileDetail['disk'])
                                ->delete($fileDetail['path']);

                            $dir = dirname($fileDetail['path']);
                            if ($dir !== '.') {
                                $directories[$fileDetail['disk']][$dir] = true;
                            }

                            // Also delete srcset variants
                            foreach ($fileDetail['srcset'] ?? [] as $srcsetEntry) {
                                if (isset($srcsetEntry['disk'], $srcsetEntry['path'])) {
                                    $this->filesystem->disk($srcsetEntry['disk'])->delete($srcsetEntry['path']);
                                    $srcsetDir = dirname($srcsetEntry['path']);
                                    if ($srcsetDir !== '.') {
                                        $directories[$srcsetEntry['disk']][$srcsetDir] = true;
                                    }
                                }
                            }
                        }
                    }

                    foreach ($directories as $dirDiskName => $dirs) {
                        foreach (array_keys($dirs) as $dir) {
                            $this->deleteImageFolderIfEmpty($dirDiskName, $dir);
                        }
                    }
                } elseif (is_string($file)) {
                    $this->filesystem->disk($diskName)->delete($file);
                    $this->deleteImageFolderIfEmpty($diskName, dirname($file));
                }
            }
        } elseif (is_string($filesToDelete)) {
            $this->filesystem->disk($diskName)->delete($filesToDelete);
            $this->deleteImageFolderIfEmpty($diskName, dirname($filesToDelete));
        }
    }

    /**
     * Handle files: upload new files, delete removed files, and apply a global order.
     *
     * Designed to be called with the raw payload of a file input (new uploads, deleted indexes,
     * ordering) in one shot. Existing files not referenced in $filesToDeleteIndex are preserved.
     *
     * @param  string                                           $diskName           Target disk.
     * @param  string                                           $path               Base path inside the disk.
     * @param  array<\Illuminate\Http\UploadedFile>|null        $uploadedFiles      New files to upload.
     * @param  array<int>|null                                  $filesToDeleteIndex Indexes into $existingFiles to delete.
     * @param  array<array{type: string, index: int}>|null      $globalOrder        Ordering directive (array order = final position).
     * @param  array|null                                       $existingFiles      Current DB file list (for deletion + reorder).
     * @param  ImageFormat|array<ImageFormat>|null              $imageFormats       Image processing formats.
     * @param  string|null                                      $customBaseName     Folder/file prefix: null = auto slug, '' = ULID only.
     * @return array|null                                       Updated file list, or null if empty.
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
            $referencedNewIndexes = [];
            $orderedFiles = [];
            $deletedIndexes = array_map('intval', $filesToDeleteIndex ?? []);
            foreach ($globalOrder as $orderItem) {
                $idx = (int) $orderItem['index'];
                if ($orderItem['type'] === 'existing') {
                    // Use original existingFiles indexes because $files is re-indexed after deletions
                    if (isset(($existingFiles ?? [])[$idx]) && !in_array($idx, $deletedIndexes, true)) {
                        $orderedFiles[] = $existingFiles[$idx];
                    }
                } elseif ($orderItem['type'] === 'new' && isset($uploadedFilesArray[$idx])) {
                    $orderedFiles[] = $uploadedFilesArray[$idx];
                    $referencedNewIndexes[] = $idx;
                }
            }

            // Delete uploaded files not referenced in globalOrder to prevent orphaned files on disk
            foreach ($uploadedFilesArray as $index => $uploadedResult) {
                if ($uploadedResult !== null && !in_array($index, $referencedNewIndexes, true)) {
                    $this->delete([$uploadedResult]);
                }
            }

            return !empty($orderedFiles) ? $orderedFiles : null;
        }

        $filteredUploads = array_values(array_filter($uploadedFilesArray));
        return !empty($files) || !empty($filteredUploads)
            ? array_merge($files, $filteredUploads)
            : null;
    }
}
