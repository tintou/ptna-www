<?php

namespace App\Gtfs;

final readonly class GtfsShapeTrip
{
    public function __construct(
        public string $tripId,
        public string $routeShortName,
        public ?string $tripShortName,
        public ?string $headsign,
    ) {
    }
}
