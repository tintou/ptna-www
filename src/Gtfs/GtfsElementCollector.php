<?php

namespace App\Gtfs;

/**
 * Builds the Overpass-like 'elements' of the GTFS API: stops and shape points as nodes,
 * shapes as ways, trips and routes as relations. One instance per request.
 */
final class GtfsElementCollector
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];
    /** @var array<string, array<string, mixed>> */
    private array $ways = [];
    /** @var array<string, array<string, mixed>> */
    private array $relations = [];
    /** @var array<string, mixed>|null */
    private ?array $agency = null;

    public function __construct(
        private readonly GtfsDatabase $db,
        private readonly GtfsTripResolver $resolver,
        private readonly bool $ptna,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function elements(): array
    {
        return [...array_values($this->nodes), ...array_values($this->ways), ...array_values($this->relations)];
    }

    /**
     * @return array<string, mixed>|null agency of the last route/trip added, null if none was asked for
     */
    public function agency(): ?array
    {
        return $this->agency;
    }

    public function addRoute(string $routeId): void
    {
        $this->addRouteOnly($routeId);

        $route = $this->relations[$routeId] ?? null;
        if (($route['tags']['type'] ?? null) === 'route') {
            foreach ($route['members'] ?? [] as $member) {
                if (isset($member['ref']) && ($member['type'] ?? null) === 'relation') {
                    $this->addTrip((string) $member['ref']);
                }
            }
        }

        $this->addAgencyOfRoute($routeId);
    }

    public function addTripWithAgency(string $tripId): void
    {
        $this->addTrip($tripId);

        $routeId = $this->relations[$tripId]['tags']['route_id'] ?? null;
        if ($routeId) {
            $this->addAgencyOfRoute((string) $routeId);
        }
    }

    private function addAgencyOfRoute(string $routeId): void
    {
        $this->agency = [];

        $agencyId = $this->relations[$routeId]['tags']['agency_id'] ?? null;
        if ($agencyId) {
            $this->agency = $this->db->connection()->fetchAssociative('SELECT * FROM agency WHERE agency_id = :id LIMIT 1', ['id' => $agencyId]) ?: [];
        }
    }

    private function addTrip(string $tripId): void
    {
        $db = $this->db->connection();
        $listSeparator = $this->db->listSeparator();
        $repTripId = $tripId;
        $serviceId = '';

        $group = $this->resolver->tripGroup($this->db, $tripId);
        if ($group !== null) {
            $repTripId = $group['trip_id'];
            if ($group['list_trip_ids'] && $group['list_service_ids']) {
                // the service_id which fits to this trip_id (not to the representative one)
                $index = array_search($tripId, explode($listSeparator, $group['list_trip_ids']));
                $serviceId = $index !== false ? (explode($listSeparator, $group['list_service_ids'])[$index] ?? '') : '';
            }
        }

        $members = [];
        $stops = $db->fetchAllAssociative(
            'SELECT stops.* FROM stops
             JOIN stop_times ON stop_times.stop_id = stops.stop_id
             WHERE stop_times.trip_id = :trip
             ORDER BY CAST(stop_times.stop_sequence AS INTEGER) ASC',
            ['trip' => $repTripId],
        );
        foreach ($stops as $stop) {
            if (!isset($stop['stop_id'], $stop['stop_lat'], $stop['stop_lon'])
                || !(!isset($stop['location_type']) || $stop['location_type'] == '' || $stop['location_type'] == 0)) {
                continue;
            }
            $members[] = ['ref' => $stop['stop_id'], 'role' => 'stop', 'type' => 'node'];
            if (!isset($this->nodes[$stop['stop_id']])) {
                $node = ['type' => 'node', 'id' => $stop['stop_id'], 'lat' => $stop['stop_lat'], 'lon' => $stop['stop_lon'], 'tags' => $stop];
                if ($this->ptna && $this->db->hasTable('ptna_stops')) {
                    $ptna = $this->ptnaStop($stop['stop_id']);
                    if ($ptna) {
                        $node['ptna'] = $ptna;
                    }
                }
                $this->nodes[$stop['stop_id']] = $node;
            }
        }

        if (!$members) {
            return;
        }

        $tags = ['type' => 'trip'];
        $shapeId = '';
        $routeId = '';
        foreach ($db->fetchAllAssociative('SELECT * FROM trips WHERE trip_id = :trip', ['trip' => $repTripId]) as $trip) {
            foreach ($trip as $key => $value) {
                if ($key === 'trip_id') {
                    $tags['trip_id'] = $tripId;
                } elseif ($key === 'service_id') {
                    if ($serviceId) {
                        $tags['service_id'] = $serviceId;
                    }
                } elseif ($value) {
                    $tags[$key] = $value;
                }
                if ($key === 'shape_id') {
                    $shapeId = $value;
                }
                if ($key === 'route_id') {
                    $routeId = $value;
                }
            }
        }

        if ($shapeId) {
            $members = [...$members, ...$this->addShape((string) $shapeId)];
        }

        if (!isset($this->relations[$tripId])) {
            $relation = ['type' => 'relation', 'id' => $tripId, 'members' => $members, 'tags' => $tags];
            if ($this->ptna && $this->db->hasTable('ptna_trips') && $this->db->hasTable('ptna_trips_comments')) {
                $ptna = $this->ptnaTrip($repTripId);
                if ($ptna) {
                    $relation['ptna'] = $ptna;
                }
            }
            $this->relations[$tripId] = $relation;
        }

        if ($routeId !== '' && $routeId !== null) {
            $this->addRouteOnly((string) $routeId);
        }
    }

    /**
     * @return list<array<string, string>> the way member of the shape, empty if the shape has no points
     */
    private function addShape(string $shapeId): array
    {
        $points = $this->db->connection()->fetchAllAssociative(
            'SELECT * FROM shapes WHERE shape_id = :shape ORDER BY CAST(shape_pt_sequence AS INTEGER) ASC',
            ['shape' => $shapeId],
        );

        $nodeIds = [];
        foreach ($points as $point) {
            if (!isset($point['shape_pt_lat'], $point['shape_pt_lon'], $point['shape_pt_sequence'])) {
                continue;
            }
            $id = $shapeId . '-' . $point['shape_pt_sequence'];
            $this->nodes[$id] ??= ['type' => 'node', 'id' => $id, 'lat' => $point['shape_pt_lat'], 'lon' => $point['shape_pt_lon']];
            $nodeIds[] = $id;
        }

        if (!$nodeIds) {
            return [];
        }

        $this->ways[$shapeId] ??= ['type' => 'way', 'id' => $shapeId, 'nodes' => $nodeIds];

        return [['ref' => $shapeId, 'role' => '', 'type' => 'way']];
    }

    private function addRouteOnly(string $routeId): void
    {
        if (isset($this->relations[$routeId])) {
            return;
        }

        $db = $this->db->connection();

        $members = [];
        foreach ($db->fetchFirstColumn('SELECT trip_id FROM trips WHERE route_id = :route', ['route' => $routeId]) as $tripId) {
            $members[] = ['ref' => $tripId, 'role' => '', 'type' => 'relation'];
        }

        $tags = [];
        foreach ($db->fetchAllAssociative('SELECT * FROM routes WHERE route_id = :route', ['route' => $routeId]) as $route) {
            $tags = array_merge($tags, $route);
        }

        if ($members && $tags) {
            $tags['type'] = 'route';
            $this->relations[$routeId] = ['type' => 'relation', 'id' => $routeId, 'members' => $members, 'tags' => $tags];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ptnaStop(string $stopId): array
    {
        $ptna = [];
        foreach ($this->db->connection()->fetchAllAssociative('SELECT * FROM ptna_stops WHERE stop_id = :id', ['id' => $stopId]) as $row) {
            foreach ($row as $key => $value) {
                if ($key !== 'stop_id' && $value) {
                    $ptna[$key === 'normalized_stop_name' ? 'stop_name' : $key] = $value;
                }
            }
        }

        return $ptna;
    }

    /**
     * @return array<string, mixed>
     */
    private function ptnaTrip(string $tripId): array
    {
        $ptna = [];
        $rows = $this->db->connection()->fetchAllAssociative(
            'SELECT ptna_trips_comments.*, ptna_trips.rides, ptna_trips.min_start_date, ptna_trips.max_end_date
             FROM ptna_trips
             LEFT JOIN ptna_trips_comments ON ptna_trips.trip_id = ptna_trips_comments.trip_id
             WHERE ptna_trips.trip_id = :trip',
            ['trip' => $tripId],
        );
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if ($key !== 'trip_id' && $value) {
                    $ptna[$key] = $value;
                }
            }
        }

        return $ptna;
    }
}
