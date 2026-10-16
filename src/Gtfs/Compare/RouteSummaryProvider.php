<?php

namespace App\Gtfs\Compare;

use App\Gtfs\GtfsDatabase;
use App\Gtfs\RouteTypeCatalog;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Aggregates the routes of one GTFS database: number of variants (trips), stops, MD5 sums over the stop
 * sequences of all trips and the validity period. Routes are sorted by their importance and name.
 */
final class RouteSummaryProvider
{
    private const NO_START = '20500101';
    private const NO_END = '19700101';
    private const MAX_SERVICE_IDS = 999;

    /**
     * @return list<RouteSummary>
     */
    public function summarize(GtfsDatabase $database): array
    {
        $types = RouteTypeCatalog::fromDatabase($database);
        $connection = $database->connection();
        $versioned = ($database->osm()['trip_id_regex'] ?? '') !== '';
        $hasPtnaTrips = $database->hasTable('ptna_trips');
        $listSeparator = $database->listSeparator();

        $agencies = [];
        foreach ($connection->fetchAllAssociative('SELECT agency_id, agency_name FROM agency') as $agency) {
            $agencies[(string) $agency['agency_id']] ??= (string) $agency['agency_name'];
        }

        $summaries = [];
        foreach ($connection->fetchAllAssociative('SELECT DISTINCT * FROM routes') as $route) {
            $routeId = (string) $route['route_id'];
            $routeType = (int) ($route['route_type'] ?? 0);
            $tripIds = $connection->fetchFirstColumn('SELECT trip_id FROM trips WHERE route_id = :id', ['id' => $routeId]);

            $stopsByTrip = [];
            $stopRows = $connection->fetchAllAssociative(
                'SELECT stop_times.trip_id AS trip_id, stop_times.stop_id AS stopid, stops.stop_name AS stopname,
                        stops.stop_lat AS stoplat, stops.stop_lon AS stoplon
                 FROM stop_times
                 JOIN stops ON stop_times.stop_id = stops.stop_id
                 WHERE stop_times.trip_id IN (SELECT trip_id FROM trips WHERE route_id = :id)
                 ORDER BY stop_times.trip_id, CAST(stop_times.stop_sequence AS INTEGER) ASC',
                ['id' => $routeId],
            );
            foreach ($stopRows as $row) {
                $stopsByTrip[(string) $row['trip_id']][] = $row;
            }

            $ids = [];
            $names = [];
            $idHashes = [];
            $nameHashes = [];
            $positionHashes = [];
            foreach ($stopsByTrip as $stops) {
                $tripIdList = array_column($stops, 'stopid');
                $tripNameList = array_column($stops, 'stopname');
                $ids = [...$ids, ...$tripIdList];
                $names = [...$names, ...$tripNameList];
                $idHashes[] = md5(implode(';', $tripIdList));
                $nameHashes[] = md5(implode(';', $tripNameList));
                $positionHashes[] = md5(implode(';', array_map(static fn (array $s): string => $s['stoplat'] . ';' . $s['stoplon'], $stops)));
            }
            // trips without any stop still count as a variant with an empty sequence
            for ($i = count($tripIds) - count($stopsByTrip); $i > 0; --$i) {
                $idHashes[] = md5('');
                $nameHashes[] = md5('');
                $positionHashes[] = md5('');
            }
            sort($idHashes);
            sort($nameHashes);
            sort($positionHashes);

            [$start, $end] = $this->validity($database, $tripIds, $hasPtnaTrips, $listSeparator);

            $shortName = (string) ($route['route_short_name'] ?? '');

            $summaries[] = new RouteSummary(
                $routeId,
                $shortName,
                (string) ($route['route_long_name'] ?? ''),
                $types->label($routeType),
                $agencies[(string) ($route['agency_id'] ?? '')] ?? '',
                $this->sortKey($types->sortKey($routeType), $shortName, $routeId, $versioned),
                count($tripIds),
                count($ids),
                count(array_unique($ids)),
                count(array_unique($names)),
                md5(implode(';', $idHashes)),
                md5(implode(';', $nameHashes)),
                md5(implode(';', $positionHashes)),
                $start,
                $end,
            );
        }

        usort($summaries, static fn (RouteSummary $a, RouteSummary $b): int => strcmp($a->sortKey, $b->sortKey));

        return $summaries;
    }

    private function sortKey(string $importance, string $shortName, string $routeId, bool $versioned): string
    {
        // numbers inside the name are right aligned, so that 2 sorts before 10
        if (preg_match('/^([0-9]+)(.*)$/', $shortName, $m)) {
            $name = sprintf('%20s%s ', $m[1], $m[2]);
        } elseif (preg_match('/^([^0-9]+)([0-9]+)(.*)$/', $shortName, $m)) {
            $name = sprintf('%s%20s%s ', $m[1], $m[2], $m[3]);
        } else {
            $name = sprintf('%s%20s ', $shortName, ' ');
        }

        // with a trip_id_regex, a route_id ending in '-<number>' is a versioned route_id
        if ($versioned && preg_match('/^(.*)-([0-9]+)$/', $routeId, $m)) {
            return $importance . ';' . $name . ';' . sprintf('%03d', $m[2]) . ';' . $m[1];
        }

        return $importance . ';' . $name . ';' . $routeId;
    }

    /**
     * @param list<string|int> $tripIds
     *
     * @return array{string, string} first and last day of service as YYYY-MM-DD, empty if unknown
     */
    private function validity(GtfsDatabase $database, array $tripIds, bool $hasPtnaTrips, string $listSeparator): array
    {
        $connection = $database->connection();
        $start = self::NO_START;
        $end = self::NO_END;
        $remaining = array_map('strval', $tripIds);

        if ($hasPtnaTrips && $remaining !== []) {
            $serviceIds = [];
            $withoutPtna = array_flip($remaining);
            foreach (array_chunk($remaining, self::MAX_SERVICE_IDS) as $chunk) {
                $rows = $connection->fetchAllAssociative(
                    'SELECT * FROM ptna_trips WHERE trip_id IN (:ids)',
                    ['ids' => $chunk],
                    ['ids' => ArrayParameterType::STRING],
                );
                foreach ($rows as $row) {
                    unset($withoutPtna[(string) $row['trip_id']]);
                    if (isset($row['min_start_date'], $row['max_end_date'])) {
                        [$start, $end] = $this->widen($start, $end, (string) $row['min_start_date'], (string) $row['max_end_date']);
                    } elseif (isset($row['list_service_ids']) && $row['list_service_ids'] !== '') {
                        array_push($serviceIds, ...explode($listSeparator, (string) $row['list_service_ids']));
                    } else {
                        $withoutPtna[(string) $row['trip_id']] = true;
                    }
                }
            }

            foreach (array_chunk(array_values(array_unique($serviceIds)), self::MAX_SERVICE_IDS) as $chunk) {
                $rows = $connection->fetchAllAssociative(
                    'SELECT start_date, end_date FROM calendar WHERE service_id IN (:ids)',
                    ['ids' => $chunk],
                    ['ids' => ArrayParameterType::STRING],
                );
                foreach ($rows as $row) {
                    [$start, $end] = $this->widen($start, $end, (string) $row['start_date'], (string) $row['end_date']);
                }
            }

            $remaining = array_map('strval', array_keys($withoutPtna));
        }

        foreach (array_chunk($remaining, self::MAX_SERVICE_IDS) as $chunk) {
            $rows = $connection->fetchAllAssociative(
                'SELECT calendar.start_date AS start_date, calendar.end_date AS end_date
                 FROM calendar JOIN trips ON trips.service_id = calendar.service_id
                 WHERE trips.trip_id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                [$start, $end] = $this->widen($start, $end, (string) $row['start_date'], (string) $row['end_date']);
            }
        }

        return [$this->format($start, self::NO_START), $this->format($end, self::NO_END)];
    }

    /**
     * @return array{string, string}
     */
    private function widen(string $start, string $end, string $newStart, string $newEnd): array
    {
        if (preg_match('/^\d{8}$/', $newStart) && $newStart < $start) {
            $start = $newStart;
        }
        if (preg_match('/^\d{8}$/', $newEnd) && $newEnd > $end) {
            $end = $newEnd;
        }

        return [$start, $end];
    }

    private function format(string $date, string $unknown): string
    {
        return $date !== $unknown && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
    }
}
