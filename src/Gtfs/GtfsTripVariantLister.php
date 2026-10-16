<?php

namespace App\Gtfs;

/**
 * The variants of a route (trips with different stops or shapes) for the page /gtfs/<country>/trips.
 */
final class GtfsTripVariantLister
{
    public function __construct(
        private readonly GtfsTripResolver $trips,
        private readonly GtfsTripPeriodCalculator $periods,
        private readonly PtnaCommentFormatter $comments,
    ) {
    }

    /**
     * @return list<GtfsTripVariant>
     */
    public function list(GtfsDatabase $db, string $routeId): array
    {
        $connection = $db->connection();
        $today = new \DateTimeImmutable();

        $seen = [];
        $sortable = [];
        foreach ($connection->fetchAllAssociative('SELECT trip_id, shape_id FROM trips WHERE route_id = :id ORDER BY trip_id', ['id' => $routeId]) as $trip) {
            $tripId = (string) $trip['trip_id'];

            // GTFS requires the stop_times to be sorted by stop_sequence, but only the PTNA import ensures it
            $stops = $connection->fetchAllAssociative(
                'SELECT stop_times.stop_id, stops.stop_name
                 FROM stop_times JOIN stops ON stop_times.stop_id = stops.stop_id
                 WHERE stop_times.trip_id = :id
                 ORDER BY CAST(stop_times.stop_sequence AS INTEGER)',
                ['id' => $tripId],
            );
            if ($stops === []) {
                continue;
            }

            $key = implode("\0", array_column($stops, 'stop_id')) . "\0\0" . ($trip['shape_id'] ?? '-');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $names = array_column($stops, 'stop_name');
            $first = (string) array_shift($names);
            $last = (string) (array_pop($names) ?? '');
            $sortable[] = [$first, $last, $names, $tripId];
        }

        // by the first stop, the last stop, the stops in between and the trip
        usort($sortable, static fn (array $a, array $b) => strcmp(
            $a[0] . "\0" . $a[1] . "\0" . implode("\0", $a[2]) . "\0" . $a[3],
            $b[0] . "\0" . $b[1] . "\0" . implode("\0", $b[2]) . "\0" . $b[3],
        ));

        $variants = [];
        foreach ($sortable as $index => [$first, $last, $via, $tripId]) {
            $row = $this->trips->tripRow($db, $tripId) ?? [];
            $period = $this->periods->forTrip($db, $tripId);

            $variants[] = new GtfsTripVariant(
                $index + 1,
                $tripId,
                $period->sumRides > $period->rides ? $period->rides . ' (' . $period->sumRides . ')' : (string) $period->rides,
                isset($row['direction_id']) ? (string) $row['direction_id'] : null,
                GtfsDate::display($period->startDate),
                GtfsDate::startClass($period->startDate, $today),
                GtfsDate::display($period->endDate),
                GtfsDate::endClass($period->endDate, $today),
                $first,
                implode(' => ', $via),
                $last,
                $this->comments->lines($row),
            );
        }

        return $variants;
    }
}
