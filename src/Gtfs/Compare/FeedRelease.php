<?php

namespace App\Gtfs\Compare;

final readonly class FeedRelease
{
    public function __construct(
        public string $date,
        public string $publisherName,
        public string $startDate,
        public string $endDate,
        public string $version,
    ) {
    }
}
