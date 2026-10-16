<?php

namespace App\Gtfs;

final readonly class OsmTag
{
    /**
     * @param string      $kind  'name' for short values, 'text' for free text (CSS class of the cell)
     * @param string|null $title tooltip of the value, e.g. the original GTFS name of a normalized stop name
     */
    public function __construct(
        public string $key,
        public string $value,
        public string $kind = 'name',
        public ?string $title = null,
    ) {
    }
}
