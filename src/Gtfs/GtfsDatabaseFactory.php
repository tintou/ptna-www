<?php

namespace App\Gtfs;

use Doctrine\DBAL\DriverManager;

/**
 * Opens the per-feed SQLite files on demand as separate DBAL connections,
 * independent from the application's own database (DATABASE_URL).
 */
final class GtfsDatabaseFactory
{
    public function __construct(private readonly GtfsFeedLocator $locator)
    {
    }

    public function path(string $feed, string $release = ''): ?string
    {
        return $this->locator->find($feed, $release);
    }

    public function open(string $feed, string $release = ''): ?GtfsDatabase
    {
        $path = $this->locator->find($feed, $release);
        if ($path === null) {
            return null;
        }

        return new GtfsDatabase(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]));
    }
}
