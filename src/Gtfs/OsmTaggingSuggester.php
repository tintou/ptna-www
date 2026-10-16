<?php

namespace App\Gtfs;

/**
 * Suggests the OSM tags of the route and the route master of a trip, derived from the 'osm' settings of the feed.
 */
final class OsmTaggingSuggester
{
    public function __construct(private readonly GtfsTripResolver $trips)
    {
    }

    /**
     * @param string $tripId the trip as requested: it is part of the suggestion and can be any trip of an identical group
     */
    public function suggest(GtfsDatabase $db, string $feed, string $tripId): ?OsmTaggingSuggestion
    {
        $representative = $this->trips->representativeTripId($db, $tripId);
        if ($representative === null) {
            return null;
        }

        $connection = $db->connection();
        $osm = $db->osm();
        $ptna = $db->ptna();

        $tripRow = $connection->fetchAssociative('SELECT * FROM trips WHERE trip_id = :id', ['id' => $representative]);
        $routeRow = $this->trips->routeOfTrip($db, $representative);
        if ($tripRow === false || $routeRow === null) {
            return null;
        }
        // the columns of trips win over the ones of routes
        $routes = $tripRow + $routeRow;

        $join = $db->hasTable('ptna_stops') ? 'LEFT OUTER JOIN ptna_stops ON stops.stop_id = ptna_stops.stop_id' : '';
        $stopSql = "SELECT * FROM stops $join JOIN stop_times ON stop_times.stop_id = stops.stop_id WHERE stop_times.trip_id = :id ORDER BY CAST(stop_times.stop_sequence AS INTEGER) %s LIMIT 1";
        $firstStop = $connection->fetchAssociative(sprintf($stopSql, 'ASC'), ['id' => $representative]) ?: [];
        $lastStop = $connection->fetchAssociative(sprintf($stopSql, 'DESC'), ['id' => $representative]) ?: [];

        $agency = $connection->fetchAssociative(
            'SELECT agency.agency_name, agency.agency_url FROM agency JOIN routes ON agency.agency_id = routes.agency_id WHERE routes.route_id = :id LIMIT 1',
            ['id' => $routes['route_id']],
        ) ?: [];

        $types = RouteTypeCatalog::fromDatabase($db);
        $osmRoute = $types->osmRoute((int) $routes['route_type']);
        $vehicle = $types->vehicle($osmRoute, (string) ($ptna['language'] ?? ''));

        $ref = (string) ($routes['route_short_name'] ?? '') !== '' ? (string) $routes['route_short_name'] : '????';
        if (!empty($osm['gtfs_short_name_hack1']) && !empty($routes['route_long_name']) && (string) $routes['route_id'] !== '' && $routes['route_long_name'] !== $routes['route_id']) {
            $ref = (string) $routes['route_long_name'];
        }
        $quoted = preg_quote($vehicle, '/');
        $ref = preg_replace(["/\\s+$quoted$/", "/^.*?$quoted /"], '', $ref);

        $colour = isset($routes['route_color']) ? (string) $routes['route_color'] : 'FFFFFF';
        $textColour = isset($routes['route_text_color']) ? (string) $routes['route_text_color'] : '000000';

        $fromName = $this->stopName($firstStop);
        $toName = $this->stopName($lastStop);

        $masterName = $vehicle . ' ' . $ref;
        if (isset($osm['route_master_name_suggestion'])) {
            $masterName = $this->nameSuggestion((string) $osm['route_master_name_suggestion'], $masterName, $vehicle, $routes, $tripRow);
        }
        $routeName = $vehicle . ' ' . $ref . ': ' . $fromName['text'] . ' => ' . $toName['text'];
        if (isset($osm['route_name_suggestion'])) {
            $routeName = $this->nameSuggestion((string) $osm['route_name_suggestion'], $routeName, $vehicle, $routes, $tripRow);
        }

        $network = (string) ($osm['network'] ?? '');
        if (preg_match('/[{}]/', $network)) {
            $network = $this->networkSuggestion($network, $agency, $routes, $tripRow);
        }

        $operator = '';
        $website = '';
        if (!empty($osm['gtfs_agency_is_operator']) && isset($agency['agency_name']) && $agency['agency_name'] !== 'Sonstige') {
            $operator = (string) $agency['agency_name'];
            $website = isset($routes['route_url']) ? (string) $routes['route_url'] : (string) ($agency['agency_url'] ?? '');
        }

        $routeId = (string) $routes['route_id'];
        $shapeId = isset($tripRow['shape_id']) ? (string) $tripRow['shape_id'] : '';
        $explicitFeed = isset($osm['gtfs_explicit_feed_name']) && $osm['gtfs_explicit_feed_name'] != '0';
        $suffix = $explicitFeed ? '' : ':' . $feed;

        $common = [];
        if ($network !== '') {
            $common[] = new OsmTag('network', $network, 'text');
        }
        if (($osm['network_guid'] ?? '') !== '') {
            $common[] = new OsmTag('network:guid', (string) $osm['network_guid']);
        }
        if (($osm['network_short'] ?? '') !== '') {
            $common[] = new OsmTag('network:short', (string) $osm['network_short']);
        }
        if ($operator !== '') {
            $common[] = new OsmTag('operator', $operator, 'text');
        }
        if ($colour !== '') {
            $common[] = new OsmTag('colour', '#' . $colour);
        }
        if ($textColour !== '') {
            $common[] = new OsmTag('colour:text', '#' . $textColour);
        }
        if ($website !== '') {
            $common[] = new OsmTag('website', $website, 'text');
        }

        $gtfs = [];
        if ($explicitFeed) {
            $gtfs[] = new OsmTag('gtfs:feed', $feed);
        }
        if ($routeId !== '') {
            $gtfs[] = new OsmTag('gtfs:route_id' . $suffix, $routeId);
        }

        $tripTags = [new OsmTag('gtfs:trip_id:sample' . $suffix, $tripId)];
        $like = $this->tripIdLike((string) ($osm['trip_id_regex'] ?? ''), $tripId);
        if ($like !== '') {
            $tripTags[] = new OsmTag('gtfs:trip_id:like' . $suffix, $like);
        }
        if ($shapeId !== '') {
            $tripTags[] = new OsmTag('gtfs:shape_id' . $suffix, $shapeId);
        }

        $master = [new OsmTag('type', 'route_master'), new OsmTag('route_master', $osmRoute), new OsmTag('ref', $ref)];
        if ($masterName !== '') {
            $master[] = new OsmTag('name', $masterName, 'text');
        }

        $route = [new OsmTag('type', 'route'), new OsmTag('route', $osmRoute), new OsmTag('ref', $ref)];
        if ($routeName !== '') {
            $route[] = new OsmTag('name', $routeName, 'text');
        }

        $end = [new OsmTag('ref_trips', $tripId)];
        if ($fromName['text'] !== '') {
            $end[] = new OsmTag('from', $fromName['text'], 'text', $fromName['title']);
        }
        if ($toName['text'] !== '') {
            $end[] = new OsmTag('to', $toName['text'], 'text', $toName['title']);
        }
        if ($fromName['text'] !== '' && $fromName['text'] === $toName['text']) {
            $end[] = new OsmTag('roundtrip', 'yes');
        }
        $end[] = new OsmTag('public_transport:version', '2');

        return new OsmTaggingSuggestion(
            [...$master, ...$common, ...$gtfs],
            [...$route, ...$common, ...$gtfs, ...$tripTags, ...$end],
        );
    }

    /**
     * @param array<string, mixed> $stop
     *
     * @return array{text: string, title: string|null} the normalized name with the original GTFS name as title, if there is one
     */
    private function stopName(array $stop): array
    {
        if (!isset($stop['stop_name'])) {
            return ['text' => '', 'title' => null];
        }
        if (($stop['normalized_stop_name'] ?? '') !== '') {
            return ['text' => (string) $stop['normalized_stop_name'], 'title' => "GTFS: stop_name='" . $stop['stop_name'] . "'"];
        }

        return ['text' => (string) $stop['stop_name'], 'title' => null];
    }

    /**
     * @param array<string, mixed> $routes
     * @param array<string, mixed> $trips
     */
    private function nameSuggestion(string $suggestion, string $default, string $vehicle, array $routes, array $trips): string
    {
        if ($suggestion === '') {
            return '';
        }
        if ($suggestion === 'PTv2') {
            return $default;
        }

        return preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($vehicle, $routes, $trips): string {
            return match (true) {
                $m[1] === 'osm_vehicle' => $vehicle,
                isset($routes[$m[1]]) => (string) $routes[$m[1]],
                isset($trips[$m[1]]) => (string) $trips[$m[1]],
                default => '[' . $m[1] . ']',
            };
        }, $suggestion);
    }

    /**
     * @param array<string, mixed> $agency
     * @param array<string, mixed> $routes
     * @param array<string, mixed> $trips
     */
    private function networkSuggestion(string $suggestion, array $agency, array $routes, array $trips): string
    {
        $shorten = str_contains($suggestion, '/ (.*)//');

        return preg_replace_callback('/\{([^}]+)\}(\/ \(\.\*\)\/\/)?/', static function (array $m) use ($agency, $routes, $trips, $shorten): string {
            $key = $m[1];
            if (isset($agency[$key])) {
                return $shorten ? preg_replace('/ \(.*\)/', '', (string) $agency[$key]) : (string) $agency[$key] . ($m[2] ?? '');
            }

            return isset($routes[$key]) ? (string) $routes[$key] . ($m[2] ?? '') : (isset($trips[$key]) ? (string) $trips[$key] . ($m[2] ?? '') : '[' . $key . ']' . ($m[2] ?? ''));
        }, $suggestion);
    }

    private function tripIdLike(string $regex, string $tripId): string
    {
        if ($regex === '' || !@preg_match('/^' . $regex . '$/', $tripId)) {
            return '';
        }

        $like = (string) @preg_replace('/' . $regex . '/', '\1', $tripId);
        if (!str_starts_with($regex, '(')) {
            $like = '%' . $like;
        }
        if (!str_ends_with($regex, ')$')) {
            $like .= '%';
        }

        return $like;
    }
}
