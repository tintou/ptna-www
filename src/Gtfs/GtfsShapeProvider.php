<?php

namespace App\Gtfs;

/**
 * The shapes of a feed release and the trips following them.
 */
final class GtfsShapeProvider
{
    /**
     * @param bool $useSequence number the points by their shape_pt_sequence instead of counting them
     *
     * @return list<GtfsShapePoint>
     */
    public function points(GtfsDatabase $db, string $shapeId, bool $useSequence): array
    {
        if ($shapeId === '' || !($db->ptna()['has_shapes'] ?? false)) {
            return [];
        }

        $rows = $db->connection()->fetchAllAssociative(
            'SELECT * FROM shapes WHERE shape_id = :id ORDER BY CAST(shape_pt_sequence AS INTEGER) ASC',
            ['id' => $shapeId],
        );

        $points = [];
        foreach ($rows as $counter => $row) {
            $points[] = new GtfsShapePoint(
                $useSequence && isset($row['shape_pt_sequence']) ? (string) $row['shape_pt_sequence'] : (string) ($counter + 1),
                isset($row['shape_pt_lat']) ? (string) $row['shape_pt_lat'] : null,
                isset($row['shape_pt_lon']) ? (string) $row['shape_pt_lon'] : null,
                isset($row['shape_dist_traveled']) ? (string) $row['shape_dist_traveled'] : null,
            );
        }

        return $points;
    }

    /**
     * @return list<GtfsShapeTrip>
     */
    public function tripsOfShape(GtfsDatabase $db, string $shapeId): array
    {
        if ($shapeId === '' || !($db->ptna()['has_shapes'] ?? false)) {
            return [];
        }

        $rows = $db->connection()->fetchAllAssociative(
            'SELECT trips.*, routes.route_short_name
             FROM trips JOIN routes ON routes.route_id = trips.route_id
             WHERE trips.shape_id = :id
             ORDER BY routes.route_short_name ASC, trips.trip_id DESC',
            ['id' => $shapeId],
        );

        return array_map(static fn (array $row) => new GtfsShapeTrip(
            (string) $row['trip_id'],
            isset($row['route_short_name']) ? (string) $row['route_short_name'] : '????',
            ($row['trip_short_name'] ?? '') !== '' ? (string) $row['trip_short_name'] : null,
            ($row['trip_headsign'] ?? '') !== '' ? (string) $row['trip_headsign'] : null,
        ), $rows);
    }
}
