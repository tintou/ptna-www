<?php

namespace App\Gtfs;

final readonly class OsmTaggingSuggestion
{
    /**
     * @param list<OsmTag> $routeMasterTags
     * @param list<OsmTag> $routeTags
     */
    public function __construct(
        public array $routeMasterTags,
        public array $routeTags,
    ) {
    }
}
