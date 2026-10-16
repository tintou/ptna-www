<?php

namespace App\Gtfs;

final readonly class GtfsServiceTimes
{
    /**
     * @param list<bool> $weekdays Monday to Sunday
     */
    public function __construct(
        public string $startDate,
        public string $startClass,
        public string $endDate,
        public string $endClass,
        public array $weekdays,
        public string $alsoOn,
        public string $notOn,
        public string $departures,
        public ?string $durations,
        public bool $wide,
        public string $serviceId,
    ) {
    }
}
