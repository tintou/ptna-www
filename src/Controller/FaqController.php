<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    #[Route('/faq', name: 'app_faq')]
    public function index(): Response
    {
        return $this->render('faq/index.html.twig');
    }

    // Old per-language URLs, e.g. /de/faq.html
    #[Route('/{lang}/faq.html', name: 'app_faq_legacy', requirements: ['lang' => 'en|de|fr'])]
    public function legacy(string $lang): RedirectResponse
    {
        return $this->redirectToRoute('app_faq', ['lang' => $lang], 301);
    }
}
