<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class GtfsDocController extends AbstractController
{
    #[Route('/gtfs-doc', name: 'app_gtfs_doc')]
    public function index(Request $request, Environment $twig): Response
    {
        $template = 'doc/gtfs.' . $request->getLocale() . '.html.twig';

        if (!$twig->getLoader()->exists($template)) {
            $template = 'doc/gtfs.en.html.twig';
        }

        return $this->render($template);
    }

    // Old per-language URLs, e.g. /de/gtfs-doc.php
    #[Route('/{lang}/gtfs-doc.php', name: 'app_gtfs_doc_legacy', requirements: ['lang' => 'en|de|fr|no|pl|ru|sv|da|fr_FR|pl_PL|ru_RU'])]
    public function legacy(string $lang): RedirectResponse
    {
        return $this->redirectToRoute('app_gtfs_doc', ['lang' => substr($lang, 0, 2)], 301);
    }
}
