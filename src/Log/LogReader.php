<?php

namespace App\Log;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the log files written by the PTNA cron jobs under the work directory
 * and removes local paths and user names from them.
 */
final class LogReader
{
    public const KINDS = ['network', 'timezone', 'planet', 'job', 'continent', 'queue', 'gtfs'];

    private const NAME_PATTERN = '/^[0-9A-Za-z_.+-]+$/';

    private const REPLACEMENTS = [
        '/\/osm\/ptna\/work/' => '$WORK_LOC',
        '/\/osm\/ptna\/www/' => '$WWW_LOC',
        '/\/osm\/ptna/' => '$PTNA_LOC',
        '/\/home\/.*?ptna/' => '$PTNA_PATH',
        '/\/home\/.*?gtfs/' => '$GTFS_PATH',
        '/\/home\/.*?bin/' => '~/bin',
        '/toni osm/' => 'user group',
        '/ uid="[^"]*" /' => ' ',
        '/ user="[^"]*" /' => ' ',
        '/ changeset="[^"]*" /' => ' ',
        '/ toni /' => ' user ',
    ];

    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    /**
     * @param string $kind one of self::KINDS
     *
     * @return string the sanitized log, empty if the name is invalid or there is no such log
     */
    public function read(string $kind, string $name): string
    {
        $file = $this->file($kind, $name);
        if ($file === null || !is_file($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        // Timezone logs have a second file written by the analysis of all its networks
        if ($kind === 'timezone') {
            $lines = array_merge($lines, $this->lines($this->workDir . 'log/ptna-all-networks-' . $name . '.log'));
        }

        $replacements = self::REPLACEMENTS;
        if (in_array($kind, ['timezone', 'planet'], true)) {
            $replacements = ['/ MULTIPOLYGON\(\(\(.*/' => ' MULTIPOLYGON(((...)))'] + $replacements;
        }

        return implode("\n", preg_replace(array_keys($replacements), array_values($replacements), $lines)) . "\n";
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    private function file(string $kind, string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name) || str_contains($name, '..')) {
            return null;
        }

        return match ($kind) {
            'network' => $this->workDir . 'log/' . $name . '.log',
            'timezone' => $this->workDir . 'ptna-handle-timezone-' . $name . '.log',
            'planet' => $this->workDir . 'ptna-handle-planet-' . $name . '.log',
            'continent' => $this->workDir . 'ptna-handle-continent-' . $name . '.log',
            'job', 'queue' => $this->workDir . 'ptna-cron-' . $name . '.log',
            'gtfs' => $this->gtfsFile($name),
            default => null,
        };
    }

    private function gtfsFile(string $name): string
    {
        if ($name === 'cron') {
            return $this->workDir . 'gtfs-cron-update.log';
        }

        // <country>/<subdir>/<feed>-gtfs-update.log or <country>/<feed>-gtfs-update.log
        $parts = explode('-', $name);
        $country = $parts[0] . '/';
        $file = $this->workDir . $country . (count($parts) > 2 ? $parts[1] . '/' : '') . $name . '-gtfs-update.log';

        return is_file($file) ? $file : $this->workDir . $country . $name . '-gtfs-update.log';
    }
}
