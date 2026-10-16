<?php

namespace App\Gtfs\Compare;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * The query parameters of the compare pages: a left (no suffix) and a right ('2' suffix) side.
 */
final readonly class CompareParameters
{
    private const NAME_PATTERN = '/^[0-9A-Za-z_.-]+$/';

    public function __construct(
        public string $feed,
        public string $feed2,
        public string $releaseDate,
        public string $releaseDate2,
        public string $routeId,
        public string $routeId2,
        public string $tripId,
        public string $tripId2,
        public string $shapeId,
        public string $shapeId2,
        public string $osmRelation,
    ) {
    }

    /**
     * @param bool $inheritLeft right side values that are empty fall back to the left side (feed, route),
     *                          as the pages showing the comparison do; the input form must not do this
     */
    public static function fromQuery(InputBag $query, bool $inheritLeft = true): self
    {
        $text = static fn (string $name): string => is_string($query->all()[$name] ?? null) ? $query->all()[$name] : '';
        $name = static fn (string $key): string => preg_match(self::NAME_PATTERN, $text($key)) ? $text($key) : '';
        // 'latest' is the feed without a release date
        $release = static fn (string $key): string => $name($key) === 'latest' ? '' : $name($key);

        $feed = $name('feed');
        $releaseDate = $release('release_date');
        if ($feed === '') {
            // the legacy parameter 'network' is '<feed>[-<release date>]'
            $network = $name('network');
            if (preg_match('/^(.*?)-(previous|long-term|\d{4}-\d{2}-\d{2})(?:-.*)?$/', $network, $m)) {
                $feed = $m[1];
                $releaseDate = $m[2];
            } else {
                $feed = $network;
            }
        }

        $feed2 = $name('feed2');
        $routeId2 = $text('route_id2');
        if ($inheritLeft) {
            $feed2 = $feed2 !== '' ? $feed2 : $feed;
            $routeId2 = $routeId2 !== '' ? $routeId2 : $text('route_id');
        }

        return new self(
            $feed,
            $feed2,
            $releaseDate,
            $release('release_date2'),
            $text('route_id'),
            $routeId2,
            $text('trip_id'),
            $text('trip_id2'),
            $text('shape_id'),
            $text('shape_id2'),
            $text('relation_id') !== '' ? $text('relation_id') : $text('relation'),
        );
    }

    public function hasOsmRelation(): bool
    {
        return $this->osmRelation !== '';
    }
}
