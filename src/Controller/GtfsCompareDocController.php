<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    // Old per-language URLs, e.g. /sv/gtfs-compare-doc.php
    #[Route('/{lang}/gtfs-compare-doc.php', name: 'app_gtfs_compare_doc_legacy', requirements: ['lang' => 'en|ru|sv|ru_RU'])]
    public function legacy(string $lang): RedirectResponse
    {
        return $this->redirectToRoute('app_gtfs_compare_doc', ['lang' => substr($lang, 0, 2)], 301);
    }
}
