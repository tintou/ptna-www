<?php

namespace App\Gtfs\Compare;

use App\Gtfs\GtfsDatabaseFactory;
use App\Gtfs\GtfsFeedLocator;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Lists the (non-empty) releases of a feed, newest first, together with their feed_info.
 */
final class FeedReleaseLister
{
    public function __construct(
        private readonly GtfsFeedLocator $locator,
        private readonly GtfsDatabaseFactory $databases,
    ) {
    }

    public function exists(string $feed): bool
    {
        return $feed !== '' && $this->locator->find($feed) !== null;
    }

    /**
     * @return list<FeedRelease>
     */
    public function list(string $feed): array
    {
        $dates = $this->locator->releaseDates($feed, true);
        rsort($dates);

        return array_map(fn (string $date): FeedRelease => $this->release($feed, $date), $dates);
    }

    private function release(string $feed, string $date): FeedRelease
    {
        $info = [];
        $db = $this->databases->open($feed, $date);
        if ($db !== null) {
            try {
                $info = $db->feedInfo();
            } catch (DbalException) {
            } finally {
                $db->close();
            }
        }

        $value = static fn (string $key): string => (string) ($info[$key] ?? '');

        return new FeedRelease($date, $value('feed_publisher_name'), $value('feed_start_date'), $value('feed_end_date'), $value('feed_version'));
    }
}
