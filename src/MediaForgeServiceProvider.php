<?php

namespace Webcimes\LaravelMediaforge;

use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

use Webcimes\LaravelMediaforge\MediaForge;

class MediaForgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mediaforge.php', 'mediaforge');

        // Default to the Montserrat font bundled in the package if no custom font is set.
        if (!config('mediaforge.text.font')) {
            config([
                'mediaforge.text.font' =>
                    __DIR__ . '/../resources/fonts/montserrat/Montserrat-Regular.ttf',
            ]);
        }

        $this->app->singleton(ImageManager::class, function ($app) {
            $driver = config('mediaforge.driver', 'gd');

            if ($driver === 'vips') {
                // Vips requires the separate package: composer require intervention/image-driver-vips
                $vipsDriverClass = 'Intervention\Image\Drivers\Vips\Driver';
                if (!class_exists($vipsDriverClass)) {
                    throw new \RuntimeException(
                        'The vips driver requires the intervention/image-driver-vips package. ' .
                            'Run: composer require intervention/image-driver-vips',
                    );
                }

                return new ImageManager(new $vipsDriverClass());
            }

            return new ImageManager($driver === 'imagick' ? new ImagickDriver() : new GdDriver());
        });

        $this->app->singleton(MediaForge::class, function ($app) {
            return new MediaForge(
                $app->make(\Illuminate\Contracts\Filesystem\Factory::class),
                $app->make(ImageManager::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [
                    __DIR__ . '/../config/mediaforge.php' => config_path('mediaforge.php'),
                ],
                'mediaforge-config',
            );
        }
    }
}
