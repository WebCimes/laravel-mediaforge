<?php

namespace Webcimes\LaravelMediaforge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webcimes\LaravelMediaforge\Events\ImageFormatsProcessed;
use Webcimes\LaravelMediaforge\ImageFormat;
use Webcimes\LaravelMediaforge\MediaForge;

class ProcessImageFormatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array $storedEntry   Entry containing at least the 'default' format (disk + path + dimensions).
     * @param array $formatsConfig Non-default formats to generate, keyed by name.
     *                            Each value is the result of ImageFormat::toConfigArray().
     */
    public function __construct(
        public readonly array $storedEntry,
        public readonly array $formatsConfig,
    ) {}

    public function handle(MediaForge $mediaForge): void
    {
        $formats = array_map(
            fn(string $name, array $config) => ImageFormat::fromConfigArray($name, $config),
            array_keys($this->formatsConfig),
            array_values($this->formatsConfig),
        );

        $result = $mediaForge->regenerate($this->storedEntry, $formats);

        event(new ImageFormatsProcessed(
            defaultPath: $this->storedEntry['default']['path'],
            entry: $result,
        ));
    }
}
