<?php

namespace App\Gtfs;

use Doctrine\DBAL\Exception as DbalException;

final class GtfsFeedSummaryProvider
{
    public function __construct(private readonly GtfsDatabaseFactory $databases)
    {
    }

    public function summarize(string $feed): GtfsFeedSummary
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
