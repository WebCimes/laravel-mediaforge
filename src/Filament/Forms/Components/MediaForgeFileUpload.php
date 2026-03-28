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

    /**
     * JSON strings of the files present in DB when the form was hydrated.
     * Used to defer deletion until the form is actually saved.
     *
     * @var string[]
     */
    protected array $originalFiles = [];

    public function setOriginalFiles(array $files): void
    {
        $this->originalFiles = $files;
    }

    public function getOriginalFiles(): array
    {
        return $this->originalFiles;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Our internal state uses JSON strings (not plain paths), so skip disk existence checks.
        $this->fetchFileInformation(false);

        // DB → internal: decode each stored array to a JSON string.
        // Also snapshot the original files so deletion can be deferred to save time.
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

            $normalized = array_values(array_filter($normalized));

            // Store snapshot so dehydrateStateUsing can detect removals.
            $component->setOriginalFiles($normalized);

            $component->rawState($normalized);
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
        // This is also where deferred deletions are performed:
        // any file present in the original hydrated state but absent from the current
        // state is deleted here — i.e. only when the form is actually saved.
        $this->dehydrateStateUsing(static function (MediaForgeFileUpload $component, mixed $state): ?array {
            $items = blank($state) ? [] : (is_array($state) ? array_values($state) : [$state]);

            // Normalise current state items to JSON strings for comparison.
            $remaining = array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_string($item)
                    ? $item
                    : (is_array($item) ? json_encode($item) : null),
                $items,
            )));

            // Delete files that existed before but are no longer in the current state.
            foreach ($component->getOriginalFiles() as $originalFile) {
                if (!in_array($originalFile, $remaining, true)) {
                    $metadata = json_decode($originalFile, true);
                    if ($metadata) {
                        app(MediaForge::class)->delete([$metadata]);
                    }
                }
            }

            if (blank($state)) {
                return null;
            }

            $decoded = array_values(array_filter(array_map(
                static fn (mixed $item): mixed => is_string($item)
                    ? json_decode($item, true)
                    : (is_array($item) ? $item : null),
                $items,
            )));

            return !empty($decoded) ? $decoded : null;
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
