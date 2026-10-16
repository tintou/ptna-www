<?php

namespace App\Gtfs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the relations of a GTFS route in the PTNA analysis results (public/results/<country>/<source>-Analysis.html).
 */
final class PtnaAnalysisLinkFinder
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/results')]
        private readonly string $resultsDir,
    ) {
    }

    /**
     * @param array<string, mixed> $route the route row, see GtfsTripResolver::routeDetails()
     *
     * @return array{source: string, links: list<PtnaAnalysisLink>|null}|null null if the feed has no PTNA analysis, links is null if the route can not be searched for
     */
    public function forRoute(GtfsDatabase $db, array $route, string $routeShortName, string $language): ?array
    {
        $osm = $db->osm();
        $source = (string) ($osm['ptna_analysis'] ?? '');
        if ($source === '') {
            return null;
        }

        $types = RouteTypeCatalog::fromDatabase($db);
        $osmRoute = isset($route['route_type']) ? $types->osmRoute((int) $route['route_type']) : '???';
        $vehicle = $types->vehicle($osmRoute, $language);

        $ref = $routeShortName;
        if (!empty($osm['gtfs_short_name_hack1']) && !empty($route['route_long_name']) && !empty($route['route_id']) && $route['route_long_name'] != $route['route_id']) {
            $ref = (string) $route['route_long_name'];
        }
        if ($ref !== '???') {
            $ref = preg_replace('/\s+' . preg_quote($vehicle, '/') . '$/', '', $ref);
        }

        return ['source' => $source, 'links' => $this->find((string) ($route['route_id'] ?? ''), $routeShortName, $ref, $osmRoute, $source)];
    }

    /**
     * @param string $source the analysis source of the feed, e.g. 'DE-BW-VVS', the part before the first '-' is the country
     *
     * @return list<PtnaAnalysisLink>|null null if the source or the route can not be searched for
     */
    public function find(string $routeId, string $routeShortName, string $osmRef, string $osmRoute, string $source): ?array
    {
        if ($routeId === '' || $osmRef === '' || $routeShortName === ''
            || !preg_match('/^[0-9A-Za-z_.-]+$/', $source) || str_contains($source, '..')) {
            return null;
        }

        $parts = explode('-', $source);
        $country = array_shift($parts);
        $webPath = '/results/' . $country . '/' . (count($parts) > 1 ? array_shift($parts) . '/' : '') . $source . '-Analysis.html';

        $path = $this->resultsDir . substr($webPath, strlen('/results'));
        $file = is_file($path) ? fopen($path, 'r') : false;
        if ($file === false) {
            return [];
        }

        // every character of the reference may be followed by blanks, blanks in the reference are ignored
        $reference = implode('', array_map(static fn (string $c) => preg_quote($c, '~') . '\s*', mb_str_split(preg_replace('/\s+/', '', $osmRef))));
        $pattern = '~id="[^0-9].*data-ref="' . $reference . '"~';

        $links = [];
        while (($line = fgets($file)) !== false) {
            if (!preg_match($pattern, $line)) {
                continue;
            }

            $id = (string) preg_replace('/".*$/s', '', (string) preg_replace('/^.*id="/s', '', $line));
            $dataRef = (string) preg_replace('/".*$/s', '', (string) preg_replace('/^.*data-ref="/s', '', $line));
            $route = match (true) {
                str_starts_with($id, 'share_taxi') => 'share_taxi',
                str_starts_with($id, 'light_rail') => 'light_rail',
                default => (string) preg_replace('/_.*$/', '', $id),
            };

            $links[] = new PtnaAnalysisLink(
                $id,
                $dataRef,
                $route,
                str_contains($line, $routeId),
                $route === $osmRoute,
                $webPath,
                $source,
                $this->info($line),
            );
        }
        fclose($file);

        return $links;
    }

    private function info(string $line): string
    {
        $info = (string) preg_replace('/^.*data-info="/s', '', $line);
        $steps = [
            '/".*$/s' => '',
            '/<a[^>]*title=_/' => '',
            '/GTFS<\/a>/' => '',
            '/, GTFS-Route-Id: /' => ';',
            '/, GTFS-Release-Date: /' => ';',
            '/,*\s*GTFS-Feed: /' => '; ',
            '/;; /' => '; ',
            '/<[^>]*>/' => '',
            '/_>/' => '',
        ];
        foreach ($steps as $pattern => $replacement) {
            $info = (string) preg_replace($pattern, $replacement, $info);
        }

        return $info;
    }
}
