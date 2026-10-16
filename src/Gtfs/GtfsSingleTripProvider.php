<?php

namespace App\Gtfs;

/**
 * The stops and the service times of a trip for the page /gtfs/<country>/single-trip.
 */
final class GtfsSingleTripProvider
{
    // SQLite limits the number of terms in a query
    public const MAX_SERVICE_IDS = 999;

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(private readonly PtnaCommentFormatter $comments)
    {
    }

    /**
     * @return list<GtfsTripStop>
     */
    public function stops(GtfsDatabase $db, string $tripId): array
    {
        $join = $db->hasTable('ptna_stops') ? 'LEFT OUTER JOIN ptna_stops ON stop_times.stop_id = ptna_stops.stop_id' : '';
        $rows = $db->connection()->fetchAllAssociative(
            "SELECT *, stop_times.stop_id
             FROM stop_times
             JOIN stops ON stop_times.stop_id = stops.stop_id
             $join
             WHERE stop_times.trip_id = :id
             ORDER BY CAST(stop_times.stop_sequence AS INTEGER) ASC",
            ['id' => $tripId],
        );

        $stops = [];
        foreach ($rows as $counter => $row) {
            $lat = (string) ($row['stop_lat'] ?? '');
            $lon = (string) ($row['stop_lon'] ?? '');
            $numeric = is_numeric($lat) && is_numeric($lon);
            $bbox = $numeric ? $this->bbox((float) $lat, (float) $lon, 15) : null;

            $stops[] = new GtfsTripStop(
                $counter + 1,
                (string) ($row['stop_name'] ?? ''),
                ($row['normalized_stop_name'] ?? '') !== '' ? (string) $row['normalized_stop_name'] : null,
                $numeric ? sprintf('https://www.openstreetmap.org/edit?editor=id#map=21/%s/%s', $lat, $lon) : null,
                $bbox !== null ? sprintf('http://127.0.0.1:8111/load_and_zoom?left=%s&right=%s&top=%s&bottom=%s&new_layer=false', $bbox['left'], $bbox['right'], $bbox['top'], $bbox['bottom']) : null,
                preg_replace('/:\d\d$/', '', (string) ($row['departure_time'] ?? '')),
                $lat,
                $lon,
                (string) ($row['stop_id'] ?? ''),
                (string) ($row['platform_code'] ?? ''),
                $this->comments->lines($row),
            );
        }

        return $stops;
    }

    /**
     * @param string $tripId the representative trip (see GtfsTripResolver)
     *
     * @return array{rows: list<GtfsServiceTimes>, truncated: bool}
     */
    public function serviceTimes(GtfsDatabase $db, string $tripId): array
    {
        $empty = ['rows' => [], 'truncated' => false];
        if (!$db->hasTable('ptna_trips')) {
            return $empty;
        }

        $ptnaTrip = $db->connection()->fetchAssociative('SELECT * FROM ptna_trips WHERE trip_id = :id', ['id' => $tripId]);
        if ($ptnaTrip === false || !isset($ptnaTrip['list_trip_ids'], $ptnaTrip['list_departure_times'], $ptnaTrip['list_service_ids'])) {
            return $empty;
        }

        $separator = $db->listSeparator();
        $tripIds = explode($separator, (string) $ptnaTrip['list_trip_ids']);
        $departureTimes = explode($separator, (string) $ptnaTrip['list_departure_times']);
        $serviceIds = explode($separator, (string) $ptnaTrip['list_service_ids']);
        $hasDurations = ($ptnaTrip['list_durations'] ?? '') !== '';
        $durationList = $hasDurations ? explode($separator, (string) $ptnaTrip['list_durations']) : [];

        $departures = [];
        $durations = [];
        foreach (array_keys($tripIds) as $i) {
            if (isset($serviceIds[$i], $departureTimes[$i])) {
                $departures[$serviceIds[$i]][] = $this->withoutSeconds($departureTimes[$i]);
            }
            if (isset($serviceIds[$i], $durationList[$i])) {
                $durations[$serviceIds[$i]][] = $this->withoutSeconds($durationList[$i]);
            }
        }

        $distinct = array_values(array_unique($serviceIds));
        $truncated = count($distinct) > self::MAX_SERVICE_IDS;
        $limited = array_map('strval', array_slice($distinct, 0, self::MAX_SERVICE_IDS));
        if ($limited === []) {
            return $empty;
        }

        $connection = $db->connection();
        $calendar = $connection->fetchAllAssociative(
            'SELECT * FROM calendar WHERE service_id IN (' . implode(',', array_fill(0, count($limited), '?')) . ')',
            $limited,
        );

        $today = new \DateTimeImmutable();
        $sortable = [];
        foreach ($calendar as $row) {
            $serviceId = (string) $row['service_id'];
            if ($serviceId === '') {
                continue;
            }

            $weekdays = array_map(static fn (string $d) => (int) ($row[$d] ?? 0) === 1, self::WEEKDAYS);
            $alsoOn = $this->exceptionDates($connection->fetchFirstColumn('SELECT date FROM calendar_dates WHERE service_id = :id AND exception_type=1', ['id' => $serviceId]));
            $notOn = $this->exceptionDates($connection->fetchFirstColumn('SELECT date FROM calendar_dates WHERE service_id = :id AND exception_type=2', ['id' => $serviceId]));

            $serviceDurations = $durations[$serviceId] ?? [];
            $wide = $hasDurations && count(array_unique($serviceDurations)) > 1;
            $serviceDepartures = $departures[$serviceId] ?? [];
            if (!$wide) {
                $serviceDepartures = array_values(array_unique($serviceDepartures));
                sort($serviceDepartures, SORT_STRING);
            }

            $durationText = null;
            if ($hasDurations) {
                $durationText = $wide ? implode(', ', $serviceDurations) : (string) ($serviceDurations[0] ?? '');
            }

            $start = (string) $row['start_date'];
            $end = (string) $row['end_date'];
            $times = new GtfsServiceTimes(
                GtfsDate::display($start),
                GtfsDate::startClass($start, $today),
                GtfsDate::display($end),
                GtfsDate::endClass($end, $today),
                $weekdays,
                $alsoOn,
                $notOn,
                implode(', ', $serviceDepartures),
                $durationText,
                $wide,
                $serviceId,
            );

            // the rows are ordered by their cells, a missing weekday sorts after a present one
            $sortKey = implode("\0", [$times->startDate, $times->endDate, implode('', array_map(static fn (bool $on) => $on ? 'X' : 'Y', $weekdays)), $alsoOn, $notOn, $times->departures, (string) $times->durations, $serviceId]);
            $sortable[] = [$sortKey, $times];
        }

        usort($sortable, static fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return ['rows' => array_column($sortable, 1), 'truncated' => $truncated];
    }

    /**
     * Bounding box around a position.
     *
     * @param float $offset in meters
     *
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    public function bbox(float $lat, float $lon, float $offset): array
    {
        $earthRadius = 6378137;
        $dLat = $offset / $earthRadius;
        $dLon = $offset / ($earthRadius * cos(pi() * $lat / 180));

        return [
            'top' => $lat + $dLat * 180 / pi(),
            'right' => $lon + $dLon * 180 / pi(),
            'bottom' => $lat - $dLat * 180 / pi(),
            'left' => $lon - $dLon * 180 / pi(),
        ];
    }

    private function withoutSeconds(string $time): string
    {
        return preg_replace('/^(\d{1,2}:\d\d):\d\d$/', '\1', $time);
    }

    /**
     * @param list<mixed> $dates
     */
    private function exceptionDates(array $dates): string
    {
        $dates = array_map('strval', $dates);
        sort($dates, SORT_STRING);

        return implode(', ', array_map(GtfsDate::display(...), $dates));
    }
}
