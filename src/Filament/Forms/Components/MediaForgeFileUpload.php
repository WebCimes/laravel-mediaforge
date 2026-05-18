<?php

namespace Webcimes\LaravelMediaforge\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\StateCasts\FileUploadStateCast;
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
        // Multiple: state is a list of items (arrays or JSON strings).
        // Single: state is a single item (a format-map array or a JSON string).
        $this->afterStateHydrated(static function (
            MediaForgeFileUpload $component,
            mixed $state,
        ): void {
            // Normalize the state into a list of items independently of `multiple()`:
            // - a list of JSON strings / format-maps stays as-is,
            // - a single JSON string or a single associative format-map is wrapped as one item.
            // This avoids iterating over the keys of a single format-map, which would
            // produce malformed entries (the inner variants, not the format-map).
            $items = match (true) {
                blank($state) => [],
                is_string($state) => [$state],
                is_array($state) && array_is_list($state) => $state,
                is_array($state) => [$state],
                default => [],
            };

            $normalized = [];

            foreach ($items as $item) {
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

        // Deletion is deferred to save time: the file JSON is queued in the session,
        // and actual disk removal happens in dehydrateStateUsing (on form save only).
        $this->deleteUploadedFileUsing(static function (MediaForgeFileUpload $component, string $file): void {
            $queueKey = 'mf_pdq_' . sha1($component->getStatePath() . '|' . ($component->getRecord()?->getKey() ?? ''));
            $queue    = session()->get($queueKey, []);
            if (!in_array($file, $queue, true)) {
                $queue[] = $file;
            }
            session()->put($queueKey, $queue);
        });

        // Internal → DB: decode JSON strings back to arrays.
        // Pending deletions (queued in session by deleteUploadedFileUsing above) are processed
        // here, ensuring files are only removed from disk when the form is actually saved.
        $this->dehydrateStateUsing(static function (MediaForgeFileUpload $component, mixed $state): ?array {
            $queueKey       = 'mf_pdq_' . sha1($component->getStatePath() . '|' . ($component->getRecord()?->getKey() ?? ''));
            $pendingDeletions = session()->pull($queueKey, []);

            // Normalize the state into a list of items independently of `multiple()`:
            // - a list of JSON strings / format-maps stays as-is,
            // - a single JSON string or a single associative format-map is wrapped as one item.
            // This prevents `array_values()` from stripping the 'default'/'thumb' keys of a
            // single format-map (which would silently corrupt the stored structure).
            $items = match (true) {
                blank($state) => [],
                is_string($state) => [$state],
                is_array($state) && array_is_list($state) => array_values($state),
                is_array($state) => [$state],
                default => [$state],
            };

            // Decode current state items to format-map arrays.
            $remaining = array_values(array_filter(array_map(
                static fn (mixed $item): ?array => is_string($item)
                    ? json_decode($item, true)
                    : (is_array($item) ? $item : null),
                $items,
            )));

            // Delete queued files and ensure they are absent from the saved state.
            foreach ($pendingDeletions as $file) {
                $metadata = json_decode($file, true);
                if (!$metadata) {
                    continue;
                }
                app(MediaForge::class)->delete([$metadata]);

                $pendingEntry = $metadata['default'] ?? (is_array($metadata) ? reset($metadata) : null);
                $pendingPath  = is_array($pendingEntry) ? ($pendingEntry['path'] ?? null) : null;

                if ($pendingPath !== null) {
                    $remaining = array_values(array_filter(
                        $remaining,
                        static function (array $formatMap) use ($pendingPath): bool {
                            $entry = $formatMap['default'] ?? (is_array($formatMap) ? reset($formatMap) : null);

                            return !is_array($entry) || ($entry['path'] ?? null) !== $pendingPath;
                        },
                    ));
                }
            }

            if (empty($remaining)) {
                return null;
            }

            // Multiple: return the full list. Single: return the first format-map directly.
            return $component->isMultiple() ? $remaining : $remaining[0];
        });
    }

    /**
     * Replace Filament's default FileUploadStateCast with our own.
     *
     * The default cast's `get()` calls `Arr::first()` in single mode, which
     * unwraps a format-map (e.g. ['default' => [...]] → ['disk' => ...]) and
     * corrupts the structure. Our cast preserves the format-map wrapper.
     *
     * @return array<mixed>
     */
    public function getDefaultStateCasts(): array
    {
        $casts = array_filter(
            parent::getDefaultStateCasts(),
            static fn ($cast): bool => !($cast instanceof FileUploadStateCast),
        );

        $casts[] = app(MediaForgeStateCast::class, ['isMultiple' => $this->isMultiple()]);

        return array_values($casts);
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
