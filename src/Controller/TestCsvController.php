<?php

namespace App\Controller;

use App\Log\LogReader;
use App\Network\NetworkDetailsReader;
use App\Network\WikiCsvInjector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TestCsvController extends AbstractController
{
    #[Route('/testcsv', name: 'app_testcsv')]
    public function index(Request $request, NetworkDetailsReader $reader, WikiCsvInjector $injector, LogReader $logs): Response
    {
        $network = ConfigController::networkFromQuery($request);
        $details = $network !== '' ? $reader->read($network) : null;
        $found = $details !== null && $details->found();

        $run = $found ? $injector->inject($details) : null;

        return $this->render('network/testcsv.html.twig', [
            'network' => $network,
            'found' => $found,
            'result' => $run['result'] ?? '',
            'error' => $run['error'] ?? '',
            'read_log' => isset($run) ? $logs->sanitize($run['read_log']) : '',
            'inject_log' => isset($run) ? $logs->sanitize($run['inject_log']) : '',
        ]);
    }

    // Old URL /en/testcsv.php
    #[Route('/en/testcsv.php', name: 'app_testcsv_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_testcsv', $request->query->all(), 301);
    }
}
