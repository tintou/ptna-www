<?php

namespace App\Gtfs;

use Doctrine\DBAL\Connection;

/**
 * Read access to one PTNA GTFS SQLite database (one feed, one release).
 * The schema is created by the external PTNA GTFS import, there is no ORM mapping for it.
 */
final class GtfsDatabase
{
    /** @var array<string, bool> */
    private array $tables = [];
    /** @var array<string, array<string, mixed>> */
    private array $singleRows = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (bool) $this->connection->fetchOne(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table],
        );
    }

    /**
     * @return array<string, mixed> the first row of a single-row table, empty if the table is missing or empty
     */
    public function singleRow(string $table): array
    {
        if (!in_array($table, ['ptna', 'osm', 'feed_info'], true) || !$this->hasTable($table)) {
            return [];
        }

        return $this->connection->fetchAssociative("SELECT * FROM $table") ?: [];
    }

    /**
     * @return array<string, mixed> the single row of the 'ptna' table, empty if missing
     */
    public function ptna(): array
    {
        return $this->singleRows['ptna'] ??= $this->singleRow('ptna');
    }

    /**
     * @return array<string, mixed>
     */
    public function osm(): array
    {
        return $this->singleRows['osm'] ??= $this->singleRow('osm');
    }

    /**
     * @return array<string, mixed>
     */
    public function feedInfo(): array
    {
        return $this->singleRow('feed_info');
    }

    public function listSeparator(): string
    {
        $separator = $this->ptna()['list_separator'] ?? '';

        return $separator !== '' ? $separator : '|';
    }

    /**
     * @return list<GtfsRouteType>
     */
    public function routeTypes(): array
    {
        if (!$this->hasTable('gtfs_route_types')) {
            return [];
        }

        return array_map(
            static fn (array $r) => new GtfsRouteType((int) $r['route_type'], (string) $r['string'], $r['sort_key'] ?? null, $r['osm_route'] ?? null),
            $this->connection->fetchAllAssociative('SELECT * FROM gtfs_route_types'),
        );
    }

    /**
     * @return array<string, array<string, string>> OSM route => language => name, for 'en' and 'de'
     */
    public function osmRouteNames(): array
    {
        if (!$this->hasTable('osm_routes')) {
            return [];
        }

        $names = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM osm_routes') as $r) {
            $names[$r['osm_route']] = ['en' => (string) $r['string'], 'de' => (string) ($r['string_de'] ?? $r['string'])];
        }

        return $names;
    }

    /**
     * @return list<GtfsRoute>
     */
    public function routes(): array
    {
        // ptna_routes first: its route_id can be empty, the columns of routes/agency must win on name clashes
        $sql = $this->hasTable('ptna_routes')
            ? 'SELECT DISTINCT ptna_routes.*, routes.*, agency.*
               FROM routes
               LEFT OUTER JOIN ptna_routes ON routes.route_id = ptna_routes.route_id
               JOIN agency ON routes.agency_id = agency.agency_id'
            : 'SELECT DISTINCT routes.*, agency.*
               FROM routes
               JOIN agency ON routes.agency_id = agency.agency_id';

        return array_map(GtfsRoute::fromRow(...), $this->connection->fetchAllAssociative($sql));
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
