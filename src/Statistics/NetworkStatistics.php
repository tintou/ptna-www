<?php

namespace App\Statistics;

use App\Network\NetworkDetails;
use App\Network\NetworkDetailsReader;

/**
 * Builds the table of the individual OSM analyses (download, extract, analysis timings and sizes).
 */
final class NetworkStatistics
{
    public function __construct(private readonly NetworkDetailsReader $reader)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function table(): array
    {
        $rows = [];
        $downloadFiles = [];
        $filterFiles = [];
        $totals = ['networks' => 0, 'download_secs' => 0, 'filter_secs' => 0, 'analysis_secs' => 0, 'changes' => 0];

        foreach ($this->reader->allNetworks() as $network) {
            $details = $this->reader->read($network);
            ++$totals['networks'];
            if ($details === null || !$details->found()) {
                continue;
            }

            $row = $this->row($details);
            $rows[] = $row;

            if ($row['download']['secs'] !== null) {
                $totals['download_secs'] += $row['download']['secs'];
                $downloadFiles[$details->get('OSM_XML_FILE') . $row['download']['start']] = $row['xml_size'];
            }
            if ($row['filter']['secs'] !== null) {
                $totals['filter_secs'] += $row['filter']['secs'];
                $filterFiles[$details->get('OSM_XML_FILE') . $row['filter']['start']] = $row['xml_size'];
            }
            $totals['analysis_secs'] += $row['analysis']['secs'] ?? 0;
            $totals['changes'] += $row['changes']['link'] !== null ? 1 : 0;
        }

        $totals['download_files'] = count($downloadFiles);
        $totals['download_mb'] = array_sum($downloadFiles) / 1024 / 1024;
        $totals['filter_files'] = count($filterFiles);
        $totals['filter_mb'] = array_sum($filterFiles) / 1024 / 1024;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NetworkDetails $d): array
    {
        $startDownload = $d->get('START_DOWNLOAD');
        $endDownload = $d->get('END_DOWNLOAD');
        $startFilter = $d->get('START_FILTER');
        $endFilter = $d->get('END_FILTER');
        $startAnalysis = $d->get('START_ANALYSIS');
        $endAnalysis = $d->get('END_ANALYSIS');
        $xmlFile = $d->get('OSM_XML_FILE');
        $xmlSize = $d->osmXmlFileSizeByte();
        $extractSize = (int) $d->get('EXTRACT_SIZE_BYTE', '0');
        $osmBase = $d->get('OSM_BASE');

        $download = ['start' => $startDownload, 'secs' => null, 'size' => 'none'];
        if ($startDownload !== '' && $endDownload !== '') {
            $download['secs'] = (int) strtotime($endDownload) - (int) strtotime($startDownload);
        }
        if ($xmlFile !== '' && $xmlSize > 0) {
            if ($download['secs'] !== null) {
                $download['size'] = 'size';
            } else {
                $download['size'] = $extractSize > 0 ? 'none' : 'reused';
            }
        } elseif ($xmlFile !== '') {
            $download['size'] = 'failed';
        }

        $filter = ['start' => $startFilter, 'secs' => null];
        if ($startFilter !== '' && $endFilter !== '') {
            $filter['secs'] = max(1, (int) strtotime($endFilter) - (int) strtotime($startFilter));
        }

        $analysis = ['start' => $startAnalysis, 'secs' => null, 'link' => null, 'empty' => false];
        if ($startAnalysis !== '' && $endAnalysis !== '') {
            $analysis['secs'] = max(1, (int) strtotime($endAnalysis) - (int) strtotime($startAnalysis));
            $size = $d->analysisFileSize();
            if ($size !== null && $size > 0) {
                $analysis['link'] = $d->analysisWebPath;
            } elseif ($size === 0) {
                $analysis['empty'] = true;
            }
        }

        // The OSM data should not be much older than the download or extract it is used for
        $osmBaseOutdated = null;
        if ($osmBase !== '' && ($startDownload !== '' || $startFilter !== '')) {
            $allowedHours = $startFilter !== '' ? 4 : 6;
            $start = (int) strtotime($startFilter !== '' ? $startFilter : $startDownload);
            if ($start - (int) strtotime($osmBase) > $allowedHours * 3600) {
                $osmBaseOutdated = $allowedHours;
            }
        }

        $htmlDiff = (int) $d->get('HTML_DIFF', '0');

        return [
            'network' => $d->network,
            'utc' => $d->get('UTC'),
            'tz_short' => $d->timezoneShort(),
            'tz_name' => $d->timezoneName(),
            'osm_base' => $osmBase,
            'osm_base_outdated' => $osmBaseOutdated,
            'xml_size' => $xmlSize,
            'download' => $download,
            'filter' => $filter,
            'routes' => $this->routes($d),
            'analysis' => $analysis,
            'changes' => [
                'link' => $d->hasChanges() ? $d->diffWebPath : null,
                'count' => $htmlDiff > 0 ? $htmlDiff : null,
            ],
            'missing_data' => (int) $d->get('ERROR_MISSING_DATA', '0'),
            'getid_notfound' => (int) $d->get('ERROR_GETID_NOTFOUND', '0'),
        ];
    }

    /**
     * @return array{state: string, date: string, link: string}
     */
    private function routes(NetworkDetails $d): array
    {
        $link = $d->routesLink();
        $date = $d->get('ROUTES_TIMESTAMP_LOC');
        $ret = $d->get('ROUTES_RET');

        if ((int) $d->get('ROUTES_SIZE', '0') > -1) {
            return ['state' => $date === '' ? 'not_configured' : 'ok', 'date' => $date, 'link' => $link];
        }

        $state = match (true) {
            $ret === '11' && $link !== '' => 'wiki_missing',
            $ret === '99' && $link !== '' => 'wiki_redirect',
            $ret === '98' && $link !== '' => 'wiki_stored',
            default => 'wiki_failed',
        };

        return ['state' => $state, 'date' => '', 'link' => $link];
    }
}
