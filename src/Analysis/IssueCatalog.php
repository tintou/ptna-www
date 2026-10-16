<?php

namespace App\Analysis;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The issues and notes of an analysis are stored by code, their texts are looked up here.
 */
final class IssueCatalog
{
    /** @var array<string, array{type: string, id?: string, plural?: string, plain?: string}>|null */
    private ?array $entries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/analysis/issues.yaml')]
        private readonly string $file,
    ) {
    }

    /** @return array{type: string, id?: string, plural?: string, plain?: string}|null */
    public function get(string $code): ?array
    {
        $this->entries ??= Yaml::parseFile($this->file);

        return $this->entries[$code] ?? null;
    }
}
