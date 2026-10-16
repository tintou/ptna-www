<?php

namespace App\Controller;

use App\Gtfs\GtfsDetailsProvider;
use App\Gtfs\GtfsStatisticsProvider;
use App\Gtfs\GtfsTimeline;
use App\Gtfs\PtnaCommentFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GtfsDetailsController extends AbstractController
{
    #[Route('/gtfs-details', name: 'app_gtfs_details')]
    public function details(Request $request, GtfsDetailsProvider $provider, GtfsTimeline $timeline): Response
    {
        [$feed, $release] = $this->feedAndRelease($request);

        $details = $feed !== '' ? $provider->details($feed, $release) : null;

        return $this->render('gtfs/details.html.twig', [
            'feed' => $feed,
            'release' => $release,
            'details' => $details,
            'timeline' => $feed !== '' ? $timeline->build($feed, $release) : $timeline->build('', ''),
        ], new Response(status: $feed !== '' && $details === null ? 404 : 200));
    }

    #[Route('/gtfs-analysis-details', name: 'app_gtfs_analysis_details')]
    public function analysisDetails(Request $request, GtfsDetailsProvider $provider, PtnaCommentFormatter $comments): Response
    {
        [$feed, $release] = $this->feedAndRelease($request);

        $topic = $request->query->get('topic', '');
        if (!is_string($topic) || ($topic !== '' && !isset(GtfsDetailsProvider::TOPICS[$topic]))) {
            throw new NotFoundHttpException('Unknown topic');
        }

        $result = $feed !== '' ? $provider->analysisDetails($feed, $release, $topic) : null;

        $rows = array_map(
            static fn (array $row) => $row + ['comment_lines' => $comments->lines($row)],
            $result['rows'] ?? [],
        );

        return $this->render('gtfs/analysis_details.html.twig', [
            'feed' => $feed,
            'release' => $release,
            'topic' => $topic,
            'found' => $result !== null,
            'rows' => $rows,
            'duration' => $result['duration'] ?? 0.0,
        ], new Response(status: $feed !== '' && $result === null ? 404 : 200));
    }

    #[Route('/gtfs-statistics', name: 'app_gtfs_statistics')]
    public function statistics(GtfsStatisticsProvider $provider): Response
    {
        return $this->render('gtfs/statistics.html.twig', [
            'load' => $provider->loadAverage(),
            'disk' => $provider->disk(),
            'updates' => $provider->updates(),
        ]);
    }

    // Old per-language URLs, e.g. /en/gtfs-details.php?feed=...
    #[Route('/{lang}/gtfs-{page}.php', name: 'app_gtfs_details_legacy', requirements: ['lang' => 'en|ro|ru|sv|ru_RU', 'page' => 'details|analysis-details|statistics'])]
    public function legacy(Request $request, string $lang, string $page): RedirectResponse
    {
        $query = $request->query->all() + ['lang' => substr($lang, 0, 2)];

        return $this->redirectToRoute('app_gtfs_' . str_replace('-', '_', $page), $query, 301);
    }

    /**
     * @return array{string, string} the feed and the release, empty if missing or invalid
     */
    private function feedAndRelease(Request $request): array
    {
        $feed = $request->query->get('feed', '');
        $release = $request->query->get('release_date', '');

        $feed = is_string($feed) && preg_match('/^[0-9A-Za-z_.-]+$/', $feed) ? $feed : '';
        $release = is_string($release) && preg_match('/^(\d{4}-\d{2}-\d{2}|previous|long-term|latest|error)$/', $release) ? $release : '';

        return [$feed, $release];
    }
}
