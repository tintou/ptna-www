<?php

namespace App\Gtfs;

use Doctrine\DBAL\Exception as DbalException;

/**
 * Data behind the pages 'GTFS Details' and 'GTFS Analysis Details' of one feed release.
 */
final class GtfsDetailsProvider
{
    public const TOPICS = [
        'SUBR' => ['subroute_of', 'subroutes'],
        'IDENT' => ['same_names_but_different_ids', 'identical_names'],
        'DIFFSHAPES' => ['same_stops_but_different_shape_ids', 'different_shapes'],
        'SUSPSTART' => ['suspicious_start', 'suspicious_start'],
        'SUSPEND' => ['suspicious_end', 'suspicious_end'],
        'SUSPCOUNT' => ['suspicious_number_of_stops', 'suspicious_count'],
        'TIME' => ['suspicious_trip_duration', 'suspicious_time'],
        'OTHER' => ['suspicious_other', 'suspicious_other'],
    ];

    private const AGGREGATION_COUNTS = ['routes', 'trips', 'stops', 'stop_times', 'shapes'];

    public function __construct(private readonly GtfsDatabaseFactory $databases)
    {
    }

    /**
     * @return array{
     *     ptna: array<string, mixed>,
     *     feedInfo: array<string, mixed>,
     *     osm: list<array<string, mixed>>,
     *     aggregation: list<array<string, mixed>>,
     *     analysis: list<array<string, mixed>>,
     *     normalization: list<array<string, mixed>>,
     *     duration: float
     * }|null null if there is no database for the feed release
     */
    public function details(string $feed, string $release): ?array
    {
        $db = $this->databases->open($feed, $release);
        if ($db === null) {
            return null;
        }

        $start = microtime(true);
        try {
            $ptna = $db->ptna();

            return [
                'ptna' => $ptna,
                'feedInfo' => $db->feedInfo(),
                'osm' => $this->osmRows($db),
                'aggregation' => $this->aggregationRows($db),
                'analysis' => $this->analysisRows($db, $ptna),
                'normalization' => $this->normalizationRows($db),
                'duration' => microtime(true) - $start,
            ];
        } finally {
            $db->close();
        }
    }

    /**
     * @param string $topic a key of self::TOPICS or '' for all topics
     *
     * @return array{rows: list<array<string, mixed>>, duration: float}|null null if there is no database for the feed release
     */
    public function analysisDetails(string $feed, string $release, string $topic): ?array
    {
        $db = $this->databases->open($feed, $release);
        if ($db === null) {
            return null;
        }

        $start = microtime(true);
        try {
            $rows = [];
            if ($db->hasTable('ptna_trips_comments')) {
                $existing = array_column($db->connection()->fetchAllAssociative('PRAGMA table_info(ptna_trips_comments)'), 'name');
                $columns = [];
                foreach (self::TOPICS as $key => [$column]) {
                    if (in_array($column, $existing, true) && ($topic === '' || $topic === $key)) {
                        $columns[] = $column;
                    }
                }

                if ($columns !== []) {
                    // only column names from the constant list above end up in the statement
                    $sql = sprintf(
                        "SELECT routes.route_id, routes.route_short_name, ptna_trips_comments.trip_id, %s
                         FROM ptna_trips_comments
                         JOIN trips ON ptna_trips_comments.trip_id = trips.trip_id
                         JOIN routes ON trips.route_id = routes.route_id
                         WHERE %s
                         ORDER BY CASE WHEN routes.route_short_name GLOB '[^0-9]*' THEN routes.route_short_name ELSE CAST(routes.route_short_name AS INTEGER) END",
                        implode(', ', array_map(static fn (string $c) => 'ptna_trips_comments.' . $c, $columns)),
                        implode(' OR ', array_map(static fn (string $c) => "ptna_trips_comments.$c != ''", $columns)),
                    );
                    $rows = $db->connection()->fetchAllAssociative($sql);
                }
            }

            return ['rows' => $rows, 'duration' => microtime(true) - $start];
        } catch (DbalException) {
            return ['rows' => [], 'duration' => microtime(true) - $start];
        } finally {
            $db->close();
        }
    }

    /**
     * @return list<array<string, mixed>> rows with 'label' (key in the domain gtfs_details), and a 'text', 'trans' or 'check' value
     */
    private function osmRows(GtfsDatabase $db): array
    {
        if (!$db->hasTable('osm')) {
            return [];
        }
        $osm = $db->osm();

        $rows = [
            ['label' => 'network', 'text' => (string) ($osm['network'] ?? '')],
            ['label' => 'network_short', 'text' => (string) ($osm['network_short'] ?? '')],
            ['label' => 'network_guid', 'text' => (string) ($osm['network_guid'] ?? '')],
            ['label' => 'agency_is_operator', 'check' => !empty($osm['gtfs_agency_is_operator'])],
            ['label' => 'trip_id_regex', 'text' => (string) ($osm['trip_id_regex'] ?? '')],
            ['label' => 'short_name_hack', 'check' => !empty($osm['gtfs_short_name_hack1'])],
            ['label' => 'ptna_analysis', 'text' => (string) ($osm['ptna_analysis'] ?? '')],
        ];

        foreach (['route_master_name_suggestion' => 'route_master_name', 'route_name_suggestion' => 'route_name'] as $column => $label) {
            if (!isset($osm[$column])) {
                continue;
            }
            $rows[] = match ((string) $osm[$column]) {
                '' => ['label' => $label, 'trans' => 'osm.no_suggestion'],
                'PTv2' => ['label' => $label, 'trans' => 'osm.ptv2'],
                default => ['label' => $label, 'text' => (string) $osm[$column]],
            };
        }

        if (isset($osm['stop_name_delimiter'])) {
            $rows[] = $osm['stop_name_delimiter'] === ''
                ? ['label' => 'stop_name_delimiter', 'trans' => 'osm.disabled_none']
                : ['label' => 'stop_name_delimiter', 'text' => (string) $osm['stop_name_delimiter']];
        } else {
            $rows[] = ['label' => 'stop_name_delimiter', 'trans' => 'osm.default', 'params' => ['%value%' => ',']];
        }

        foreach (['ddiff' => 100, 'wdiff' => 15, 'wn' => 2] as $column => $default) {
            $value = $osm[$column] ?? '';
            if ($value === '' || $value === null) {
                $rows[] = ['label' => $column, 'trans' => 'osm.default', 'params' => ['%value%' => (string) $default]];
            } elseif ((float) $value === 0.0) {
                $rows[] = ['label' => $column, 'trans' => 'osm.disabled_zero'];
            } else {
                $rows[] = ['label' => $column, 'text' => (string) $value];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregationRows(GtfsDatabase $db): array
    {
        if (!$db->hasTable('ptna_aggregation')) {
            return [];
        }
        $row = $db->connection()->fetchAssociative('SELECT * FROM ptna_aggregation') ?: [];

        $rows = $this->dateAndDuration($row);
        foreach (['before', 'after'] as $when) {
            if (isset($row['size_' . $when])) {
                $rows[] = ['label' => 'db_size_' . $when, 'value' => sprintf('%.2f', (float) $row['size_' . $when] / 1024 / 1024), 'unit' => 'MB'];
            }
        }
        foreach (self::AGGREGATION_COUNTS as $column) {
            foreach (['before', 'after'] as $when) {
                // shape data is only listed if both counts are known
                if (isset($row[$column . '_' . $when]) && ($column !== 'shapes' || isset($row['shapes_before'], $row['shapes_after']))) {
                    $rows[] = ['label' => 'count_' . $column . '_' . $when, 'value' => (string) (int) $row[$column . '_' . $when], 'unit' => '1'];
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $ptna
     *
     * @return list<array<string, mixed>>
     */
    private function analysisRows(GtfsDatabase $db, array $ptna): array
    {
        if (!$db->hasTable('ptna_analysis')) {
            return [];
        }
        $connection = $db->connection();
        $rows = $this->dateAndDuration($connection->fetchAssociative('SELECT * FROM ptna_analysis') ?: []);

        if (!$db->hasTable('ptna_trips_comments')) {
            return $rows;
        }

        $columns = array_column($connection->fetchAllAssociative('PRAGMA table_info(ptna_trips_comments)'), 'name');

        if (isset($ptna['IFOPT_cmp_len'])) {
            $rows[] = ['label' => 'ifopt_cmp_len', 'value' => (string) $ptna['IFOPT_cmp_len'], 'unit' => '1'];
        }

        foreach (self::TOPICS as $topic => [$column, $label]) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $count = (int) $connection->fetchOne("SELECT COUNT(*) FROM ptna_trips_comments WHERE $column != ''");
            if ($count > 0) {
                $rows[] = ['label' => 'analysis_' . $label, 'value' => (string) $count, 'unit' => '1', 'topic' => $topic];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizationRows(GtfsDatabase $db): array
    {
        if (!$db->hasTable('ptna_normalization')) {
            return [];
        }
        $row = $db->connection()->fetchAssociative('SELECT * FROM ptna_normalization') ?: [];

        $rows = $this->dateAndDuration($row);
        foreach (['routes', 'stops'] as $column) {
            if (isset($row[$column])) {
                $rows[] = ['label' => 'count_' . $column, 'value' => (string) $row[$column], 'unit' => '1'];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    private function dateAndDuration(array $row): array
    {
        $rows = [];
        if (!empty($row['date'])) {
            $rows[] = ['label' => 'date', 'value' => (string) $row['date'], 'unit' => 'YYYY-MM-DD'];
        }
        if (isset($row['duration']) && $row['duration'] !== '') {
            $rows[] = ['label' => 'duration', 'value' => $this->formatDuration((int) $row['duration']), 'unit' => 'hh:mm:ss'];
        }

        return $rows;
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return match (true) {
            $hours > 0 => sprintf('%d:%02d:%02d', $hours, $minutes, $secs),
            $minutes > 0 => sprintf('%d:%02d', $minutes, $secs),
            default => (string) $secs,
        };
    }
}
