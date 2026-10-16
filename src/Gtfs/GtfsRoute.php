<?php

namespace App\Gtfs;

final readonly class GtfsRoute
{
    /**
     * @param array<string, mixed> $columns all columns of the joined routes/ptna_routes/agency row
     */
    public function __construct(
        public string $id,
        public string $shortName,
        public string $longName,
        public int $type,
        public ?string $agencyId,
        public ?string $agencyName,
        public array $columns,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['route_id'],
            (string) ($row['route_short_name'] ?? ''),
            (string) ($row['route_long_name'] ?? ''),
            (int) ($row['route_type'] ?? 0),
            isset($row['agency_id']) ? (string) $row['agency_id'] : null,
            isset($row['agency_name']) ? (string) $row['agency_name'] : null,
            $row,
        );
    }
}
