<?php

namespace App\Network;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the details files of the OSM based PTNA analyses (not GTFS) below the work directory.
 *
 * Layout: <work>/<country>/<network>-Analysis-details.txt
 *     or: <work>/<country>/<subdir>/<network>-Analysis-details.txt
 * where <country> and <subdir> are the first two '-' separated parts of the network name.
 */
final class NetworkDetailsReader
{
    public const NAME_PATTERN = '/^[0-9A-Za-z_.-]+$/';

    private const OPTION_DEFAULTS = [
        'allow-coach' => 'OFF', 'check-access' => 'OFF', 'check-against-gtfs' => 'OFF', 'check-bus-stop' => 'OFF',
        'check-dates' => 'OFF', 'check-gtfs' => 'OFF', 'check-from-to' => 'OFF', 'check-motorway-link' => 'OFF',
        'check-name-relaxed' => 'OFF', 'check-name' => 'OFF', 'check-osm-separator' => 'OFF', 'check-platform' => 'OFF',
        'check-roundabouts' => 'OFF', 'check-route-ref' => 'OFF', 'check-sequence' => 'OFF', 'check-service-type' => 'OFF',
        'check-stop-position' => 'OFF', 'check-version' => 'OFF', 'check-via' => 'OFF', 'check-way-type' => 'OFF',
        'coloured-sketchline' => 'OFF', 'exclude-if-key-value' => '', 'expect-network-long-as' => '',
        'expect-network-long-for' => '', 'expect-network-long' => 'OFF', 'expect-network-short-as' => '',
        'expect-network-short-for' => '', 'expect-network-short' => 'OFF', 'gtfs-feed' => '', 'inherit-tags' => 'OFF',
        'language' => 'en', 'link-gtfs' => 'OFF', 'max-error' => '', 'multiple-ref-type-entries' => 'analyze',
        'network-guid' => '', 'network-long-regex' => '', 'network-short-regex' => '', 'no-additional-navigation' => 'OFF',
        'operator-regex' => '', 'or-separator' => '|', 'positive-notes' => 'OFF', 'ptv1-compatibility' => 'no',
        'ref-separator' => '/', 'relaxed-begin-end-for' => '', 'roundtrip-distance' => '50', 'separator' => ';',
        'show-gtfs' => 'OFF', 'show-operators' => 'OFF', 'strict-network' => 'OFF', 'strict-operator' => 'OFF',
        'table-show-also' => '',
    ];

    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
        #[Autowire('%kernel.project_dir%/public/results')]
        private readonly string $resultsDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    public function isValidName(string $network): bool
    {
        return preg_match(self::NAME_PATTERN, $network) === 1 && !str_contains($network, '..');
    }

    /**
     * @return NetworkDetails|null null for an invalid network name; check found() for a network without details file
     */
    public function read(string $network): ?NetworkDetails
    {
        if (!$this->isValidName($network)) {
            return null;
        }

        $parts = explode('-', $network);
        $country = array_shift($parts);
        $subdir = count($parts) > 1 ? array_shift($parts) . '/' : '';

        $base = $this->workDir . $country . '/' . $subdir . $network;
        $webBase = '/results/' . $country . '/' . $subdir . $network;

        $values = [];
        $lines = is_file($base . '-Analysis-details.txt')
            ? (file($base . '-Analysis-details.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];
        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value);
            }
        }

        return new NetworkDetails(
            $network,
            $values,
            $base . '-catalog.json',
            $webBase . '-Analysis.html',
            $this->resultsDir . '/' . substr($webBase, strlen('/results/')) . '-Analysis.html',
            $webBase . '-Analysis.diff.html',
        );
    }

    /**
     * @return list<string>
     */
    public function allNetworks(): array
    {
        $networks = [];
        foreach (array_merge(glob($this->workDir . '*/*-details.txt') ?: [], glob($this->workDir . '*/*/*-details.txt') ?: []) as $file) {
            $networks[] = str_replace('-Analysis-details.txt', '', basename($file));
        }
        sort($networks);

        return $networks;
    }

    /**
     * @return list<array{option: string, value: string}> empty if the analysis has no options recorded
     */
    public function options(NetworkDetails $details): array
    {
        if (!$details->has('analysis-options')) {
            return [];
        }

        $options = [];
        foreach (self::OPTION_DEFAULTS as $option => $default) {
            $options[$option] = $details->get($option, $default);
        }

        foreach (preg_split('/--/', $details->get('analysis-options')) ?: [] as $completeOption) {
            $parts = explode('=', trim($completeOption), 2);
            $option = trim($parts[0]);
            if ($option === '') {
                continue;
            }
            if ($option === 'exclude-if-key-value') {
                $options[$option] = is_array($options[$option] ?? null) ? $options[$option] : [];
                $options[$option][] = preg_replace('/(^[\'"])|([\'"]$)/', '', trim($parts[1] ?? ''));
            } else {
                $options[$option] = isset($parts[1]) ? trim($parts[1]) : 'ON';
            }
        }

        $rows = [];
        foreach ($options as $option => $value) {
            foreach ((array) $value as $part) {
                $rows[] = ['option' => $option, 'value' => (string) $part];
            }
        }

        return $rows;
    }
}
