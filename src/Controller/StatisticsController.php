<?php

namespace App\Controller;

use App\Statistics\CronSchedule;
use App\Statistics\NetworkStatistics;
use App\Statistics\ServerLoad;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatisticsController extends AbstractController
{
    #[Route('/statistics', name: 'app_statistics')]
    public function index(NetworkStatistics $statistics, CronSchedule $cron, ServerLoad $load): Response
    {
        return $this->render('statistics/index.html.twig', [
            'cpu' => $load->cpu(),
            'disk' => $load->disk(),
            'table' => $statistics->table(),
            'cron' => $cron->schedule(),
        ]);
    }
}
