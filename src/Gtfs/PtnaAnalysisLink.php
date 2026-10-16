<?php

namespace App\Gtfs;

final readonly class PtnaAnalysisLink
{
    public function __construct(
        public string $id,
        public string $dataRef,
        public string $osmRoute,
        public bool $goodIdMatch,
        public bool $goodRouteMatch,
        public string $webPath,
        public string $fileName,
        public string $info,
    ) {
    }
}
