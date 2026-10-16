<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ResultsController extends AbstractController
{
    #[Route('/results', name: 'app_results_legacy')]
    public function legacy(): RedirectResponse
    {
        return $this->redirect('/results/index.html', 301);
    }
}
