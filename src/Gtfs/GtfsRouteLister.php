<?php

namespace App\Gtfs;

/**
 * The routes of a feed release for the page /gtfs/<country>/routes.
 */
final class GtfsRouteLister
{
    public function __construct(
        private readonly GtfsTripPeriodCalculator $periods,
        private readonly PtnaCommentFormatter $comments,
    ) {
    }

    /**
     * @return list<GtfsRouteRow>
     */
    public function list(GtfsDatabase $db): array
    {
        $types = RouteTypeCatalog::fromDatabase($db);
        $ptna = $db->ptna();
        $today = new \DateTimeImmutable();

        $entries = [];
        foreach ($db->routes() as $route) {
            $entries[] = [$types->sortKey($route->type) . ';' . $this->paddedName($route->shortName) . ';' . $route->id, $route];
        }
        usort($entries, static fn (array $a, array $b) => strcmp($a[0], $b[0]));

        $commentTable = $db->hasTable('ptna_routes_comments');
        $rows = [];
        $idCounts = [];
        $alternate = true;
        $last = null;
        foreach ($entries as [, $route]) {
            $columns = $route->columns;
            $description = (string) ($columns['route_desc'] ?? '');

            $identity = [$route->shortName, $route->agencyName, $route->type, $description];
            if ($identity !== $last) {
                $alternate = !$alternate;
                $last = $identity;
            }

            $osmRoute = isset($columns['route_type']) ? $types->osmRoute($route->type) : '???';
            $typeLabel = isset($columns['route_type']) ? $types->label($route->type) : '???';

            if (isset($columns['min_start_date'], $columns['max_end_date'])) {
                $start = (string) $columns['min_start_date'];
                $end = (string) $columns['max_end_date'];
            } else {
                [$start, $end] = $this->validity($db, $route->id, (bool) ($ptna['consider_calendar'] ?? false), $today);
            }

            $shortName = $route->shortName !== '' ? $route->shortName : '???';
            $rowId = preg_replace('/[^0-9A-Za-z_.-]/', '_', $osmRoute . '_' . $shortName);
            if (isset($idCounts[$rowId])) {
                $rowId .= '-' . ++$idCounts[$rowId];
            } else {
                $idCounts[$rowId] = 1;
            }

            $name = match (true) {
                ($columns['normalized_route_long_name'] ?? '') !== '' => (string) $columns['normalized_route_long_name'],
                $route->longName !== '' => $route->longName,
                isset($columns['route_desc']) => $description,
                default => $route->id,
            };

            $comment = $commentTable
                ? $db->connection()->fetchAssociative('SELECT * FROM ptna_routes_comments WHERE route_id = :id', ['id' => $route->id])
                : false;

            $url = (string) ($columns['agency_url'] ?? '');
            $rows[] = new GtfsRouteRow(
                $rowId,
                $alternate,
                $route->id,
                $shortName,
                $typeLabel,
                $osmRoute,
                GtfsDate::display($start),
                GtfsDate::startClass($start, $today),
                GtfsDate::display($end),
                GtfsDate::endClass($end, $today),
                $name,
                (string) $route->agencyName,
                $this->safeUrl($url),
                $comment ? $this->comments->lines($comment) : [],
            );
        }

        return $rows;
    }

    /**
     * Sort key of the short name: numbers are compared by value, blanks are ignored.
     */
    private function paddedName(string $shortName): string
    {
        $name = str_replace(' ', '', $shortName);
        if (preg_match('/^([0-9]+)(.*)$/', $name, $parts)) {
            return sprintf('%20s%s ', $parts[1], $parts[2]);
        }
        if (preg_match('/^([^0-9]+)([0-9]+)(.*)$/', $name, $parts)) {
            return sprintf('%s%20s%s ', $parts[1], $parts[2], $parts[3]);
        }

        return sprintf('%s%20s ', $name, ' ');
    }

    /**
     * @return array{string, string} first and last day of service of the route
     */
    private function validity(GtfsDatabase $db, string $routeId, bool $considerCalendar, \DateTimeImmutable $today): array
    {
        $params = ['id' => $routeId];
        if ($considerCalendar) {
            $sql = 'SELECT DISTINCT trip_id FROM trips JOIN calendar ON trips.service_id = calendar.service_id
                    WHERE trips.route_id = :id AND :today >= calendar.start_date AND :today <= calendar.end_date';
            $params['today'] = $today->format('Ymd');
        } else {
            $sql = 'SELECT DISTINCT trip_id FROM trips WHERE trips.route_id = :id';
        }

        $start = '20500101';
        $end = '19700101';
        foreach ($db->connection()->fetchFirstColumn($sql, $params) as $tripId) {
            $period = $this->periods->forTrip($db, (string) $tripId);
            if (preg_match('/^\d{8}$/', $period->startDate)) {
                $start = min($start, $period->startDate);
            }
            if (preg_match('/^\d{8}$/', $period->endDate)) {
                $end = max($end, $period->endDate);
            }
        }

        return [$start, $end];
    }

    // the URL comes from the feed: only links to web sites are offered
    private function safeUrl(string $url): ?string
    {
        return preg_match('#^https?://#i', $url) ? $url : null;
    }
}
