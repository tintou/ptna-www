<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class GtfsCompareDocController extends AbstractController
{
    #[Route('/gtfs-compare-doc', name: 'app_gtfs_compare_doc')]
    public function index(Request $request, Environment $twig): Response
    {
        $template = 'doc/gtfs-compare.' . $request->getLocale() . '.html.twig';

        if (!$twig->getLoader()->exists($template)) {
            $template = 'doc/gtfs-compare.en.html.twig';
        }

        return $this->render($template);
    }
}
