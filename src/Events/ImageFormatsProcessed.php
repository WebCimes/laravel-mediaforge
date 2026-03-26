<?php

namespace Webcimes\LaravelMediaforge\Events;

class ImageFormatsProcessed
{
    /**
     * Fired after a queued upload finishes processing all non-default formats.
     *
     * Use `$defaultPath` as a lookup key to find the model that stored the initial
     * (partial) entry, then update it with the complete `$entry`.
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
