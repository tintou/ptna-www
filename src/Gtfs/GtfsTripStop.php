<?php

namespace App\Gtfs;

final readonly class GtfsTripStop
{
    /**
     * @param list<string> $commentLines
     */
    public function __construct(
        public int $number,
        public string $name,
        public ?string $normalizedName,
        public ?string $idUrl,
        public ?string $josmUrl,
        public string $departure,
        public string $lat,
        public string $lon,
        public string $stopId,
        public string $platformCode,
        public array $commentLines,
    ) {
    }
}
