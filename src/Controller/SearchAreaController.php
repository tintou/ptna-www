<?php

namespace App\Controller;

use App\Network\NetworkDetailsReader;
use App\Network\SearchAreaReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SearchAreaController extends AbstractController
{
    #[Route('/results/search-area', name: 'app_search_area')]
    public function index(Request $request, NetworkDetailsReader $reader, SearchAreaReader $areas): Response
    {
        $network = $request->query->get('network');
        $details = is_string($network) ? $reader->read($network) : null;
        if ($details === null || !$details->found()) {
            throw new NotFoundHttpException();
        }

        return $this->render('results/search_area.html.twig', [
            'network' => $network,
            'area' => $areas->read($details),
        ]);
    }

    // Old URL /results/search-area.php?network=...
    #[Route('/results/search-area.php', name: 'app_search_area_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_search_area', $request->query->all(), 301);
    }
}
