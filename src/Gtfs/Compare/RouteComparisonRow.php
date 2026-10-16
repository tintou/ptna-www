<?php

namespace App\Gtfs\Compare;

/**
 * One line of the route comparison: a route of the left feed version, of the right one, or of both.
 */
final readonly class RouteComparisonRow
{
    public function __construct(
        public ?RouteSummary $left,
        public ?RouteSummary $right,
    ) {
    }

    /**
     * Routes present on one side only, or on both sides but with different figures, are interesting.
     */
    public function changed(): bool
    {
        return $this->left === null || $this->right === null || $this->left->differsFrom($this->right);
    }
}
