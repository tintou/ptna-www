<?php

namespace App\Gtfs;

final readonly class GtfsRouteType
{
    public function __construct(
        public int $routeType,
        public string $label,
        public ?string $sortKey,
        public ?string $osmRoute,
    ) {
    }
}
