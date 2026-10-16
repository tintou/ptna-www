<?php

namespace App\Gtfs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The countries and the feeds listed on their /gtfs/<country> page (config/gtfs/countries.yaml).
 *
 * @phpstan-type Section array{id: string, anchor?: string, title?: string, intro?: string, feeds: list<string>}
 * @phpstan-type Country array{flag: string, flag_alt: string, title: string, notice?: string, sections: list<Section>}
 */
final class GtfsCountryCatalog
{
    /** @var array<string, Country>|null */
    private ?array $countries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/gtfs/countries.yaml')]
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
     * @return array<string, Country>
     */
    public function all(): array
    {
        return $this->countries ??= Yaml::parseFile($this->file);
    }
}
