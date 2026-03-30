<?php

namespace Webcimes\LaravelMediaforge\Traits;

use Webcimes\LaravelMediaforge\Facades\MediaForge;

/**
 * Automatically deletes MediaForge files when the model is permanently deleted.
 *
 * Usage: add `use HasMediaForge;` to your model and declare which columns
 * (or nested paths) hold MediaForge data:
 *
 *   protected array $mediaForgeColumns = [
 *       'cover',                      // direct column
 *       'images',                     // multiple-upload column (array of format maps)
 *       'content.hero.image',         // nested inside a JSON column
 *       'content.slides.*.image',     // repeater — wildcard over all slide items
 *   ];
 *
 * Soft-delete aware: files are NOT removed on a soft delete — only on a
 * permanent (force) delete, so restored records still have their files intact.
 */
trait HasMediaForge
{
    protected static function bootHasMediaForge(): void
    {
        static::deleting(function ($model) {
            // If the model uses SoftDeletes and this is NOT a force-delete,
            // skip file deletion so files survive a potential restore.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            foreach ($model->mediaForgeColumns ?? [] as $column) {
                if (str_contains($column, '.')) {
                    // Dot notation: extract value from a nested JSON column.
                    // e.g. 'content.hero.image'    → data_get($model->content, 'hero.image')
                    // e.g. 'content.slides.*.image' → array of format maps (one per slide)
                    [$root, $path] = explode('.', $column, 2);
                    $value = data_get($model->{$root}, $path);

                    if ($value === null) {
                        continue;
                    }

                    // data_get() with a '*' wildcard returns a flat indexed array of results.
                    // Each result is a format map → delete individually.
                    if (str_contains($path, '*')) {
                        foreach ((array) $value as $formatMap) {
                            MediaForge::delete($formatMap);
                        }
                    } else {
                        MediaForge::delete($value);
                    }
                } else {
                    MediaForge::delete($model->{$column});
                }
            }
        });
    }
}
