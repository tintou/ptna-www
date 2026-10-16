<?php

namespace App\Network;

/**
 * The search areas (Overpass API, 'osmium extract', 'osmium getid') of a network as recorded in its details file.
 */
final class SearchAreaReader
{
    /**
     * @return array{
     *     region: string,
     *     network_name: string,
     *     network_link: string,
     *     overpass_area: string,
     *     overpass_code: string,
     *     extract_name: string,
     *     extract_data: string,
     *     getid_name: string,
     *     getid_data: string,
     * }
     */
    public function read(NetworkDetails $details): array
    {
        [$extractName, $extractData] = $this->areaFile($details->get('EXTRACT_SEARCH_AREA'));
        [$getidName, $getidData] = $this->areaFile($details->get('EXTRACT_GETID_AREA'));
        $link = $details->get('NETWORK_LINK');

        return [
            'region' => $details->get('REGION_NAME'),
            'network_name' => $details->get('NETWORK_NAME'),
            'network_link' => preg_match('#^https?://#i', $link) === 1 ? $link : '',
            'overpass_area' => $details->get('OVERPASS_SEARCH_AREA'),
            'overpass_code' => preg_replace('/^[a-z]*/', 'relation', urldecode($details->get('OVERPASS_SEARCH_AREA'))) ?? '',
            'extract_name' => $extractName,
            'extract_data' => $extractData,
            'getid_name' => $getidName,
            'getid_data' => $getidData,
        ];
    }

    /**
     * @return array{0: string, 1: string} name and content of a '.poly' or '.geojson' file, empty if not readable
     */
    private function areaFile(string $filename): array
    {
        if ($filename === '' || preg_match('/\.(poly|geojson)$/', $filename) !== 1 || !is_file($filename)) {
            return ['', ''];
        }

        $data = file_get_contents($filename);
        if ($data === false) {
            return ['', ''];
        }

        return [basename(basename($filename, '.poly'), '.geojson'), preg_replace('/  +/', ' ', $data) ?? $data];
    }
}
