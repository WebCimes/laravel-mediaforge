<?php

namespace Webcimes\LaravelMediaforge\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Webcimes\LaravelMediaforge\ImageFormat;
use Webcimes\LaravelMediaforge\MediaForge;

/**
 * Filament FileUpload that delegates storage to MediaForge.
 *
 * Each upload produces a JSON-encoded format map stored in the DB:
 * [
 *   'default' => ['disk' => 'public', 'path' => 'uploads/img_xxx/default.webp', 'width' => 1920, ...],
 *   'thumb'   => ['disk' => 'public', 'path' => 'uploads/img_xxx/thumb.webp',   'width' => 400,  ...],
 * ]
 */
class MediaForgeFileUpload extends FileUpload
{
    /** @var array<ImageFormat>|null */
    protected ?array $imageFormats = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Our internal state uses JSON strings (not plain paths), so skip disk existence checks.
        $this->fetchFileInformation(false);

        // DB → internal: decode each stored array to a JSON string.
        $this->afterStateHydrated(static function (
            MediaForgeFileUpload $component,
            mixed $state,
        ): void {
            if (blank($state) || !is_array($state)) {
                $component->rawState([]);

                return;
            }

            $normalized = [];

            foreach ($state as $item) {
                if (is_array($item)) {
                    $normalized[] = json_encode($item);
                } elseif (is_string($item) && filled($item)) {
                    $normalized[] = $item;
                }
            }

            $component->rawState(array_values(array_filter($normalized)));
        });

        // Upload: delegate to MediaForge, return JSON-encoded result.
        $this->saveUploadedFileUsing(static function (
            MediaForgeFileUpload $component,
            TemporaryUploadedFile $file,
        ): ?string {
            $result = app(MediaForge::class)->upload(
                $file,
                $component->getDiskName(),
                $component->getDirectory() ?? '',
                $component->getImageFormats(),
            );

            return $result ? json_encode($result) : null;
        });

        // Preview: decode JSON and return FilePond-compatible info using the 'default' format.
        $this->getUploadedFileUsing(static function (string $file): ?array {
            $metadata = json_decode($file, true);

            if (!$metadata) {
                return null;
            }

            $defaultFormat = $metadata['default'] ?? reset($metadata);

            if (!is_array($defaultFormat) || !isset($defaultFormat['disk'], $defaultFormat['path'])) {
                return null;
            }

            /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
                $storageDisk = Storage::disk($defaultFormat['disk']);

                return [
                'name' => basename($defaultFormat['path']),
                'size' => 0,
                'type' => 'image/jpeg',
                'url' => $storageDisk->url($defaultFormat['path']),
            ];
        });

        // Deletion is intentionally deferred — actual file removal happens in dehydrateStateUsing
        // only when the form is saved, so clicking the X button without saving leaves files intact.
        $this->deleteUploadedFileUsing(static function (): void {});

        // Internal → DB: decode JSON strings back to arrays.
        // Deferred deletions are performed here by comparing the new state against the
        // current DB value (read via getRawOriginal), so files are only removed on save.
        // This approach is reliable across Livewire requests, unlike in-memory snapshots.
        $this->dehydrateStateUsing(static function (MediaForgeFileUpload $component, mixed $state): ?array {
            $items = blank($state) ? [] : (is_array($state) ? array_values($state) : [$state]);

            // Decode current state items to format-map arrays.
            $remaining = array_values(array_filter(array_map(
                static fn (mixed $item): ?array => is_string($item)
                    ? json_decode($item, true)
                    : (is_array($item) ? $item : null),
                $items,
            )));

            // Build a list of default-format paths still present in the new state.
            $remainingPaths = [];
            foreach ($remaining as $formatMap) {
                $entry = $formatMap['default'] ?? (is_array($formatMap) ? reset($formatMap) : null);
                if (is_array($entry) && isset($entry['path'])) {
                    $remainingPaths[] = $entry['path'];
                }
            }

            // Read the pre-save DB value and delete any format map no longer referenced.
            try {
                $record = $component->getRecord();
                if ($record !== null) {
                    $dbRaw = $record->getRawOriginal($component->getName());
                    if (filled($dbRaw)) {
                        $dbItems = is_string($dbRaw) ? json_decode($dbRaw, true) : $dbRaw;
                        if (is_array($dbItems)) {
                            foreach ($dbItems as $dbItem) {
                                if (!is_array($dbItem)) {
                                    continue;
                                }
                                $dbEntry = $dbItem['default'] ?? (is_array($dbItem) ? reset($dbItem) : null);
                                $dbPath  = is_array($dbEntry) ? ($dbEntry['path'] ?? null) : null;
                                if ($dbPath !== null && !in_array($dbPath, $remainingPaths, true)) {
                                    app(MediaForge::class)->delete([$dbItem]);
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Silently skip if the record or attribute is unavailable (e.g. CreateRecord).
            }

            return !empty($remaining) ? $remaining : null;
        });
    }

    /**
     * @param  array<ImageFormat>|Closure  $formats
     */
    public function imageFormats(array|Closure $formats): static
    {
        $this->imageFormats = $formats instanceof Closure ? $formats() : $formats;

        return $this;
    }

    /**
     * @return array<ImageFormat>|null
     */
    public function getImageFormats(): ?array
    {
        return $this->imageFormats;
    }
}
