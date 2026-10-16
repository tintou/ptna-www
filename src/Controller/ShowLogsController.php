<?php

namespace App\Controller;

use App\Log\LogReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowLogsController extends AbstractController
{
    #[Route('/showlogs', name: 'app_showlogs')]
    public function index(Request $request, LogReader $logs): Response
    {
        $kind = null;
        $name = '';

        // The first parameter present wins, in the order of LogReader::KINDS
        foreach (LogReader::KINDS as $candidate) {
            $value = $request->query->get($candidate);
            if (is_string($value) && $value !== '') {
                $kind = $candidate;
                // '+' in a timezone such as UTC+10 arrives as a space when not URL-encoded
                $name = in_array($candidate, ['timezone', 'planet'], true) ? str_replace(' ', '+', $value) : $value;
                break;
            }
        }

        return $this->render('showlogs/index.html.twig', [
            'is_gtfs' => $kind === 'gtfs',
            'log' => $kind !== null ? $logs->read($kind, $name) : '',
        ]);
    }

    // Old URL /en/showlogs.php?...
    #[Route('/en/showlogs.php', name: 'app_showlogs_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_showlogs', $request->query->all(), 301);
    }
}
