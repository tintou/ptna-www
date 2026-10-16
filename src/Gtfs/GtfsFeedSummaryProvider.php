<?php

namespace App\Gtfs;

use Doctrine\DBAL\Exception as DbalException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class GtfsFeedSummaryProvider
{
    private const RELEASES = ['', 'long-term', 'previous'];

    public function __construct(
        private readonly GtfsDatabaseFactory $databases,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Opening up to three SQLite files per feed is slow for the country tables, so the summary is cached
     * until one of the database files (or what a 'previous' / 'long-term' link points to) changes.
     */
    public function summarize(string $feed): GtfsFeedSummary
    {
        $fingerprint = [];
        foreach (self::RELEASES as $release) {
            $path = $this->databases->path($feed, $release);
            $fingerprint[] = $path !== null ? $path . '|' . (int) filemtime($path) . '|' . (int) filesize($path) : '-';
        }
        if ($fingerprint[0] === '-') {
            return GtfsFeedSummary::missing($feed);
        }

        $failed = null;
        $summary = $this->cache->get(
            'gtfs_summary_' . hash('xxh128', $feed . "\0" . implode("\0", $fingerprint)),
            function (ItemInterface $item) use ($feed, &$failed): GtfsFeedSummary {
                $summary = $this->read($feed);
                if ($summary->error !== null) {
                    $failed = $summary;
                    $item->expiresAfter(0);
                }

                return $summary;
            },
        );

        return $failed ?? $summary;
    }

    private function read(string $feed): GtfsFeedSummary
    {
        $db = $this->databases->open($feed);
        if ($db === null) {
            return GtfsFeedSummary::missing($feed);
        }

        try {
            $ptna = $db->ptna();
            $feedInfo = $db->feedInfo();

            return new GtfsFeedSummary($feed, $ptna, $feedInfo, $this->ptnaOf($feed, 'long-term'), $this->ptnaOf($feed, 'previous'));
        } catch (DbalException $e) {
            return GtfsFeedSummary::failed($feed, $e->getMessage());
        } finally {
            $db->close();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ptnaOf(string $feed, string $release): ?array
    {
        $db = $this->databases->open($feed, $release);
        if ($db === null) {
            return null;
        }

        try {
            return $db->ptna();
        } finally {
            $db->close();
        }
    }
}
