<?php

namespace Webcimes\LaravelMediaforge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null upload(\Illuminate\Http\UploadedFile|null $uploadedFile, string $diskName = 'public', string $path = '', \Webcimes\LaravelMediaforge\ImageFormat|array|null $imageFormats = null, ?string $customBaseName = null)
 * @method static array regenerate(array $storedEntry, \Webcimes\LaravelMediaforge\ImageFormat|array $formats)
 * @method static void delete(array|string|null $filesToDelete, string $diskName = 'public')
 * @method static array|null handleFiles(string $diskName, string $path, array|null $uploadedFiles, ?array $filesToDeleteIndex, ?array $globalOrder = null, ?array $existingFiles = null, \Webcimes\LaravelMediaforge\ImageFormat|array|null $imageFormats = null, ?string $customBaseName = null)
 *
 * @see \Webcimes\LaravelMediaforge\MediaForge
 */
class MediaForge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Webcimes\LaravelMediaforge\MediaForge::class;
    }
}
