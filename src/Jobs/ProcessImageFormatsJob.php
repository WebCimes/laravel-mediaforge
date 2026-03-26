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
     * Dispatch the job only after the current database transaction commits.
     * Ensures the model is already saved before the job tries to update it
     * (critical for Filament forms which wrap their save in a transaction).
     *
     * @param array           $storedEntry   Entry containing at least the 'default' format (disk + path + dimensions).
     * @param array           $formatsConfig Non-default formats to generate, keyed by name.
     *                                       Each value is the result of ImageFormat::toConfigArray().
     * @param string|null     $modelClass    Eloquent model class for auto-update after processing (e.g. App\Models\Post::class).
     * @param int|string|null $modelId       Primary key of the model instance.
     * @param string|null     $modelColumn   Model attribute / column name that stores the media entry.
     */
    public function __construct(
        public readonly array $storedEntry,
        public readonly array $formatsConfig,
        public readonly ?string $modelClass = null,
        public readonly int|string|null $modelId = null,
        public readonly ?string $modelColumn = null,
    ) {
        $this->afterCommit = true;
    }

    public function handle(MediaForge $mediaForge): void
    {
        $formats = array_map(
            fn(string $name, array $config) => ImageFormat::fromConfigArray($name, $config),
            array_keys($this->formatsConfig),
            array_values($this->formatsConfig),
        );

        $result = $mediaForge->regenerate($this->storedEntry, $formats);

        // Auto-update the model column when class, ID and column are all provided.
        // Handles both a single-entry column (['default'=>…,'thumb'=>…])
        // and a list-of-entries column ([[…],[…]]) — as produced by handleFiles().
        if ($this->modelClass && $this->modelId !== null && $this->modelColumn) {
            $model = ($this->modelClass)::find($this->modelId);

            if ($model) {
                $data = $model->{$this->modelColumn};
                $defaultPath = $this->storedEntry['default']['path'];

                if (is_array($data)) {
                    if (array_is_list($data)) {
                        // List of entries: [[default => …, thumb => …], […]]
                        foreach ($data as &$item) {
                            if (is_array($item) && ($item['default']['path'] ?? null) === $defaultPath) {
                                $item = $result;
                                break;
                            }
                        }
                        unset($item);
                    } else {
                        // Single entry: [default => …, thumb => …]
                        if (($data['default']['path'] ?? null) === $defaultPath) {
                            $data = $result;
                        }
                    }

                    $model->{$this->modelColumn} = $data;
                    $model->save();
                }
            }
        }

        event(new ImageFormatsProcessed(
            defaultPath: $this->storedEntry['default']['path'],
            entry: $result,
        ));
    }
}
