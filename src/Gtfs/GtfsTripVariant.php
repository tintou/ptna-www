<?php

namespace App\Gtfs;

final readonly class GtfsTripVariant
{
    /**
     * @param list<string> $commentLines
     */
    public function __construct(
        public int $index,
        public string $tripId,
        public string $rides,
        public ?string $directionId,
        public string $startDate,
        public string $startClass,
        public string $endDate,
        public string $endClass,
        public string $firstStop,
        public string $viaStops,
        public string $lastStop,
        public array $commentLines,
    ) {
    }
}
