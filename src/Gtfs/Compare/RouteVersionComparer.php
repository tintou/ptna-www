<?php

namespace App\Gtfs\Compare;

use App\Gtfs\GtfsDatabase;
use App\Gtfs\GtfsDatabaseFactory;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Lines up the routes of two feed versions by their sort key.
 */
final class RouteVersionComparer
{
    public function __construct(
        private readonly GtfsDatabaseFactory $databases,
        private readonly RouteSummaryProvider $summaries,
    ) {
    }

    /**
     * @return list<RouteComparisonRow>|null null if one of the databases does not exist
     *
     * @throws DbalException
     */
    public function compare(string $feed, string $releaseDate, string $feed2, string $releaseDate2): ?array
    {
        $left = $this->databases->open($feed, $releaseDate);
        $right = ($feed === $feed2 && $releaseDate === $releaseDate2) ? $left : $this->databases->open($feed2, $releaseDate2);

        if ($left === null || $right === null) {
            $left?->close();
            $right?->close();

            return null;
        }

        try {
            $leftRoutes = $this->summaries->summarize($left);
            $rightRoutes = $left === $right ? $leftRoutes : $this->summaries->summarize($right);
        } finally {
            $left->close();
            $this->closeOther($left, $right);
        }

        return $this->merge($leftRoutes, $rightRoutes);
    }

    /**
     * @param list<RouteSummary> $left  sorted by sort key
     * @param list<RouteSummary> $right sorted by sort key
     *
     * @return list<RouteComparisonRow>
     */
    public function merge(array $left, array $right): array
    {
        $rows = [];
        $l = 0;
        $r = 0;

        while (isset($left[$l]) || isset($right[$r])) {
            $order = isset($left[$l], $right[$r]) ? strcmp($left[$l]->sortKey, $right[$r]->sortKey) : (isset($left[$l]) ? -1 : 1);

            if ($order === 0) {
                $rows[] = new RouteComparisonRow($left[$l++], $right[$r++]);
            } elseif ($order < 0) {
                $rows[] = new RouteComparisonRow($left[$l++], null);
            } else {
                $rows[] = new RouteComparisonRow(null, $right[$r++]);
            }
        }

        return $rows;
    }

    private function closeOther(GtfsDatabase $left, GtfsDatabase $right): void
    {
        if ($left !== $right) {
            $right->close();
        }
    }
}
