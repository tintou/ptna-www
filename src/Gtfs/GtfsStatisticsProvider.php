<?php

namespace App\Gtfs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Data behind the page 'GTFS Statistics': server resources and the update state of all GTFS feeds.
 */
final class GtfsStatisticsProvider
{
    private readonly string $workDir;

    public function __construct(
        private readonly GtfsFeedLocator $locator,
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    /**
     * @return list<float>|null the load averages of the last 1, 5 and 15 minutes
     */
    public function loadAverage(): ?array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return $load !== false ? $load : null;
    }

    /**
     * @return array{total: int, free: int}|null bytes of the file system holding the work directory
     */
    public function disk(): ?array
    {
        $total = @disk_total_space($this->workDir);
        $free = @disk_free_space($this->workDir);

        return $total !== false && $free !== false ? ['total' => (int) $total, 'free' => (int) $free] : null;
    }

    /**
     * @return list<array{feed: string, country: string, updated: \DateTimeImmutable|null, hasLog: bool}>
     */
    public function updates(): array
    {
        $updates = [];
        foreach ($this->locator->currentFeeds() as $feed => $file) {
            $time = @filemtime($file);
            $updates[] = [
                'feed' => $feed,
                'country' => explode('-', $feed)[0],
                'updated' => $time !== false ? (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null,
                'hasLog' => is_file(substr($file, 0, -strlen('-ptna-gtfs-sqlite.db')) . '-gtfs-update.log'),
            ];
        }

        return $updates;
    }
}
