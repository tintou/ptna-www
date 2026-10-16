<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    // Old per-language start pages, e.g. /de/index.html
    #[Route('/{lang}/index.html', name: 'app_home_legacy', requirements: ['lang' => 'en|de|fr|fr_FR'])]
    public function legacy(string $lang): RedirectResponse
    {
        return $this->redirectToRoute('app_home', ['lang' => substr($lang, 0, 2), '_fragment' => 'whatis'], 301);
    }
}
