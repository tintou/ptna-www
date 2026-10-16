<?php

namespace App\Controller;

use App\Gtfs\GtfsDatabase;
use App\Gtfs\GtfsDatabaseFactory;
use App\Gtfs\GtfsElementCollector;
use App\Gtfs\GtfsTripResolver;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ApiGtfsController extends AbstractController
{
    public function __construct(
        private readonly GtfsDatabaseFactory $databases,
        private readonly GtfsTripResolver $resolver,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/gtfs', name: 'app_api_gtfs')]
    public function index(Request $request): JsonResponse
    {
        set_time_limit(300);

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        $query = $request->query->all();
        $param = static fn (string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
        $feed = $param('feed');
        $releaseDate = $param('release_date');
        $routeId = $param('route_id');
        $tripId = $param('trip_id');
        $ptna = array_key_exists('ptna', $query);

        $response = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'generator' => $this->generator($request, $query),
            'elements' => [],
        ];

        if ($feed === '') {
            $response['error'] = "Name of GTFS feed not specified: use parameter 'feed'";
        } elseif (($db = $this->databases->open($feed, $releaseDate)) === null) {
            $response['error'] = $releaseDate !== ''
                ? sprintf("Data base for GTFS feed ('%s') and version ('%s') not found", $feed, $releaseDate)
                : sprintf("Data base for GTFS feed ('%s') not found", $feed);
        } else {
            try {
                $response['generator']['params']['release_date'] = $this->releaseDate($feed, $releaseDate);
                $response = $this->fill($response, $db, $routeId, $tripId, $ptna);
            } catch (DbalException $e) {
                $response['error'] = sprintf("%s - feed = '%s'", $e->getMessage(), $feed);
            } finally {
                $db->close();
            }
        }

        $response['duration'] = sprintf('%.6F', microtime(true) - $startTime);
        $response['size'] = memory_get_usage() - $memoryBefore;

        return new JsonResponse($response);
    }

    // Old URL /api/gtfs.php?...
    #[Route('/api/gtfs.php', name: 'app_api_gtfs_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_api_gtfs', $request->query->all(), 301);
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function fill(array $response, GtfsDatabase $db, string $routeId, string $tripId, bool $ptna): array
    {
        if ($ptna) {
            $response = $this->addRow($response, $db, 'ptna');
            $response = $this->addRow($response, $db, 'osm');
        }
        $response = $this->addRow($response, $db, 'feed_info');
        $response = $this->addLicense($response, $db);

        $collector = new GtfsElementCollector($db, $this->resolver, $ptna);
        if ($routeId !== '') {
            foreach (explode(';', $routeId) as $id) {
                $collector->addRoute($id);
            }
        } elseif ($tripId !== '') {
            $collector->addTripWithAgency($tripId);
        } elseif ($db->hasTable('agency')) {
            $response['agency'] = $db->connection()->fetchAllAssociative('SELECT * FROM agency');
        }

        if ($collector->agency() !== null) {
            $response['agency'] = $collector->agency();
        }
        $response['elements'] = $collector->elements();

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function addRow(array $response, GtfsDatabase $db, string $table): array
    {
        if (!$db->hasTable($table)) {
            return $response;
        }

        $row = $table === 'feed_info' ? ['feed_publisher_name' => '', 'feed_publisher_url' => '', 'feed_lang' => ''] : [];
        // $table is one of the fixed names above, never user input
        $response[$table] = array_merge($row, $db->connection()->fetchAssociative(sprintf('SELECT * FROM %s LIMIT 1', $table)) ?: []);

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function addLicense(array $response, GtfsDatabase $db): array
    {
        if (!$db->hasTable('ptna')) {
            return $response;
        }

        $license = [];
        foreach ($db->connection()->fetchAllAssociative('SELECT * FROM ptna') as $row) {
            if (isset($row['original_license'])) {
                $license['type'] = $row['original_license'];
            }
            if (isset($row['original_license_url'])) {
                $license['url'] = $row['original_license_url'];
            }
            if (isset($row['license'])) {
                $license['use for OSM'] = $row['license'];
                $license['url for OSM'] = $row['license_url'] ?? null;
            }
        }
        $response['license'] = $license;

        return $response;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function generator(Request $request, array $query): array
    {
        $orig = $this->projectDir . '/.git/ORIG_HEAD';
        $local = in_array($request->getHost(), ['localhost', '127.0.0.1'], true);

        if (!$local && is_file($orig)) {
            $version = 'PTNA ' . substr((string) file_get_contents($orig), 0, 6);
            $date = gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($orig));
        } else {
            $version = $local ? 'PTNA on localhost' : 'PTNA';
            $date = gmdate('Y-m-d\TH:i:s\Z');
        }

        return [
            'version' => $version,
            'date' => $date,
            'url' => $request->getSchemeAndHttpHost() . $request->getPathInfo(),
            'params' => $query,
        ];
    }

    private function releaseDate(string $feed, string $releaseDate): string
    {
        $path = $this->databases->path($feed, $releaseDate);

        return $path === null ? '' : preg_replace('/-ptna.*$/', '', preg_replace('/^.*' . preg_quote($feed, '/') . '-/', '', basename($path)));
    }
}
