<?php

namespace App\Gtfs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the per-feed SQLite files written by the PTNA GTFS import under the work directory.
 *
 * Layout: <work>/<country>/<feed>[-<release>]-ptna-gtfs-sqlite.db
 *     or: <work>/<country>/<subdir>/<feed>[-<release>]-ptna-gtfs-sqlite.db
 * where <country> and <subdir> are the first two '-' separated parts of the feed name.
 */
final class GtfsFeedLocator
{
    private const NAME_PATTERN = '/^[0-9A-Za-z_.-]+$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const SUFFIX = '-ptna-gtfs-sqlite.db';

    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    /**
     * @param string $release a date (YYYY-MM-DD), 'previous', 'long-term', 'latest' or '' for the current feed
     */
    public function find(string $feed, string $release = ''): ?string
    {
        $feedRelease = $release !== '' ? $feed . '-' . $release : $feed;

        if (!preg_match(self::NAME_PATTERN, $feedRelease)) {
            return null;
        }

        $parts = explode('-', $feed);
        $country = array_shift($parts);
        $subdir = array_shift($parts);

        $candidates = [$this->workDir . $country . '/'];
        if ($subdir !== null) {
            $candidates[] = $this->workDir . $country . '/' . $subdir . '/';
        }

        foreach ($candidates as $dir) {
            $path = $dir . $feedRelease . self::SUFFIX;
            if (!file_exists($path)) {
                continue;
            }

            // 'previous' and 'long-term' are symbolic links to a dated file in the same directory
            if (is_link($path) && preg_match('/-(previous|long-term)$/', $feedRelease)) {
                $target = readlink($path);
                if ($target !== false) {
                    $path = $dir . basename($target);
                }
            }

            return is_file($path) && filesize($path) > 0 ? $path : null;
        }

        return null;
    }

    /**
     * @return list<string> release dates (YYYY-MM-DD) for which a database file exists
     */
    public function releaseDates(string $feed, bool $nonEmptyOnly = false): array
    {
        $dir = $this->directoryOf($feed);
        if ($dir === null) {
            return [];
        }

        $pattern = '/^' . preg_quote($feed, '/') . '-(\d{4}-\d{2}-\d{2})' . preg_quote(self::SUFFIX, '/') . '$/';
        $dates = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match($pattern, $entry, $m) && (!$nonEmptyOnly || filesize($dir . $entry) > 0)) {
                $dates[] = $m[1];
            }
        }

        sort($dates);

        return $dates;
    }

    /**
     * @param string $name '' (current release), 'previous', 'long-term' or 'error'
     *
     * @return string file name of the database the symbolic link points to, empty if there is no such link
     */
    public function linkTarget(string $feed, string $name = ''): string
    {
        if (!in_array($name, ['', 'previous', 'long-term', 'error'], true)) {
            return '';
        }

        $dir = $this->directoryOf($feed);
        if ($dir === null) {
            return '';
        }

        $path = $dir . $feed . ($name !== '' ? '-' . $name : '') . self::SUFFIX;
        $target = is_link($path) ? readlink($path) : false;

        return $target !== false ? basename($target) : '';
    }

    /**
     * @return array<string, string> database file by feed name, for all feeds with a current database (no dated release, no link to one)
     */
    public function currentFeeds(): array
    {
        $files = array_merge(
            glob($this->workDir . '*/*' . self::SUFFIX) ?: [],
            glob($this->workDir . '*/*/*' . self::SUFFIX) ?: [],
        );

        $feeds = [];
        foreach ($files as $file) {
            if (preg_match('/previous|long-term|error|20\d\d-\d\d-\d\d/', $file)) {
                continue;
            }
            $feeds[basename($file, self::SUFFIX)] = $file;
        }
        ksort($feeds);

        return $feeds;
    }

    /**
     * @return int|null size in bytes, null if there is no such database
     */
    public function size(string $feed, string $releaseDate): ?int
    {
        if (!preg_match(self::DATE_PATTERN, $releaseDate)) {
            return null;
        }

        $dir = $this->directoryOf($feed);
        $path = $dir !== null ? $dir . $feed . '-' . $releaseDate . self::SUFFIX : null;

        return $path !== null && is_file($path) ? filesize($path) : null;
    }

    private function directoryOf(string $feed): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $feed)) {
            return null;
        }

        $parts = explode('-', $feed);
        $dir = $this->workDir . array_shift($parts) . '/';
        $subdir = array_shift($parts);

        if (!is_dir($dir)) {
            return null;
        }

        if ($subdir !== null && is_dir($dir . $subdir)) {
            $dir .= $subdir . '/';
        }

        return $dir;
    }
}
