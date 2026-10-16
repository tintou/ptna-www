<?php

namespace App\Analysis;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translates and formats the messages of an analysis document (see ptna-analysis.schema.json) as HTML.
 */
final class MessageRenderer
{
    private const LIST_SEPARATORS = ['comma' => ', ', 'or' => null, 'or_ref' => null, 'role_or' => "', 'role' = '"];

    private const GTFS_PAGES = ['route' => 'trips.php', 'trip' => 'single-trip.php', 'shape' => 'shape.php'];
    private const GTFS_CLASSES = ['valid' => 'gtfs-datevalid', 'past' => 'gtfs-dateold', 'future' => 'gtfs-datenew', 'previous' => 'gtfs-dateprevious'];
    private const GTFS_LABELS = ['valid' => 'GTFS', 'past' => 'GTFS?', 'future' => 'GTFS?', 'previous' => 'GTFS??'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly IssueCatalog $issues,
        private readonly string $language,
    ) {
    }

    public function tr(string $id, ?string $plural = null, int|string|null $n = null): string
    {
        if ($plural === null) {
            return $this->translator->trans($id, [], 'analysis', $this->language);
        }

        // The loaded .po files hold "singular|plural" under the singular msgid; untranslated ones fall back to English.
        $translated = $this->translator->trans($id, ['%count%' => (int) $n], 'analysis', $this->language);

        return $translated !== $id ? $translated : ((int) $n === 1 ? $id : $plural);
    }

    /** Renders a message: plain strings are used as they are. */
    public function rt(mixed $message): string
    {
        if (!is_array($message)) {
            return (string) ($message ?? '');
        }
        $kind = $message['kind'] ?? (isset($message['code']) ? 'issue' : null);

        return match ($kind) {
            'tr' => $this->tr($message['msgid'], $message['plural'] ?? null, $message['n'] ?? null),
            'fmt' => self::sprintf($this->rt($message['format']), array_map($this->rt(...), $message['args'])),
            'cat' => implode('', array_map($this->rt(...), $message['parts'])),
            'join' => implode($this->rt($message['separator']), array_map($this->rt(...), $message['items'])),
            'list' => implode($this->listSeparator($message['style']), array_map($this->rt(...), $message['items'])),
            'listed' => $this->listed($this->rt($message['head']), $message['items'], $message['more'] ?? null),
            'issue' => $this->issue($message),
            'tags' => $this->tags($message['tags']),
            'foreign' => $this->foreign($this->rt($message['value'])),
            'text' => self::escape($this->rt($message['value'])),
            'anchor' => sprintf('<a href="#%s">%s</a>', $message['id'], $this->rt($message['text'])),
            'relation' => $this->relationLink($message['object']),
            'way' => $this->objectLink($message['object'], 'way'),
            'node' => $this->objectLink($message['object'], 'node'),
            'inject' => $this->injectLink($message['relation'], $message['tags']),
            'gtfs' => $this->gtfsLink($message['object']),
            'gtfs_status' => $this->gtfsStatus($message['object']),
            'gtfs_compare' => $this->gtfsCompare($message['object']),
            'gtfs_scores' => $this->gtfsScores(),
            default => '',
        };
    }

    private function listSeparator(string $style): string
    {
        return match ($style) {
            'or' => ' '.$this->tr('or').' ',
            'or_ref' => $this->tr("' or 'ref'='"),
            default => self::LIST_SEPARATORS[$style] ?? ', ',
        };
    }

    /** @param list<mixed> $items */
    private function listed(string $head, array $items, int|string|null $more): string
    {
        $list = implode(', ', array_map($this->rt(...), $items));

        return $more !== null
            ? self::sprintf($this->tr('%s: %s and %d more ...'), [$head, $list, $more])
            : sprintf('%s: %s', $head, $list);
    }

    /** @param array<string, mixed> $issue */
    private function issue(array $issue): string
    {
        $entry = $this->issues->get($issue['code']) ?? ['plain' => $issue['code']];
        $text = $entry['plain'] ?? $this->tr($entry['id'], $entry['plural'] ?? null, $issue['n'] ?? null);
        if (isset($issue['args'])) {
            // Plain formats (like "'%s' = '%s'") show data taken from OSM: the document does not mark it as foreign
            $foreign = isset($entry['plain']);
            $text = self::sprintf($text, array_map(
                fn (mixed $arg): string => match (true) {
                    is_array($arg) && array_is_list($arg) => $this->tags($arg),
                    $foreign && is_string($arg) => $this->foreign($arg),
                    default => $this->rt($arg),
                },
                $issue['args'],
            ));
        }

        return isset($issue['items']) ? $this->listed($text, $issue['items'], $issue['more'] ?? null) : $text;
    }

    /** @param list<array{key: mixed, value: mixed}> $tags */
    private function tags(array $tags): string
    {
        return implode(', ', array_map(
            fn (array $tag): string => sprintf("'%s'='%s'", $this->rt($tag['key']), $this->rt($tag['value'])),
            $tags,
        ));
    }

    /** Like Perl's sprintf(): missing arguments are empty, '#' is understood. */
    public static function sprintf(string $format, array $args): string
    {
        $args = array_values($args);

        return preg_replace_callback('/%(%|([#0\- +]*)(\d*)(?:\.(\d+))?([sdx]))/', static function (array $m) use (&$args): string {
            if ($m[1] === '%') {
                return '%';
            }
            $value = array_shift($args) ?? '';
            $prefix = ($m[5] === 'x' && str_contains($m[2], '#')) ? '0x' : '';
            $spec = '%'.str_replace('#', '', $m[2]).$m[3].($m[4] !== '' ? '.'.$m[4] : '').$m[5];

            return $prefix.sprintf($spec, $m[5] === 's' ? (string) $value : (int) $value);
        }, $format) ?? $format;
    }

    public static function escape(mixed $text): string
    {
        $text = (string) ($text ?? '');
        if ($text === '' || $text === '0') {
            return $text;
        }

        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#039;']);
    }

    public static function uriEscape(mixed $text): string
    {
        return preg_replace_callback('/[ !"#$%&\'()*+,.\/:;<=>?@\[\\\\\]{|}-]/', static fn (array $m): string => sprintf('%%%x', ord($m[0])), (string) ($text ?? '')) ?? '';
    }

    public static function gtfsUriEscape(mixed $text): string
    {
        return preg_replace_callback('/[^A-Za-z0-9\-_.!~*()]/', static fn (array $m): string => sprintf('%%%x', ord($m[0])), (string) ($text ?? '')) ?? '';
    }

    public function foreign(mixed $text): string
    {
        return '<span class="foreign" dir="auto">'.self::escape($text).'</span>';
    }

    /** Converts the wiki markup used in the texts of the analysis to HTML. */
    public static function wiki2html(string $text, bool $suppressLinks = false): string
    {
        if ($text === '') {
            return $text;
        }
        $text = strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
        $text = preg_replace('/\[\[Category:[^\]]+\]\]/', '', $text);

        if ($suppressLinks) {
            $text = preg_replace('/\[\[([^|\]]+)\|([^\]]+)\]\]/', '$2', $text, 1);
            $text = preg_replace('/\[\[([^|\]]+?)\]\]/', '$1', $text, 1);
            $text = preg_replace('/\[([^ \]]+) ([^\]]+)\]/', '$2', $text);
            $text = preg_replace('/\[([^ \]]+)\]/', '$1', $text);
        } else {
            $text = preg_replace('~\[\[([^|\]]+)\|([^\]]+)\]\]~', '<a href="https://wiki.openstreetmap.org/wiki/$1">$2</a>', $text);
            $text = preg_replace('~\[\[([^|\]]+?)\]\]~', '<a href="https://wiki.openstreetmap.org/wiki/$1">$1</a>', $text);
            $text = preg_replace('/\[([^ \]]+) ([^\]]+)\]/', '<a href="$1">$2</a>', $text);
            $text = preg_replace('/\[([^ \]]+)\]/', '<a href="$1">$1</a>', $text);
        }

        $text = preg_replace('/!!!(.+?)!!!/', '<span class="attention">$1</span>', $text);
        $text = preg_replace("/'''''(.+?)'''''/", '<strong><em>$1</em></strong>', $text);
        $text = preg_replace("/'''(.+?)'''/", '<strong>$1</strong>', $text);
        $text = preg_replace("/''(.+?)''/", '<em>$1</em>', $text);

        return str_replace("'", '&#039;', $text);
    }

    /** @param array<string, mixed> $link */
    public function relationLink(array $link): string
    {
        $id = $link['id'] ?? null;
        if (!$id) {
            return '';
        }
        $info = isset($link['label']) ? sprintf("'%s' ", $this->foreign($link['label'])) : '';
        $image = '<img src="/img/Relation.svg" alt="Relation" />';

        if ($id <= 0) {
            $value = sprintf('%s %s%s', $image, $info, $id);

            return !empty($link['gtfs_tag']) ? $value.sprintf(' <small>(%s)</small>', $this->rt($link['gtfs_tag'])) : $value;
        }

        $links = [
            sprintf('<a href="https://osm.org/edit?editor=id&amp;relation=%s" title="%s">iD</a>', $id, self::escape($this->tr('Edit in iD'))),
            sprintf('<a href="http://127.0.0.1:8111/load_object?new_layer=false&amp;relation_members=true&amp;objects=r%s" target="hiddenIframe" title="%s">JOSM</a>', $id, self::escape($this->tr('Edit in JOSM'))),
        ];
        if (!empty($link['relatify'])) {
            $links[] = sprintf('<a href="https://relatify.monicz.dev/?relation=%s&load=1" target="blank" title="%s">Relatify</a>', $id, self::escape($this->tr('Edit in Relatify')));
        }
        if (!empty($link['show'])) {
            $links[] = sprintf('<a href="/relation.php?id=%d&lang=%s" title="%s">PTNA</a>', $id, self::uriEscape($this->language), self::escape($this->tr('Show relation on special map')));
        }
        if (!empty($link['unroll'])) {
            $links[] = sprintf('<a href="https://jungle-bus.github.io/unroll/route.html?qa=yes&line=%d" title="%s">UNROLL</a>', $id, self::escape($this->tr("Show route details on jungle-bus' unroll page")));
        }
        if (!empty($link['gtfs_tag'])) {
            $links[] = $this->rt($link['gtfs_tag']);
        }

        return sprintf(
            '%s %s<a href="https://osm.org/relation/%s" title="%s">%s</a> <small>(%s)</small>',
            $image,
            $info,
            $id,
            self::escape($this->tr('Browse on map')),
            $id,
            implode(', ', $links),
        );
    }

    /** @param array<string, mixed> $link */
    public function objectLink(array $link, string $type): string
    {
        $id = $link['id'] ?? null;
        if (!$id) {
            return '';
        }
        $info = isset($link['label']) ? sprintf("'%s' ", $this->foreign($link['label'])) : '';
        $image = sprintf('<img src="/img/%s.svg" alt="%s" />', ucfirst($type), ucfirst($type));

        if ($id <= 0) {
            return sprintf('%s %s%s', $image, $info, $id);
        }

        return sprintf(
            '%s %s<a href="https://osm.org/%s/%s" title="%s">%s</a> <small>(<a href="https://osm.org/edit?editor=id&amp;%s=%s" title="%s">iD</a>, <a href="http://127.0.0.1:8111/load_object?new_layer=false&amp;objects=%s%s" target="hiddenIframe" title="%s">JOSM</a>)</small>',
            $image,
            $info,
            $type,
            $id,
            self::escape($this->tr('Browse on map')),
            $id,
            $type,
            $id,
            self::escape($this->tr('Edit in iD')),
            $type[0],
            $id,
            self::escape($this->tr('Edit in JOSM')),
        );
    }

    /** @param list<string> $tags 'key=value' pairs */
    private function injectLink(mixed $relation, array $tags): string
    {
        $params = [];
        $title = $this->tr('Inject')."\n";
        foreach ($tags as $kv) {
            $params[] = self::uriEscape($kv);
            $title .= sprintf("- '%s'\n", preg_replace('/=/', "' = '", $kv, 1));
        }
        $title .= sprintf($this->tr('into relation %s using JOSM'), $relation);

        return ' <a href="http://127.0.0.1:8111/load_object?new_layer=false&relation_members=true&objects=r'.$relation
            .'&addtags='.implode(self::uriEscape('|'), $params)
            .'" target="hiddenIframe" title="'.self::escape($title)
            .'"><img src="/img/Inject32.png" alt="'.self::escape($this->tr('Inject data using JOSM')).'" height="18" width="18" /></a>';
    }

    public function gtfsFeedLink(array $cell): string
    {
        $feed = $cell['feed'] ?? '';
        $date = $cell['release_date'] ?? '';
        if (empty($cell['linked'])) {
            return self::escape($feed);
        }

        return sprintf(
            '<a href="/gtfs/%s/routes.php?feed=%s&release_date=%s" title="GTFS-Feed: %s, GTFS-Release-Date: %s">%s</a>',
            self::gtfsUriEscape(self::gtfsCountry($feed)),
            self::gtfsUriEscape($feed),
            self::gtfsUriEscape($date),
            self::escape($feed),
            self::escape($date),
            self::escape($feed),
        );
    }

    private static function gtfsCountry(string $feed): string
    {
        return preg_replace('/-.*$/', '', $feed);
    }

    /** Describes the status of a GTFS object, as part of the title of a link. */
    private function gtfsStatusText(array $link): string
    {
        $range = ($link['from'] ?? '').' - '.($link['to'] ?? '');

        return match ($link['status']) {
            'valid' => $range,
            'past' => self::escape($this->tr('is no longer valid (in the past)').': '.$range),
            'future' => self::escape($this->tr('is not yet valid (in the future)').': '.$range),
            'previous' => self::escape($this->tr('is outdated, fits to older GTFS version only')),
            'no_shapes' => self::escape($this->tr("does not provide any 'shape' data")),
            default => self::escape($this->tr('does not exist')),
        };
    }

    private function gtfsLink(array $link): string
    {
        $status = $link['status'];
        $label = !empty($link['route_only']) ? 'GTFS(r)' : 'GTFS';
        $feed = $link['feed'] ?? '';
        $date = $link['release_date'] ?? '';

        if ($status === 'no_feed') {
            return sprintf(
                '<a class="bad-link" href="/gtfs/" title="\'GTFS feed\' %s. %s">%s!</a>',
                self::escape($this->tr('is not set')),
                self::escape($this->tr('GTFS database not found')),
                $label,
            );
        }
        if ($status === 'no_db') {
            return sprintf(
                '<a class="bad-link" href="/gtfs/" title="GTFS-Feed: %s, GTFS-Release-Date: %s : %s.">%s!</a>',
                self::escape($feed),
                self::escape($date),
                self::escape($this->tr('GTFS database not found')),
                $label,
            );
        }

        $country = self::gtfsUriEscape(self::gtfsCountry($feed));
        $query = sprintf('feed=%s&release_date=%s', self::gtfsUriEscape($feed), self::gtfsUriEscape($date));
        $prefix = sprintf('GTFS-Feed: %s, GTFS-Release-Date: %s', self::escape($feed), self::escape($date));

        if ($status === 'no_id') {
            return sprintf(
                '<a class="bad-link" href="/gtfs/%s/routes.php?%s" title="%s, \'%s\' %s.">%s!</a>',
                $country,
                $query,
                $prefix,
                self::escape($link['tag']),
                self::escape($this->tr('is not set')),
                $label,
            );
        }

        $what = $link['what'];
        $id = $link['id'];
        $text = preg_replace('/^GTFS/', $label, self::GTFS_LABELS[$status] ?? 'GTFS!');

        return sprintf(
            '%s<a class="%s" href="/gtfs/%s/%s?%s&%s_id=%s" title="%s, \'%s\' = \'%s\' %s%s">%s</a>',
            ($what === 'trip' && $status === 'future') ? ', ' : '',
            self::GTFS_CLASSES[$status] ?? 'bad-link',
            $country,
            self::GTFS_PAGES[$what],
            $query,
            $what,
            self::gtfsUriEscape($id),
            $prefix,
            self::escape($link['tag']),
            self::escape($id),
            $this->gtfsStatusText($link),
            $status === 'valid' ? '' : '.',
            $text,
        );
    }

    private function gtfsStatus(array $link): string
    {
        return match ($link['status']) {
            'no_db' => sprintf('GTFS-Feed: %s, GTFS-Release-Date: %s : %s', $link['feed'] ?? '', $link['release_date'] ?? '', $this->tr('GTFS database not found')),
            'no_feed' => sprintf("'GTFS feed' %s. %s", $this->tr('is not set'), $this->tr('GTFS database not found')),
            'no_id' => sprintf("'%s' %s", $link['tag'], $this->tr('is not set')),
            default => sprintf("'%s' = '%s' %s", $link['tag'], $link['id'], $this->gtfsStatusText($link)),
        };
    }

    private function gtfsCompare(array $link): string
    {
        $trip = $link['what'] === 'trip';

        return sprintf(
            ', <a href="/gtfs/%s?feed=%s&release_date=%s&%s_id=%s&relation=%s" target="_blank"><img src="/img/compare19.png" title="%s" style="height: 15px;width: 15px;vertical-align: middle;"></a>',
            $trip ? 'compare-trips.php' : 'compare-routes.php',
            self::gtfsUriEscape($link['feed']),
            self::gtfsUriEscape($link['release_date']),
            $link['what'],
            self::gtfsUriEscape($link['id']),
            self::gtfsUriEscape($link['relation']),
            self::escape($trip ? $this->tr('Compare GTFS trip with OSM route') : $this->tr('Compare GTFS route with OSM route_master/route')),
        );
    }

    /** The scores are a placeholder which links to the explanation. */
    private function gtfsScores(): string
    {
        return '<span style="background-color: #6aef00;" title="GTFS trips versus OSM route relations">4 vs 4</span> '
            .'=&gt; '
            .'<span style="background-color: #6aef00;" title="Best score for OSM route xxx">0.76%</span> '
            .'<span style="background-color: #aecd00;" title="Best score for OSM route xxx">8.13%</span> '
            .'<span style="background-color: #6aef00;" title="Best score for OSM route xxx">0.22%</span> '
            .'<span style="background-color: #aecd00;" title="Best score for OSM route xxx">7.70%</span>'
            .' &nbsp;<a target="_blank" href="https://community.openstreetmap.org/t/ptna-news-for-public-transport-network-analysis/8383/685" title="'.$this->tr('HELP!').'">'.$this->tr('Help').'</a>';
    }
}
