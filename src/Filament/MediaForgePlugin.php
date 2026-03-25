<?php

namespace Webcimes\LaravelMediaforge\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MediaForgePlugin implements Plugin
{
    public function getId(): string
    {
        return 'mediaforge';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
