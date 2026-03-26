<?php

namespace Webcimes\LaravelMediaforge\Events;

class ImageFormatsProcessed
{
    /**
     * Fired after a queued upload finishes processing all non-default formats.
     *
     * **Preferred approach — auto-update via model binding:**
     * Pass `model:` and `modelColumn:` to `upload()` or `handleFiles()`. The job will
     * find the stored entry by `$defaultPath` and replace it with the complete `$entry`,
     * no listener required. Works automatically in Filament edit forms.
     *
     * **Fallback — use this event when:**
     * - The record was not yet persisted at upload time (Filament create forms)
     * - You need side effects beyond a simple column update (broadcast, webhooks, logs)
     *
     * Example listener:
     * ```php
     * Event::listen(ImageFormatsProcessed::class, function ($event) {
     *     $model = MyModel::where('media->0->default->path', $event->defaultPath)->first();
     *     if ($model) {
     *         $media = $model->media;
     *         foreach ($media as &$item) {
     *             if (($item['default']['path'] ?? null) === $event->defaultPath) {
     *                 $item = $event->entry;
     *             }
     *         }
     *         $model->media = $media;
     *         $model->save();
     *     }
     * });
     * ```
     *
     * @param string $defaultPath Path of the 'default' format — unique identifier for this upload.
     * @param array  $entry       Complete entry with all processed format keys.
     */
    public function __construct(
        public readonly string $defaultPath,
        public readonly array $entry,
    ) {}
}
