<?php

namespace App\Controller;

use App\Gtfs\GtfsCountryCatalog;
use App\Gtfs\GtfsDatabase;
use App\Gtfs\GtfsDatabaseFactory;
use App\Gtfs\GtfsRouteLister;
use App\Gtfs\GtfsShapeProvider;
use App\Gtfs\GtfsSingleTripProvider;
use App\Gtfs\GtfsTimeline;
use App\Gtfs\GtfsTripResolver;
use App\Gtfs\GtfsTripVariantLister;
use App\Gtfs\OsmTaggingSuggester;
use App\Gtfs\PtnaAnalysisLinkFinder;
use App\Gtfs\PtnaCommentFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The drill-down pages of the GTFS feeds of a country: routes, the variants (trips) of a route, a single trip and a shape.
 */
final class GtfsBrowseController extends AbstractController
{
    private const COUNTRY = '[A-Z]{2}';

    public function __construct(
        private readonly GtfsCountryCatalog $countries,
        private readonly GtfsDatabaseFactory $databases,
    ) {
    }

    #[Route('/gtfs/{country}/routes', name: 'app_gtfs_routes', requirements: ['country' => self::COUNTRY])]
    public function routes(string $country, Request $request, GtfsRouteLister $lister, GtfsTimeline $timeline): Response
    {
        $data = $this->countries->get($country) ?? throw new NotFoundHttpException();
        [$feed, $release] = $this->feedAndRelease($request);

        $start = microtime(true);
        $db = $feed !== '' ? $this->databases->open($feed, $release) : null;
        $rows = [];
        $ptna = [];
        $includeAgency = false;
        if ($db !== null) {
            try {
                $rows = $lister->list($db);
                $ptna = $db->ptna();
                $osm = $db->osm();
                $includeAgency = !empty($osm['gtfs_agency_is_operator']);
            } finally {
                $db->close();
            }
        }

        return $this->render('gtfs/browse/routes.html.twig', [
            'code' => $country,
            'country' => $data,
            'feed' => $feed,
            'release' => $release,
            'found' => $db !== null,
            'rows' => $rows,
            'ptna' => $ptna,
            'include_agency' => $includeAgency,
            'timeline' => $feed !== '' ? $timeline->build($feed, $release) : null,
            'duration' => microtime(true) - $start,
        ], new Response(status: $feed !== '' && $db === null ? 404 : 200));
    }

    #[Route('/gtfs/{country}/trips', name: 'app_gtfs_trips', requirements: ['country' => self::COUNTRY])]
    public function trips(
        string $country,
        Request $request,
        GtfsTripVariantLister $lister,
        GtfsTripResolver $resolver,
        PtnaAnalysisLinkFinder $links,
        PtnaCommentFormatter $comments,
        GtfsTimeline $timeline,
    ): Response {
        $data = $this->countries->get($country) ?? throw new NotFoundHttpException();
        [$feed, $release] = $this->feedAndRelease($request);
        $routeId = $this->freeText($request, 'route_id');

        $start = microtime(true);
        $db = $feed !== '' ? $this->databases->open($feed, $release) : null;
        $route = null;
        $variants = [];
        $analysis = null;
        $routeComments = [];
        $shortName = '???';
        if ($db !== null) {
            try {
                $route = $resolver->routeDetails($db, $routeId);
                if ($route !== null) {
                    $shortName = ($route['route_short_name'] ?? '') !== '' ? (string) $route['route_short_name'] : '???';
                    $routeComments = $comments->lines($route);
                    $analysis = $links->forRoute($db, $route, $shortName, $request->getLocale());
                    $variants = $lister->list($db, $routeId);
                }
            } finally {
                $db->close();
            }
        }

        return $this->render('gtfs/browse/trips.html.twig', [
            'code' => $country,
            'country' => $data,
            'feed' => $feed,
            'release' => $release,
            'route_id' => $routeId,
            'found' => $db !== null && $route !== null,
            'route_short_name' => $shortName,
            'route_comments' => $routeComments,
            'analysis' => $analysis,
            'variants' => $variants,
            'timeline' => $feed !== '' ? $timeline->build($feed, $release) : null,
            'duration' => microtime(true) - $start,
        ], new Response(status: $feed !== '' && ($db === null || $route === null) ? 404 : 200));
    }

    #[Route('/gtfs/{country}/single-trip', name: 'app_gtfs_single_trip', requirements: ['country' => self::COUNTRY])]
    public function singleTrip(
        string $country,
        Request $request,
        GtfsTripResolver $resolver,
        GtfsSingleTripProvider $provider,
        GtfsShapeProvider $shapes,
        OsmTaggingSuggester $suggester,
        PtnaCommentFormatter $comments,
    ): Response {
        $data = $this->countries->get($country) ?? throw new NotFoundHttpException();
        [$feed, $release] = $this->feedAndRelease($request);
        $tripId = $this->freeText($request, 'trip_id');
        $shapeId = $this->freeText($request, 'shape_id');

        // old links with a shape only
        if ($tripId === '' && $shapeId !== '') {
            return $this->redirectToRoute('app_gtfs_shape', $request->query->all() + ['country' => $country]);
        }

        $start = microtime(true);
        $db = $feed !== '' ? $this->databases->open($feed, $release) : null;
        $model = null;
        if ($db !== null) {
            try {
                $model = $this->singleTripModel($db, $feed, $tripId, $resolver, $provider, $shapes, $suggester, $comments);
            } finally {
                $db->close();
            }
        }

        return $this->render('gtfs/browse/single_trip.html.twig', [
            'code' => $country,
            'country' => $data,
            'feed' => $feed,
            'release' => $release,
            'trip_id' => $tripId,
            'found' => $model !== null,
            'trip' => $model,
            'duration' => microtime(true) - $start,
        ], new Response(status: $feed !== '' && $model === null ? 404 : 200));
    }

    #[Route('/gtfs/{country}/shape', name: 'app_gtfs_shape', requirements: ['country' => self::COUNTRY])]
    public function shape(string $country, Request $request, GtfsShapeProvider $shapes): Response
    {
        $data = $this->countries->get($country) ?? throw new NotFoundHttpException();
        [$feed, $release] = $this->feedAndRelease($request);
        $shapeId = $this->freeText($request, 'shape_id');

        $start = microtime(true);
        $db = $feed !== '' && $shapeId !== '' ? $this->databases->open($feed, $release) : null;
        $points = [];
        $trips = [];
        if ($db !== null) {
            try {
                $points = $shapes->points($db, $shapeId, true);
                $trips = $shapes->tripsOfShape($db, $shapeId);
            } finally {
                $db->close();
            }
        }

        return $this->render('gtfs/browse/shape.html.twig', [
            'code' => $country,
            'country' => $data,
            'feed' => $feed,
            'release' => $release,
            'shape_id' => $shapeId,
            'found' => $db !== null,
            'points' => $points,
            'trips' => $trips,
            'duration' => microtime(true) - $start,
        ], new Response(status: $feed !== '' && $shapeId !== '' && $db === null ? 404 : 200));
    }

    // Old URLs, e.g. /gtfs/DE/trips.php?feed=...
    #[Route('/gtfs/{country}/{page}.php', name: 'app_gtfs_browse_legacy', requirements: ['country' => self::COUNTRY, 'page' => 'routes|trips|single-trip|shape'])]
    public function legacy(string $country, string $page, Request $request): RedirectResponse
    {
        $query = $request->query->all();
        [$feed, $release] = $this->feedAndRelease($request);
        unset($query['network']);
        if ($feed !== '') {
            $query['feed'] = $feed;
            $query['release_date'] = $release;
            if ($release === '') {
                unset($query['release_date']);
            }
        }

        return $this->redirectToRoute('app_gtfs_' . str_replace('-', '_', $page), ['country' => $country] + $query, 301);
    }

    /**
     * @return array{feed: string, trip: array<string, mixed>, route: array<string, mixed>, stops: list<\App\Gtfs\GtfsTripStop>, serviceTimes: array{rows: list<\App\Gtfs\GtfsServiceTimes>, truncated: bool}, shapeId: string, shapePoints: list<\App\Gtfs\GtfsShapePoint>, commentLines: list<string>, tagging: \App\Gtfs\OsmTaggingSuggestion|null}|null
     */
    private function singleTripModel(
        GtfsDatabase $db,
        string $feed,
        string $tripId,
        GtfsTripResolver $resolver,
        GtfsSingleTripProvider $provider,
        GtfsShapeProvider $shapes,
        OsmTaggingSuggester $suggester,
        PtnaCommentFormatter $comments,
    ): ?array {
        $representative = $resolver->representativeTripId($db, $tripId);
        $tripRow = $representative !== null ? $resolver->tripRow($db, $representative) : null;
        $routeRow = $representative !== null ? $resolver->routeOfTrip($db, $representative) : null;
        if ($representative === null || $tripRow === null || $routeRow === null) {
            return null;
        }

        $shapeId = isset($tripRow['shape_id']) ? (string) $tripRow['shape_id'] : '';

        return [
            'feed' => $feed,
            'trip' => $tripRow,
            'route' => $routeRow,
            'stops' => $provider->stops($db, $representative),
            'serviceTimes' => $provider->serviceTimes($db, $representative),
            'shapeId' => $shapeId,
            'shapePoints' => $shapes->points($db, $shapeId, false),
            'commentLines' => $comments->lines($tripRow),
            'tagging' => $suggester->suggest($db, $feed, $tripId),
        ];
    }

    /**
     * @return array{string, string} the feed and the release, empty if missing or invalid
     */
    private function feedAndRelease(Request $request): array
    {
        $feed = $request->query->get('feed', '');
        $release = $request->query->get('release_date', '');
        $feed = is_string($feed) && preg_match('/^[0-9A-Za-z_.-]+$/', $feed) ? $feed : '';
        $release = is_string($release) && preg_match('/^(\d{4}-\d{2}-\d{2}|previous|long-term|error)$/', $release) ? $release : '';

        // old links: 'network' is the feed followed by the release
        $network = $request->query->get('network', '');
        if ($feed === '' && is_string($network) && preg_match('/^[0-9A-Za-z_.-]+$/', $network)) {
            $release = preg_match('/^(.*?)-(previous|long-term|\d{4}-\d{2}-\d{2})(-.*)?$/', $network, $m) ? $m[2] : '';
            $feed = preg_match('/^(.*?)-(previous|long-term|\d{4}-\d{2}-\d{2})(-.*)?$/', $network, $m) ? $m[1] : $network;
        }

        return [$feed, $release];
    }

    private function freeText(Request $request, string $name): string
    {
        $value = $request->query->get($name, '');

        return is_string($value) ? $value : '';
    }
}
