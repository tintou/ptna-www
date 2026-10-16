<?php

namespace App\Controller;

use App\Gtfs\GtfsCountryCatalog;
use App\Gtfs\GtfsFeedSummaryProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class GtfsController extends AbstractController
{
    #[Route('/gtfs/{country}', name: 'app_gtfs_country', requirements: ['country' => '[A-Z]{2}'])]
    public function country(string $country, GtfsCountryCatalog $catalog, GtfsFeedSummaryProvider $summaries): Response
    {
        $data = $catalog->get($country) ?? throw new NotFoundHttpException();

        $start = microtime(true);
        $sections = [];
        foreach ($data['sections'] as $section) {
            $section['rows'] = array_map($summaries->summarize(...), $section['feeds']);
            $sections[] = $section;
        }

        return $this->render('gtfs/country.html.twig', [
            'code' => $country,
            'country' => $data,
            'sections' => $sections,
            'duration' => microtime(true) - $start,
        ]);
    }

    // Old URLs, e.g. /gtfs/AT/index.php
    #[Route('/gtfs/{country}/index.php', name: 'app_gtfs_country_legacy', requirements: ['country' => '[A-Z]{2}'])]
    public function legacy(string $country): RedirectResponse
    {
        return $this->redirectToRoute('app_gtfs_country', ['country' => $country], 301);
    }
}
