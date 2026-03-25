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
 *   'default' => ['disk' => 'public', 'path' => 'uploads/default/img_xxx.webp', 'width' => 1920, ...],
 *   'thumb'   => ['disk' => 'public', 'path' => 'uploads/thumb/img_xxx.jpg',    'width' => 400,  ...],
 * ]
 */
class MediaForgeUpload extends FileUpload
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
            MediaForgeUpload $component,
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
            MediaForgeUpload $component,
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

            return [
                'name' => basename($defaultFormat['path']),
                'size' => 0,
                'type' => 'image/jpeg',
                'url' => Storage::disk($defaultFormat['disk'])->url($defaultFormat['path']),
            ];
        });

        // Delete: decode JSON and delete all format files via MediaForge.
        $this->deleteUploadedFileUsing(static function (string $file): void {
            $metadata = json_decode($file, true);

            if (!$metadata) {
                return;
            }

            app(MediaForge::class)->delete([$metadata]);
        });

        // Internal → DB: decode JSON strings back to arrays.
        $this->dehydrateStateUsing(static function (mixed $state): ?array {
            if (blank($state)) {
                return null;
            }

            $items = is_array($state) ? array_values($state) : [$state];

            $decoded = array_values(
                array_filter(
                    array_map(static function (mixed $item): mixed {
                        if (is_string($item)) {
                            return json_decode($item, true);
                        }

                        return is_array($item) ? $item : null;
                    }, $items),
                ),
            );

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
