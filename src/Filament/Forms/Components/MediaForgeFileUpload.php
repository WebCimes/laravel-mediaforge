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

            $items = blank($state) ? [] : (is_array($state) ? array_values($state) : [$state]);

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
