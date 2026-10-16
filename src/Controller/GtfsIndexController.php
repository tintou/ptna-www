<?php

namespace App\Controller;

use App\Gtfs\GtfsCountryCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GtfsIndexController extends AbstractController
{
    #[Route('/gtfs', name: 'app_gtfs_index')]
    public function index(GtfsCountryCatalog $catalog): Response
    {
        return $this->render('gtfs/index.html.twig', ['countries' => $catalog->all()]);
    }

    // Old URLs /gtfs/ and /gtfs/index.html
    #[Route('/gtfs/', name: 'app_gtfs_index_slash')]
    #[Route('/gtfs/index.html', name: 'app_gtfs_index_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_gtfs_index', $request->query->all(), 301);
    }
}
