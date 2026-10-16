<?php

namespace App\Gtfs;

/**
 * Validity period and rough number of rides of the (identical) trips of a representative trip.
 */
final class GtfsTripPeriodCalculator
{
    private const NO_START = '20500101';
    private const NO_END = '19700101';
    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    // SQLite limits the number of terms in a query
    private const MAX_SERVICE_IDS = 999;

    public function forTrip(GtfsDatabase $db, string $tripId): GtfsTripPeriod
    {
        $start = self::NO_START;
        $end = self::NO_END;
        $rides = -1;
        $sumRides = -1;

        $ptnaTrip = $db->hasTable('ptna_trips')
            ? $db->connection()->fetchAssociative('SELECT * FROM ptna_trips WHERE trip_id = :id', ['id' => $tripId])
            : false;

        $hasDates = false;
        $hasRides = false;
        if ($ptnaTrip !== false) {
            if (isset($ptnaTrip['min_start_date'], $ptnaTrip['max_end_date'])) {
                $start = (string) $ptnaTrip['min_start_date'];
                $end = (string) $ptnaTrip['max_end_date'];
                $hasDates = true;
            }
            if (isset($ptnaTrip['rides'], $ptnaTrip['sum_rides'])) {
                $rides = (int) $ptnaTrip['rides'];
                $sumRides = (int) $ptnaTrip['sum_rides'];
                $hasRides = true;
            }
        }

        if (!$hasDates || !$hasRides) {
            if ($ptnaTrip !== false && isset($ptnaTrip['list_service_ids'])) {
                [$calendarStart, $calendarEnd, $calendarRides] = $this->fromServiceIds($db, (string) $ptnaTrip['list_service_ids']);
                if (!$hasDates) {
                    $start = $calendarStart;
                    $end = $calendarEnd;
                }
                if (!$hasRides) {
                    $rides = $sumRides = $calendarRides;
                }
            } elseif (!$hasDates) {
                [$start, $end] = $this->fromCalendarOfTrip($db, $tripId);
            }
        }

        return new GtfsTripPeriod($start, $end, $rides, $sumRides);
    }

    /**
     * @return array{string, string, int} start date, end date and rides
     */
    private function fromServiceIds(GtfsDatabase $db, string $list): array
    {
        $serviceIds = array_count_values(explode($db->listSeparator(), $list));
        $limited = array_slice(array_keys($serviceIds), 0, self::MAX_SERVICE_IDS);
        if ($limited === []) {
            return [self::NO_START, self::NO_END, 0];
        }

        $connection = $db->connection();
        $rows = $connection->fetchAllAssociative(
            'SELECT * FROM calendar WHERE service_id IN (' . implode(',', array_fill(0, count($limited), '?')) . ')',
            array_map('strval', $limited),
        );

        $start = self::NO_START;
        $end = self::NO_END;
        $rides = 0;
        foreach ($rows as $row) {
            $start = min($start, (string) $row['start_date']);
            $end = max($end, (string) $row['end_date']);

            if ($row['start_date'] > $row['end_date']) {
                continue;
            }
            $days = (int) (new \DateTimeImmutable((string) $row['start_date']))->diff(new \DateTimeImmutable((string) $row['end_date']))->days;
            $daysOfWeek = count(array_filter(self::WEEKDAYS, static fn (string $d) => (int) ($row[$d] ?? 0) === 1));
            $serviceDays = (int) ceil($days * $daysOfWeek / 7);
            if ($serviceDays < 1 && $daysOfWeek > 0) {
                $serviceDays = $daysOfWeek;
            }

            $exceptions = $connection->fetchAllKeyValue(
                'SELECT exception_type, COUNT(exception_type) FROM calendar_dates WHERE service_id = :id GROUP BY exception_type',
                ['id' => (string) $row['service_id']],
            );
            $serviceDays += (int) ($exceptions[1] ?? 0) - (int) ($exceptions[2] ?? 0);
            $rides += $serviceDays * $serviceIds[$row['service_id']];
        }

        return [$start, $end, $rides];
    }

    /**
     * @return array{string, string}
     */
    private function fromCalendarOfTrip(GtfsDatabase $db, string $tripId): array
    {
        $start = self::NO_START;
        $end = self::NO_END;
        $rows = $db->connection()->fetchAllAssociative(
            'SELECT start_date, end_date FROM calendar JOIN trips ON trips.service_id = calendar.service_id WHERE trip_id = :id',
            ['id' => $tripId],
        );
        foreach ($rows as $row) {
            $start = min($start, (string) $row['start_date']);
            $end = max($end, (string) $row['end_date']);
        }

        return [$start, $end];
    }
}
