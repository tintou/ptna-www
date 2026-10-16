<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RelationController extends AbstractController
{
    #[Route('/relation', name: 'app_relation')]
    public function index(): Response
    {
        return $this->render('relation/index.html.twig');
    }
}
