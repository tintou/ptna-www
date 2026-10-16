<?php

namespace App\Results;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The countries of the /results pages and the networks listed on them (config/results/countries.yaml).
 *
 * @phpstan-type Heading array{id: string, text: string, flag?: string, flag_alt?: string}
 * @phpstan-type Block array{type: 'heading'|'intro', id?: string, text?: string, flag?: string, flag_alt?: string, lang?: string}
 * @phpstan-type Section array{id: string, th_lang: string, blocks?: list<Block>, networks: list<array{0: string, 1: string, 2?: string}>}
 * @phpstan-type Country array{lang: string, title: string, landing: array{flag: string, alt: string, name: string}, heading: Heading, nav?: list<array{href: string, text: string}>, sections: list<Section>}
 */
final class ResultsCatalog
{
    /** @var array<string, Country>|null */
    private ?array $countries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/results/countries.yaml')]
        private readonly string $file,
    ) {
    }

    /**
     * @return Country|null
     */
    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * @return array<string, Country> in the order of the landing page
     */
    public function all(): array
    {
        return $this->countries ??= Yaml::parseFile($this->file)['countries'];
    }
}
