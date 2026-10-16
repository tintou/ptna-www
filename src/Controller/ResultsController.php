<?php

namespace App\Controller;

use App\Results\ResultRowBuilder;
use App\Results\ResultsCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResultsController extends AbstractController
{
    /** @param string[] $enabledLocales */
    public function __construct(
        private readonly ResultsCatalog $catalog,
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {
    }

    #[Route('/results', name: 'app_results')]
    public function index(): Response
    {
        return $this->render('results/index.html.twig', ['countries' => $this->catalog->all()]);
    }

    // Old URL /results/index.html
    #[Route('/results/index.html', name: 'app_results_legacy')]
    public function legacy(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_results', $request->query->all(), 301);
    }

    #[Route('/results/{country}', name: 'app_results_country', requirements: ['country' => '[A-Z]{2}'])]
    public function country(string $country, Request $request, ResultRowBuilder $rows, TranslatorInterface $translator): Response
    {
        $data = $this->catalog->get($country) ?? throw new NotFoundHttpException();

        // Without an explicit ?lang= the page is shown in the language of the country
        if (!in_array($request->query->get('lang'), $this->enabledLocales, true)) {
            $locale = substr($data['lang'], 0, 2);
            if (in_array($locale, $this->enabledLocales, true)) {
                $request->setLocale($locale);
                $translator->setLocale($locale);
            }
        }

        $sections = [];
        foreach ($data['sections'] as $section) {
            $section['rows'] = array_map(
                static fn (array $entry): array => $rows->build($entry[0], $entry[1]) + ['label' => $entry[2] ?? null],
                $section['networks'],
            );
            $sections[] = $section;
        }

        return $this->render('results/country.html.twig', [
            'code' => $country,
            'country' => $data,
            'sections' => $sections,
        ]);
    }

    // Old URLs /results/<CC>/index.php, /results/<CC>/index.html and /results/<CC>/
    #[Route('/results/{country}/index.php', name: 'app_results_country_legacy_php', requirements: ['country' => '[A-Z]{2}'])]
    #[Route('/results/{country}/index.html', name: 'app_results_country_legacy_html', requirements: ['country' => '[A-Z]{2}'])]
    #[Route('/results/{country}/', name: 'app_results_country_legacy_slash', requirements: ['country' => '[A-Z]{2}'])]
    public function legacyCountry(Request $request, string $country): RedirectResponse
    {
        return $this->redirectToRoute('app_results_country', ['country' => $country] + $request->query->all(), 301);
    }
}
