<?php

namespace App\Network;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Test run of the injection of GTFS data into the CSV table of a network's OSM-Wiki routes page.
 * Nothing is written back to the Wiki.
 */
final class WikiCsvInjector
{
    private const DEFAULT_BIN_DIR = '/home/toni/ptna/bin';

    private readonly string $tmpDir;
    private readonly string $binDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
        #[Autowire('%env(default::PTNA_BIN_DIR)%')]
        ?string $binDir,
        private readonly Filesystem $fs,
    ) {
        $this->tmpDir = rtrim($workDir, '/') . '/tmp/';
        $this->binDir = rtrim($binDir ?: self::DEFAULT_BIN_DIR, '/');
    }

    /**
     * @return array{result: string, read_log: string, inject_log: string, error: string}
     */
    public function inject(NetworkDetails $details): array
    {
        $out = ['result' => '', 'read_log' => '', 'inject_log' => '', 'error' => ''];

        $page = preg_replace('/^.*\/wiki\//', '', $details->routesLink());
        if (!is_file($details->catalogFile)) {
            $out['error'] = sprintf("Nothing to inject, catalog file '%s-catalog.json' does not exist", $details->network);

            return $out;
        }
        if ($page === '' || $page === null) {
            $out['error'] = 'Error: no OSM-Wiki routes page configured';

            return $out;
        }

        $this->fs->mkdir($this->tmpDir);
        $template = $this->fs->tempnam($this->tmpDir, $details->network . '-Routes-temp-', '.txt');
        $injected = $this->fs->tempnam($this->tmpDir, $details->network . '-Routes-injected-', '.txt');

        try {
            $read = new Process([$this->binDir . '/ptna-wiki-page.pl', '--pull', '--page=' . $page, '--file=' . $template]);
            $read->setTimeout(120);
            $read->run();
            $out['read_log'] = $read->getOutput() . $read->getErrorOutput();

            if (!$read->isSuccessful() || filesize($template) === 0) {
                $out['error'] = "Error: executing 'read from Wiki' command for page '" . $page . "'";

                return $out;
            }

            $inject = new Process([
                $this->binDir . '/ptnaFillCsvData.py', '--show-hidden-variables',
                '--routes', $details->catalogFile, '--template', $template, '--outfile', $injected,
            ]);
            $inject->setTimeout(300);
            $inject->run();
            $out['inject_log'] = $inject->getOutput() . $inject->getErrorOutput();
            $out['result'] = (string) file_get_contents($injected);
        } finally {
            $this->fs->remove([$template, $injected]);
        }

        return $out;
    }
}
