<?php

namespace App\Gtfs;

/**
 * Validity and number of rides of a trip, dates as 'YYYYMMDD'.
 */
final readonly class GtfsTripPeriod
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $rides,
        public int $sumRides,
    ) {
    }
}
