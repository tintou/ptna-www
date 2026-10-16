<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DocController extends AbstractController
{
    #[Route('/doc', name: 'app_doc')]
    public function index(Request $request, Environment $twig): Response
    {
        $template = 'doc/index.' . $request->getLocale() . '.html.twig';

        if (!$twig->getLoader()->exists($template)) {
            $template = 'doc/index.en.html.twig';
        }

        return $this->render($template);
    }
}
