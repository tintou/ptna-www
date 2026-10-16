<?php

namespace App\Gtfs;

final readonly class GtfsRouteRow
{
    /**
     * @param list<string> $commentLines
     */
    public function __construct(
        public string $rowId,
        public bool $alternate,
        public string $routeId,
        public string $shortName,
        public string $typeLabel,
        public string $osmRoute,
        public string $validFrom,
        public string $validFromClass,
        public string $validUntil,
        public string $validUntilClass,
        public string $name,
        public string $agencyName,
        public ?string $agencyUrl,
        public array $commentLines,
    ) {
    }
}
