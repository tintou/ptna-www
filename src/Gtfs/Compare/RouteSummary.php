<?php

namespace App\Gtfs\Compare;

/**
 * A route of a GTFS feed with figures aggregated over all its trips.
 */
final readonly class RouteSummary
{
    public function __construct(
        public string $routeId,
        public string $shortName,
        public string $longName,
        public string $routeType,
        public string $agencyName,
        public string $sortKey,
        public int $variants,
        public int $stops,
        public int $uniqueStopIds,
        public int $uniqueStopNames,
        public string $md5StopIds,
        public string $md5StopNames,
        public string $md5StopPositions,
        public string $startDate,
        public string $endDate,
    ) {
    }

    public function differsFrom(self $other): bool
    {
        return $this->variants !== $other->variants
            || $this->stops !== $other->stops
            || $this->uniqueStopIds !== $other->uniqueStopIds
            || $this->uniqueStopNames !== $other->uniqueStopNames
            || $this->md5StopIds !== $other->md5StopIds
            || $this->md5StopNames !== $other->md5StopNames
            || $this->md5StopPositions !== $other->md5StopPositions;
    }
}
