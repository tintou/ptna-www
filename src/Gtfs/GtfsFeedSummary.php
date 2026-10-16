<?php

namespace App\Gtfs;

/**
 * One row of a country table: what is known about the current release of a feed.
 * A null $ptna means that no database is available (or it could not be read).
 */
final readonly class GtfsFeedSummary
{
    /**
     * @param array<string, mixed>|null $ptna
     * @param array<string, mixed>      $feedInfo
     * @param array<string, mixed>|null $longTerm ptna row of the long-term release
     * @param array<string, mixed>|null $previous ptna row of the previous release
     */
    public function __construct(
        public string $feed,
        public ?array $ptna,
        public array $feedInfo = [],
        public ?array $longTerm = null,
        public ?array $previous = null,
        public ?string $error = null,
    ) {
    }

    public static function missing(string $feed): self
    {
        return new self($feed, null);
    }

    public static function failed(string $feed, string $error): self
    {
        return new self($feed, null, error: $error);
    }
}
