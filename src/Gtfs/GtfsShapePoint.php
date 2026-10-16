<?php

namespace App\Gtfs;

final readonly class GtfsShapePoint
{
    public function __construct(
        public string $number,
        public ?string $lat,
        public ?string $lon,
        public ?string $distance,
    ) {
    }
}
