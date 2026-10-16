<?php

namespace App\Controller;

use App\Analysis\AnalysisHtmlRenderer;
use App\Results\ResultsCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly AnalysisHtmlRenderer $renderer,
        private readonly ResultsCatalog $catalog,
        #[Autowire('%kernel.project_dir%/public/results')]
        private readonly string $resultsDir,
        /** @var string[] */
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {
    }

    /** The static HTML file of the previous analysis is replaced by the page rendered from the JSON file. */
    #[Route('/results/{network}-Analysis.html', name: 'app_analysis_legacy', requirements: ['network' => '[A-Z]{2}/[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)*'])]
    public function legacy(string $network, Request $request): Response
    {
        if (str_contains($network, '..') || !is_file(sprintf('%s/%s-Analysis.json', $this->resultsDir, $network))) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('app_analysis', ['network' => $network] + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    /** The analysis of a network is rendered from the JSON file written by the analysis. */
    #[Route('/results/{network}-Analysis', name: 'app_analysis', requirements: ['network' => '[A-Z]{2}/[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)*'])]
    public function analysis(string $network, Request $request, TranslatorInterface $translator): Response
    {
        if (str_contains($network, '..')) {
            throw new NotFoundHttpException();
        }
        $file = sprintf('%s/%s-Analysis.json', $this->resultsDir, $network);
        $doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($doc) || ($doc['format'] ?? null) !== 'ptna-analysis') {
            throw new NotFoundHttpException();
        }

        $country = $this->catalog->get(substr($network, 0, 2));
        // An explicit ?lang= wins over the language of the country
        $language = $request->query->get('lang');
        if (!in_array($language, $this->enabledLocales, true)) {
            $language = substr($country['lang'] ?? 'en', 0, 2);
        }

        $request->setLocale($language);
        $translator->setLocale($language);

        return new Response($this->renderer->render($doc, $language));
    }
}
