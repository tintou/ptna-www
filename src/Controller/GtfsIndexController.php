<?php

namespace App\Controller;

use App\Gtfs\GtfsCountryCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GtfsIndexController extends AbstractController
{
    #[Route('/gtfs', name: 'app_gtfs_index')]
    public function index(GtfsCountryCatalog $catalog): Response
    {
        return $this->render('gtfs/index.html.twig', ['countries' => $catalog->all()]);
    }
}
