<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RelationController extends AbstractController
{
    #[Route('/relation', name: 'app_relation')]
    public function index(): Response
    {
        return $this->render('relation/index.html.twig');
    }

    // Old URL /relation.php
    #[Route('/relation.php', name: 'app_relation_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_relation', $request->query->all(), 301);
    }
}
