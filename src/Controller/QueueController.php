<?php

namespace App\Controller;

use App\Network\AnalysisQueue;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QueueController extends AbstractController
{
    // The analysis result pages request a new analysis by linking here; the old URL stays served (not redirected) for them
    #[Route(['/results/queue', '/results/queue.php'], name: 'app_queue')]
    public function index(Request $request, AnalysisQueue $queue): Response
    {
        $status = 200;
        $requested = null;
        $show = true;

        $referer = (string) $request->headers->get('Referer', '');
        if ($referer !== '') {
            if (preg_match('/\/results\/.*\/([0-9A-Za-z_.-]+)-Analysis\.[dif.]*.*html$/', $referer, $matches)) {
                $result = $queue->enqueue($matches[1], $request->getClientIp() ?? 'unknown');
                $status = $result['http'];
                $requested = ['network' => $matches[1], 'accepted' => $status >= 200 && $status < 300, 'status' => $result['status']];
            } elseif (!preg_match('/(\/statistics(\.php)?|\/results\/queue(\.php)?|openstreetmap\.org)(\?.*)?$/', $referer)) {
                $status = 404;
                $show = false;
                $requested = ['network' => null, 'accepted' => false, 'status' => ''];
            }
        }

        $entries = null;
        $error = false;
        if ($show) {
            try {
                $entries = $queue->entries();
            } catch (Exception) {
                $error = true;
            }
        }

        return $this->render('statistics/queue.html.twig', [
            'requested' => $requested,
            'show' => $show,
            'entries' => $entries,
            'error' => $error,
        ], new Response('', $status));
    }
}
