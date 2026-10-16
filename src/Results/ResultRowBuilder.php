<?php

namespace App\Results;

use App\Network\NetworkDetails;
use App\Network\NetworkDetailsReader;

/**
 * Prepares the data of one table row of a /results/<country> page from the details file of the network.
 *
 * All values are plain data, escaping is done by the template. Links taken from the details files are only
 * passed on if they are http(s) links.
 *
 * @phpstan-type Link array{url: string|null, text: string}
 * @phpstan-type Row array{
 *     network: string,
 *     analysis: array{url: string|null, state: 'ok'|'empty'|'none'},
 *     region: Link,
 *     network_links: list<Link>,
 *     new_date: array{utc: string, local: string}|null,
 *     old_date: array{utc: string, local: string, state: string, url: string}|null,
 *     config_lang: string,
 *     test_csv: bool,
 *     routes: Link,
 * }
 */
final class ResultRowBuilder
{
    public function __construct(
        private readonly NetworkDetailsReader $reader,
    ) {
    }

    /**
     * @return Row
     */
    public function build(string $network, string $configLang): array
    {
        $details = $this->reader->read($network) ?? new NetworkDetails($network, [], '', '', '', '');

        $regionName = $details->get('REGION_NAME');
        $regionUrl = null;
        if ($regionName !== '' && $details->get('REGION_LINK') !== '') {
            $regionUrl = '/results/search-area?network=' . rawurlencode($network);
            $lang = $details->get('lang', 'en');
            if ($lang !== 'en') {
                $regionUrl .= '&lang=' . rawurlencode($lang);
            }
        }

        $diffFile = str_replace('-Analysis.html', '-Analysis.diff.html', $details->analysisFile);
        $diffUrl = $details->diffWebPath;
        $newUtc = $details->get('NEW_DATE_UTC');
        $newLocal = $details->get('NEW_DATE_LOC');
        $oldUtc = $details->get('OLD_DATE_UTC');
        $oldLocal = $details->get('OLD_DATE_LOC');
        $oldOrNew = $details->get('OLD_OR_NEW', 'old');

        return [
            'network' => $network,
            'analysis' => $this->analysis($details),
            'region' => ['url' => $regionUrl, 'text' => $regionName],
            'network_links' => $this->networkLinks($details->get('NETWORK_LINK'), $details->get('NETWORK_NAME')),
            'new_date' => $newUtc !== '' && $newLocal !== '' ? ['utc' => $newUtc, 'local' => $newLocal] : null,
            'old_date' => $oldUtc !== '' && $oldLocal !== '' && $oldOrNew !== '' && is_file($diffFile)
                ? ['utc' => $oldUtc, 'local' => $oldLocal, 'state' => $oldOrNew === 'new' ? 'new' : 'old', 'url' => $diffUrl]
                : null,
            'config_lang' => substr($configLang, 0, 2),
            'test_csv' => $details->catalogFile !== '' && is_file($details->catalogFile),
            'routes' => [
                'url' => $details->get('ROUTES_NAME') !== '' ? self::safeLink($details->get('ROUTES_LINK')) : null,
                'text' => $details->get('ROUTES_NAME'),
            ],
        ];
    }

    /**
     * @return array{url: string|null, state: 'ok'|'empty'|'none'}
     */
    private function analysis(NetworkDetails $details): array
    {
        $json = preg_replace('/\.html$/', '.json', $details->analysisFile);
        $webPath = $details->analysisWebPath;
        if (is_file($json)) {
            // rendered from the JSON file
            $size = (int) filesize($json);
            $webPath = preg_replace('/\.html$/', '', $webPath);
        } else {
            $size = $details->analysisFileSize();
        }
        if ($size === null || $webPath === '') {
            return ['url' => null, 'state' => 'none'];
        }

        return $size > 0
            ? ['url' => $webPath, 'state' => 'ok']
            : ['url' => null, 'state' => 'empty'];
    }

    /**
     * The network names and links are ';' separated lists in the details file.
     *
     * @return list<Link>
     */
    private function networkLinks(string $link, string $name): array
    {
        if ($name === '') {
            return [];
        }
        if (!str_contains($link, ';') || !str_contains($name, ';')) {
            return [['url' => self::safeLink($link), 'text' => $name]];
        }

        $names = explode(';', $name);
        $links = explode(';', $link);
        $result = [];
        foreach ($names as $i => $n) {
            $l = $links[$i] ?? '';
            $safe = $l !== '' ? self::safeLink($l) : null;
            if ($n !== '') {
                $result[] = ['url' => $safe, 'text' => $n];
            } elseif ($safe !== null) {
                $result[] = ['url' => $safe, 'text' => $safe];
            }
        }

        return $result;
    }

    private static function safeLink(string $link): ?string
    {
        return preg_match('#^https?://#i', $link) === 1 ? $link : null;
    }
}
