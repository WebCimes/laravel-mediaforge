<?php

namespace Webcimes\LaravelMediaforge\Filament\Forms\Components;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;
use Illuminate\Support\Str;

/**
 * State cast tailored for MediaForge format-maps.
 *
 * Replaces Filament's default FileUploadStateCast, which in single mode
 * calls `Arr::first()` and would strip the 'default' wrapper of a
 * format-map (e.g. ['default' => [...]] → ['disk' => ..., 'path' => ...]).
 *
 * Internal state is always a UUID-keyed array of items so each entry has a
 * stable identity for FilePond, regardless of whether we're in single or
 * multiple mode:
 *   ['ulid1' => '{"default":{...}}', 'ulid2' => '{"default":{...}}']
 *
 * External representation:
 *   - Multiple: list of items (format-maps or JSON strings)
 *   - Single:   one item (format-map or JSON string), or null
 */
class MediaForgeStateCast implements StateCast
{
    public function __construct(protected bool $isMultiple = false) {}

    /**
     * External → internal: wrap each item under a UUID key so the format-map
     * structure (e.g. `default`, `thumb`, …) is preserved.
     */
    public function set(mixed $state): mixed
    {
        if (blank($state)) {
            return [];
        }

        $items = match (true) {
            is_string($state) => [$state],
            is_array($state) && array_is_list($state) => array_values($state),
            is_array($state) => [$state], // single associative format-map
            default => [$state],
        };

        $newState = [];

        foreach ($items as $item) {
            if (blank($item)) {
                continue;
            }
            $newState[(string) Str::uuid()] = $item;
        }

        return $newState;
    }

    /**
     * Internal → external: list of values for multiple, first value for single.
     */
    public function get(mixed $state): mixed
    {
        if (!is_array($state)) {
            return $this->isMultiple ? [] : $state;
        }

        $items = array_values($state);

        if ($this->isMultiple) {
            return $items;
        }

        return $items[0] ?? null;
    }
}
