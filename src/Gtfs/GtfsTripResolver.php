<?php

namespace App\Gtfs;

/**
 * Finds the representative trip of a trip_id: identical trips are aggregated in 'ptna_trips',
 * all trip ids of such a group are listed in 'list_trip_ids'.
 */
final class GtfsTripResolver
{
    /**
     * @return string|null the trip_id of the representative trip, null if there is no such trip
     */
    public function representativeTripId(GtfsDatabase $db, string $tripId): ?string
    {
        if ($tripId === '') {
            return null;
        }

        $connection = $db->connection();

        if (!$db->hasTable('ptna_trips')) {
            $found = $connection->fetchOne('SELECT trip_id FROM trips WHERE trip_id = :id LIMIT 1', ['id' => $tripId]);

            return $found === false ? null : (string) $found;
        }

        $group = $this->tripGroup($db, $tripId);

        return $group === null ? null : (string) $group['trip_id'];
    }

    /**
     * @return array{trip_id: string, list_trip_ids: string, list_service_ids: string}|null the aggregated trip which contains the trip_id
     */
    public function tripGroup(GtfsDatabase $db, string $tripId): ?array
    {
        if ($tripId === '' || !$db->hasTable('ptna_trips')) {
            return null;
        }

        $separator = $this->escapeLike($db->listSeparator());
        $id = $this->escapeLike($tripId);

        $row = $db->connection()->fetchAssociative(
            "SELECT trip_id, list_trip_ids, list_service_ids FROM ptna_trips
             WHERE trip_id = :id
                OR list_trip_ids LIKE :first ESCAPE '\\'
                OR list_trip_ids LIKE :middle ESCAPE '\\'
                OR list_trip_ids LIKE :last ESCAPE '\\'
             LIMIT 1",
            ['id' => $tripId, 'first' => $id . $separator . '%', 'middle' => '%' . $separator . $id . $separator . '%', 'last' => '%' . $separator . $id],
        );

        return $row === false ? null : ['trip_id' => (string) $row['trip_id'], 'list_trip_ids' => (string) ($row['list_trip_ids'] ?? ''), 'list_service_ids' => (string) ($row['list_service_ids'] ?? '')];
    }

    /**
     * @return array<string, mixed>|null the columns of the trip joined with its PTNA comments
     */
    public function tripRow(GtfsDatabase $db, string $representativeTripId): ?array
    {
        $join = $db->hasTable('ptna_trips_comments') ? 'LEFT OUTER JOIN ptna_trips_comments ON trips.trip_id = ptna_trips_comments.trip_id' : '';

        // the columns of trips must win over the ones of the comments on name clashes
        $columns = $join !== '' ? 'ptna_trips_comments.*, trips.*' : 'trips.*';
        $row = $db->connection()->fetchAssociative("SELECT $columns FROM trips $join WHERE trips.trip_id = :id", ['id' => $representativeTripId]);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null the columns of the route and of its PTNA comments
     */
    public function routeDetails(GtfsDatabase $db, string $routeId): ?array
    {
        if ($routeId === '') {
            return null;
        }

        $join = $db->hasTable('ptna_routes_comments') ? 'LEFT OUTER JOIN ptna_routes_comments ON routes.route_id = ptna_routes_comments.route_id' : '';
        // ptna_routes_comments.route_id can be empty: the columns of routes must win on name clashes
        $columns = $join !== '' ? 'ptna_routes_comments.*, routes.*' : 'routes.*';
        $row = $db->connection()->fetchAssociative("SELECT $columns FROM routes $join WHERE routes.route_id = :id", ['id' => $routeId]);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null the columns of the route of the trip
     */
    public function routeOfTrip(GtfsDatabase $db, string $representativeTripId): ?array
    {
        $row = $db->connection()->fetchAssociative(
            'SELECT routes.* FROM routes JOIN trips ON trips.route_id = routes.route_id WHERE trips.trip_id = :id LIMIT 1',
            ['id' => $representativeTripId],
        );

        return $row === false ? null : $row;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
