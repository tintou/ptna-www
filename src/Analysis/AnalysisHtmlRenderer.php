<?php

namespace App\Analysis;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders an analysis document (see ptna-analysis.schema.json) as the HTML page of the analysis.
 *
 * The sections, their titles, numbers and anchors, the table of contents and the explanatory texts
 * are not part of the document, they are derived here.
 */
final class AnalysisHtmlRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly IssueCatalog $issues,
        #[Autowire('%kernel.project_dir%/config/analysis/colours.yaml')]
        private readonly string $coloursFile,
    ) {
    }

    /** @param array<string, mixed> $doc */
    public function render(array $doc, string $language): string
    {
        return $this->twig->render('analysis/page.html.twig', (new AnalysisPage($this->translator, $this->issues, $language, Yaml::parseFile($this->coloursFile)))->render($doc));
    }
}
