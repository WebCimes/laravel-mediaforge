<?php

namespace Webcimes\LaravelMediaforge\Traits;

use Webcimes\LaravelMediaforge\Facades\MediaForge;

/**
 * Automatically deletes MediaForge files when the model is permanently deleted.
 *
 * Usage: add `use HasMediaForge;` to your model and declare the columns
 * that hold MediaForge data:
 *
 *   protected array $mediaForgeColumns = ['cover', 'images'];
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
                MediaForge::delete($model->{$column});
            }
        });
    }
}
