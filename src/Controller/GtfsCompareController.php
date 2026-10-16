<?php

namespace App\Controller;

use App\Gtfs\Compare\CompareParameters;
use App\Gtfs\Compare\FeedReleaseLister;
use App\Gtfs\Compare\RouteVersionComparer;
use App\Gtfs\GtfsFeedLocator;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GtfsCompareController extends AbstractController
{
    private const LEGACY_PAGES = [
        'compare' => 'app_gtfs_compare',
        'compare-feeds' => 'app_gtfs_compare_feeds',
        'compare-versions' => 'app_gtfs_compare_versions',
        'compare-routes' => 'app_gtfs_compare_routes',
        'compare-trips' => 'app_gtfs_compare_trips',
        'compare-shapes' => 'app_gtfs_compare_shapes',
    ];

    #[Route('/gtfs/compare', name: 'app_gtfs_compare')]
    public function index(Request $request): Response
    {
        return $this->render('gtfs/compare/index.html.twig', [
            'p' => CompareParameters::fromQuery($request->query, false),
        ]);
    }

    #[Route('/gtfs/compare/feeds', name: 'app_gtfs_compare_feeds')]
    public function feeds(Request $request, FeedReleaseLister $lister): Response
    {
        $start = microtime(true);
        $p = CompareParameters::fromQuery($request->query);
        $same = $p->feed === $p->feed2;
        $valid = $lister->exists($p->feed) && ($same || $lister->exists($p->feed2));

        return $this->render('gtfs/compare/feeds.html.twig', [
            'p' => $p,
            'same' => $same,
            'valid' => $valid,
            'releases' => $valid ? $lister->list($p->feed) : [],
            'releases2' => $valid && !$same ? $lister->list($p->feed2) : [],
            'duration' => microtime(true) - $start,
        ]);
    }

    #[Route('/gtfs/compare/versions', name: 'app_gtfs_compare_versions')]
    public function versions(Request $request, GtfsFeedLocator $locator, RouteVersionComparer $comparer): Response
    {
        set_time_limit(300);

        $start = microtime(true);
        $p = CompareParameters::fromQuery($request->query);
        $rows = null;
        $error = null;

        if ($locator->find($p->feed, $p->releaseDate) !== null && $locator->find($p->feed2, $p->releaseDate2) !== null) {
            try {
                $rows = $comparer->compare($p->feed, $p->releaseDate, $p->feed2, $p->releaseDate2);
            } catch (DbalException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('gtfs/compare/versions.html.twig', [
            'p' => $p,
            'same_feed' => $p->feed === $p->feed2,
            'left_found' => $locator->find($p->feed, $p->releaseDate) !== null,
            'right_found' => $locator->find($p->feed2, $p->releaseDate2) !== null,
            'rows' => $rows ?? [],
            'error' => $error,
            'duration' => microtime(true) - $start,
        ]);
    }

    // The tables of the next three pages are filled by public/script/gtfs-compare.js using /api/gtfs

    #[Route('/gtfs/compare/routes', name: 'app_gtfs_compare_routes')]
    public function routes(Request $request): Response
    {
        return $this->render('gtfs/compare/routes.html.twig', [
            'osm_relation' => CompareParameters::fromQuery($request->query)->hasOsmRelation(),
        ]);
    }

    #[Route('/gtfs/compare/trips', name: 'app_gtfs_compare_trips')]
    public function trips(Request $request): Response
    {
        return $this->render('gtfs/compare/trips.html.twig', [
            'osm_relation' => CompareParameters::fromQuery($request->query)->hasOsmRelation(),
        ]);
    }

    #[Route('/gtfs/compare/shapes', name: 'app_gtfs_compare_shapes')]
    public function shapes(Request $request, GtfsFeedLocator $locator): Response
    {
        $p = CompareParameters::fromQuery($request->query);

        return $this->render('gtfs/compare/shapes.html.twig', [
            'p' => $p,
            'same_feed' => $p->feed === $p->feed2,
            'left_found' => $locator->find($p->feed, $p->releaseDate) !== null,
            'right_found' => $locator->find($p->feed2, $p->releaseDate2) !== null,
        ]);
    }

    // Old URLs, e.g. /gtfs/compare-routes.php?feed=...
    #[Route('/gtfs/{page}.php', name: 'app_gtfs_compare_legacy', requirements: ['page' => 'compare|compare-feeds|compare-versions|compare-routes|compare-trips|compare-shapes'])]
    public function legacy(string $page, Request $request): RedirectResponse
    {
        return $this->redirectToRoute(self::LEGACY_PAGES[$page], $request->query->all(), 301);
    }
}
