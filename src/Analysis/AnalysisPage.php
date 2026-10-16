<?php

namespace App\Analysis;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders an analysis document (see ptna-analysis.schema.json) as the HTML page of the analysis.
 *
 * The sections, their titles, numbers and anchors, the table of contents and the explanatory texts
 * are not part of the document, they are derived here.
 */
final class AnalysisPage
{
    private const TOPIC_ORDER = [
        'unassigned', 'lines', 'lines_without_ref', 'life_cycle_prefix', 'excluded', 'morerelations',
        'networkdetails', 'considerednetworks', 'addednetworks', 'notconsiderednetworks', 'operatordetails',
        'gtfsreferences', 'gtfsreferences_csv', 'gtfsreferences_relations', 'conditional_on_ways',
    ];
    private const SUB_TOPICS = ['considerednetworks', 'addednetworks', 'notconsiderednetworks', 'gtfsreferences_csv', 'gtfsreferences_relations'];
    private const ROUTE_TYPES = ['train', 'subway', 'light_rail', 'tram', 'trolleybus', 'bus', 'ferry', 'monorail', 'aerialway', 'funicular', 'share_taxi', 'coach'];
    private const SORT_TYPES = ['ref' => 'number', 'relation' => 'number', 'number' => 'number', 'conditional_access' => 'string', 'key' => 'string', 'value' => 'string'];

    private MessageRenderer $messages;

    /** @var array<string, true> */
    private array $usedIds = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @param array<string, string> $colours HTML/CSS colour names and their '#rrggbb' values */
    public function __construct(TranslatorInterface $translator, IssueCatalog $issues, private readonly string $language, private readonly array $colours)
    {
        $this->messages = new MessageRenderer($translator, $issues, $language);
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed> the model of the page, see templates/analysis/page.html.twig
     */
    public function render(array $doc): array
    {
        $this->meta = $doc['meta'] ?? [];
        $sections = $this->sections($doc);
        $state = [];
        $model = [];
        $toc = [];
        $last = 0;
        foreach ($sections as $section) {
            $blocks = [...$this->pageNotes($section), ...$this->helpAfter($section, $state)];
            foreach ($section['content'] as $block) {
                $blocks = [...$blocks, ...$this->helpBefore($block, $state), ...array_filter([$this->node($block)])];
            }
            if (!empty($section['text'])) {
                $level = $section['level'];
                $toc[] = ['closes' => $level <= $last ? 1 + max(0, $last - $level) : 0, 'opens' => max(0, $level - $last), 'number' => $section['number'], 'id' => $section['id'], 'html' => MessageRenderer::wiki2html($this->rt($section['text']), true)];
                $last = $level;
            }
            $model[] = ['header' => $this->header($section), 'blocks' => $blocks];
        }

        $title = $this->meta['title'] ?? null;

        return [
            'language' => $this->language,
            'title' => $title ? $this->rt($title) : 'Results',
            'contents' => $this->tr('Contents'),
            'toc' => $toc,
            'toc_closes' => $last,
            'sections' => $model,
        ];
    }

    private function tr(string $id, ?string $plural = null, int|string|null $n = null): string
    {
        return $this->messages->tr($id, $plural, $n);
    }

    private function rt(mixed $message): string
    {
        return $this->messages->rt($message);
    }

    /** @return list<array<string, mixed>> */
    private function sections(array $doc): array
    {
        $routes = $doc['routes'] ?? [];
        $sections = [['level' => 1, 'topic' => (!empty($this->meta['osm_base']) || !empty($this->meta['areas'])) ? 'dates' : 'hints', 'content' => []]];

        // The headers of the routes list split it into sections
        $current = null;
        foreach ($routes as $block) {
            if ($block['type'] === 'header') {
                $sections[] = ['level' => $block['level'], 'text' => $block['text'], 'content' => []];
                $current = array_key_last($sections);
            } else {
                if ($current === null) {
                    $sections[] = ['level' => 1, 'content' => []];
                    $current = array_key_last($sections);
                }
                $sections[$current]['content'][] = $block;
            }
        }

        foreach (self::TOPIC_ORDER as $topic) {
            if (empty($doc[$topic])) {
                continue;
            }
            $collection = $doc[$topic];
            $name = ($topic === 'lines' && $routes) ? 'other_lines' : $topic;
            $isList = array_is_list($collection);
            $content = $isList
                ? array_map(static fn (array $table): array => ['type' => 'table'] + $table, $collection)
                : ($collection['content'] ?? []);
            $sections[] = ['level' => in_array($topic, self::SUB_TOPICS, true) ? 2 : 1, 'topic' => $name, 'content' => $content];

            $groups = $isList ? null : ($collection['route_types'] ?? null);
            if (!$groups) {
                continue;
            }
            $types = array_keys($groups);
            $types = $topic === 'lines_without_ref' ? $this->sorted($types) : $this->routeTypeOrder($types);
            foreach ($types as $type) {
                $sections[] = ['level' => 2, 'text' => $this->routeTypeTitle($type), 'content' => $groups[$type]];
            }
        }

        $counters = array_fill(0, 7, 0);
        foreach ($sections as &$section) {
            $section['text'] ??= $this->topicTitle($section['topic'] ?? '');
            if (!empty($section['text'])) {
                $level = $section['level'];
                ++$counters[$level];
                for ($i = $level + 1; $i <= 6; ++$i) {
                    $counters[$i] = 0;
                }
                $section['number'] = implode('.', array_slice($counters, 1, $level));
                $section['id'] = $this->topicAnchor($section['topic'] ?? null) ?? 'A'.$section['number'];
            }
            $section['nav'] = $this->localNavigation($section['content']);
        }

        return $sections;
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /** @param list<string> $types @return list<string> */
    private function routeTypeOrder(array $types): array
    {
        $known = array_values(array_intersect(self::ROUTE_TYPES, $types));

        return [...$known, ...$this->sorted(array_diff($types, $known))];
    }

    private function routeTypeTitle(string $type): string
    {
        $names = [
            'bus' => 'Bus', 'coach' => 'Coach', 'share_taxi' => 'Share-Taxi', 'train' => 'Train/Suburban-Train',
            'tram' => 'Tram/Streetcar', 'subway' => 'Subway/Underground', 'light_rail' => 'Light-Rail',
            'trolleybus' => 'Trolley Bus', 'ferry' => 'Ferry', 'monorail' => 'Mono-Rail', 'aerialway' => 'Aerialway', 'funicular' => 'Funicular',
        ];

        return sprintf('%s (%s)', isset($names[$type]) ? $this->tr($names[$type]) : $type, $type);
    }

    private function topicAnchor(?string $topic): ?string
    {
        return match ($topic) {
            null => null,
            'lines' => 'otherslines',
            'other_lines' => 'otherlines',
            'lines_without_ref' => 'withoutref',
            'life_cycle_prefix' => 'life-cycle-prefix',
            default => $topic,
        };
    }

    private function topicTitle(string $topic): ?string
    {
        $id = match ($topic) {
            'dates' => 'Date of Data',
            'hints' => 'Hints',
            'unassigned' => 'Not clearly assigned routes',
            'other_lines' => 'Other Public Transport Lines',
            'lines' => 'Public Transport Lines',
            'lines_without_ref' => "Public Transport Lines without 'ref'",
            'life_cycle_prefix' => 'Routes with life-cycle prefix',
            'excluded' => "Excluded relations - excluded by 'key-value' pair",
            'morerelations' => 'More Relations',
            'networkdetails' => "Details for 'network'-Values",
            'operatordetails' => "Details for 'operator'-Values",
            'gtfsreferences' => 'References to GTFS feeds and releases',
            'conditional_on_ways' => 'Conditional access statements found on ways',
            'considerednetworks' => "Considered 'network'-Values",
            'addednetworks' => "Additionally considered 'network'-Values",
            'notconsiderednetworks' => "Not Considered 'network'-Values",
            'gtfsreferences_csv' => 'References to GTFS from CSV list',
            'gtfsreferences_relations' => 'References to GTFS from relations',
            default => null,
        };

        return $id === null ? null : $this->tr($id);
    }

    /** Links to the lines of the tables of a section. @return list<array<string, mixed>> */
    private function localNavigation(array $content): array
    {
        $nav = [];
        foreach ($content as $table) {
            if ($table['type'] !== 'table') {
                continue;
            }
            foreach ($table['rows'] as $row) {
                if ($row['type'] !== 'subheader' || !isset($row['id']) || $row['id'] === '') {
                    continue;
                }
                $text = implode(' ', array_map($this->rt(...), $row['refs'] ?? []));
                if ($text !== '') {
                    $nav[] = ['id' => $row['id'], 'text' => $text, 'colour' => $row['colour'] ?? '', 'text_colour' => $row['text_colour'] ?? ''];
                }
            }
        }

        return $nav;
    }

    private function esc(mixed $text): string
    {
        return MessageRenderer::escape($text);
    }

    /** @return array<string, mixed>|null */
    private function node(array $node): ?array
    {
        return match ($node['type']) {
            'paragraph' => $this->paragraph($node),
            'list' => $this->list($node['items']),
            'table' => $this->table($node),
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function pageNotes(array $node): array
    {
        $name = $node['topic'] ?? '';
        if ($name !== 'dates' && $name !== 'hints') {
            return [];
        }
        $lines = [];
        if (!empty($this->meta['osm_base'])) {
            $lines[] = 'OSM-Base Time : '.$this->meta['osm_base'];
        }
        if (!empty($this->meta['osm_base']) && !empty($this->meta['areas'])) {
            $lines[] = '<br>';
        }
        if (!empty($this->meta['areas'])) {
            $lines[] = 'Areas Time    : '.$this->meta['areas'];
        }
        $blocks = [];
        if ($name === 'dates') {
            $blocks[] = $this->paragraph(['indent' => 8, 'lines' => $lines, 'blank_after' => true]);
        }
        $blocks[] = $this->paragraph(['indent' => 8, 'lines' => [$this->tr('The data will be updated when the result of the analysis has changed.')]]);
        $blocks[] = $this->paragraph(['indent' => 8, 'lines' => [$this->tr("An explanation of the error texts can be found in the documentation at '<a href='/en/index.php#messages'>Messages</a>'.")]]);
        $blocks[] = ['type' => 'request_analysis', 'label' => $this->tr('Request start of new analysis')];

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function header(array $node): array
    {
        $nav = [];
        foreach ($node['nav'] as $item) {
            [$begin, $innerBegin, $innerEnd, $end] = $this->colourSpans($item['colour'], $item['text_colour'], '<nobr>&nbsp;', '&nbsp;</nobr>');
            $nav[] = sprintf('%s<a href="#%s">%s%s%s</a>%s', $begin, $this->esc($item['id']), $innerBegin, $this->messages->foreign($item['text']), $innerEnd, $end);
        }

        return [
            'level' => $node['level'],
            'id' => $node['id'] ?? null,
            'html' => !empty($node['text']) ? $node['number'].' '.MessageRenderer::wiki2html($this->rt($node['text'])) : null,
            'nav' => $nav,
        ];
    }

    /** @return array<string, mixed> */
    private function paragraph(array $node): array
    {
        $wiki = ($node['markup'] ?? '') === 'wiki';

        return [
            'type' => 'paragraph',
            'indent' => str_repeat(' ', $node['indent'] ?? 0),
            'lines' => array_map(fn (mixed $line): string => $wiki ? $this->wikiLine($this->rt($line)) : $this->rt($line), $node['lines'] ?? []),
            'loose' => !empty($node['loose']),
            'blank_after' => !empty($node['blank_after']),
        ];
    }

    private function wikiLine(string $text): string
    {
        $html = MessageRenderer::wiki2html($text);

        return preg_replace_callback('/^\s+/', static fn (array $m): string => str_repeat('&nbsp;', strlen($m[0])), $html) ?? $html;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function list(array $items): array
    {
        return [
            'type' => 'list',
            'items' => array_map(fn (array $item): array => [
                'html' => $this->rt($item['text']),
                'children' => $this->list($item['children'] ?? [])['items'],
            ], $items),
        ];
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     *
     * @return array<string, mixed>
     */
    private function simpleTable(array $header, array $rows): array
    {
        $render = fn (array $cells): array => array_map($this->rt(...), $cells);

        return ['type' => 'simple_table', 'header' => $render($header), 'rows' => array_map($render, $rows)];
    }

    private function helpParagraph(array $lines, array $options = []): array
    {
        return $this->paragraph(['lines' => $lines] + $options);
    }

    /** @return array{text: string, children: list<array<string, mixed>>} */
    private function helpItem(string $text, array ...$children): array
    {
        return ['text' => $text, 'children' => $children];
    }

    /** Explanatory text at the start of a section, selected by the topic of the section. */
    private function helpAfter(array $node, array &$state): array
    {
        $id = $node['topic'] ?? '';
        $state = ['section' => $id, 'lists' => 0, 'tables' => 0] + $state;
        $out = [];
        $t = $this->tr(...);

        switch ($id) {
            case 'unassigned':
                $out[] = $this->helpParagraph([implode(' ', [
                    $t('This section lists the lines that could not be clearly assigned.'),
                    $t("The line numbers 'ref' have been specified several times in the CSV file."),
                    $t('This means that the same line number exists in the transport network several times in different municipalities / cities.'),
                    $t('In order to be able to clearly assign the lines, the following should be indicated:'),
                ])]);
                $out[] = $this->list([
                    $this->helpItem($t('Relation').':',
                        $this->helpItem($t("'network', 'operator', as well as 'from' and 'to' should be tagged with the relation."),
                            $this->helpItem($t("If the value of 'operator' is sufficient for differentiation, 'from' and 'to' need not be specified.")))),
                    $this->helpItem($t('CSV file').':',
                        $this->helpItem($t("'Operator', as well as 'From' and 'To' should be specified in the CSV file with the same values as with the relation."),
                            $this->helpItem($t('See the instructions for such entries at the beginning of the CSV file.')))),
                ]);
                $out[] = $this->helpParagraph([$t("Examples for an entry in the CSV file of the form: 'ref;type;Comment;From;To;Operator':")]);
                $out[] = $this->simpleTable(
                    ['&nbsp;', 'ref', 'type', $t('Comment'), $t('From'), $t('To'), $t('Operator')],
                    [
                        ['<strong>1.)</strong> ', '9', 'bus', 'Bus 9 '.$t('provides services in City-A'), $t('First Avenue'), $t('Sixth Avenue'), $t('Operator-X')],
                        ['<strong>2.)</strong> ', '9', 'bus', 'Bus 9 '.$t('provides services in Village-B'), $t('Main Street'), $t('Second Street'), $t('Operator-X')],
                        ['<strong>3.)</strong> ', '9', 'bus', 'Bus 9 '.$t('provides services in Town-C'), $t('Sunset Boulevard'), $t('Rainbow Boulevard'), $t('Operator-Z')],
                    ],
                );
                $out[] = $this->helpParagraph([
                    $t("1.) and 2.) are only distinguishable by means of 'From'/'from' and 'To'/'to', since 'Operator'/'operator' are identical (='Operator-X').").'<br>',
                    $t("1.) and 3.) as well as 2.) and 3.) can be distinguished by 'Operator'/'operator', as these are different (='Operator-X' or ='Operator-Z')."),
                ]);
                break;
            case 'morerelations':
                $out[] = $this->helpParagraph([$t('This section lists further relations of the environment of the routes:')]);
                $out[] = $this->list([
                    $this->helpItem($t("potentially wrong 'route' or 'route_master' values?"),
                        $this->helpItem($t("e.g. 'route' = 'suspended_bus' instead of 'route' = 'bus'"))),
                    $this->helpItem($t("but also 'type' = 'network', 'type' = 'set' or 'route' = 'network', i.e. a collection of all routes and route-masters belonging to the 'network'."),
                        $this->helpItem($t('such <strong>collections are strictly spoken errors</strong>, since relations shall not represent collections:')
                            .' <a href="https://wiki.openstreetmap.org/wiki/Relations/Relations_are_not_Categories">'.$t('Relations/Relations are not Categories').'</a>')),
                ]);
                break;
            case 'addednetworks':
                $out[] = $this->helpParagraph([$t("This section lists 'network' values that were additionally considered because the 'network' value of the Route-Master or member Route matched.")]);
                break;
            case 'notconsiderednetworks':
                $out[] = $this->helpParagraph([
                    $t("This section lists the 'network'-values which have not been considered."),
                    $t('They might include typos in values which otherwise should have been considered.'),
                ]);
                break;
            case 'gtfsreferences':
                $out[] = $this->helpParagraph([$t('This section lists references to GTFS feed information found in the CSV list and in Route-Master and Route relations.')]);
                break;
            case 'gtfsreferences_csv':
                $out[] = $this->helpParagraph([
                    $t('This section lists the name of the GTFS feed and the related release date information as found in the CSV list.'),
                    $t('An empty release date means: reference to the latest GTFS information, the latest available at PTNA.'),
                ]);
                $out[] = $this->helpParagraph([
                    $t('Example').':<br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;#ref;route;comment;from;to;operator;gtfs_feed;route_id;release_date</code><br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;210;bus;;Brunnthal, Zusestraße;Neuperlach Süd U S;Verkehrsbetrieb Ettenhuber GmbH;DE-BY-MVV;19-210-s20-1;2020-07-24</code><br />',
                    $t("Where the GTFS feed name is 'DE-BY-MVV' and the release date is '2020-07-24'."),
                ], ['loose' => true]);
                break;
            case 'gtfsreferences_relations':
                $out[] = $this->helpParagraph([
                    $t('This section lists the name of the GTFS feed and the related release date information as found in Route-Master and Route relations.'),
                    $t('An empty release date means: reference to the latest GTFS information, the latest available at PTNA.'),
                    $t('In addition to that, the source of the information (name and date) is listed.'),
                    $t("The value '--gtfs-feed' refers to the corresponding global analysis option, because no information about the source of the GTFS feed information was found in the Route-Master or Route relation."),
                ], ['loose' => true]);
                $out[] = $this->helpParagraph([
                    $t('Preferred example').' ('.$t('key = value').'):<br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;gtfs:release_date:DE-BY-MVV&nbsp;=&nbsp;2026-07-13</code><br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;gtfs:route_id:DE-BY-MVV&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;=&nbsp;19-210-s20-1</code><br />',
                ], ['loose' => true]);
                $out[] = $this->helpParagraph([
                    $t('Deprecated example').' ('.$t('key = value').'):<br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;gtfs:feed&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;=&nbsp;DE-BY-MVV</code><br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;gtfs:release_date&nbsp;=&nbsp;2020-07-24</code><br />',
                    '<code>&nbsp;&nbsp;&nbsp;&nbsp;gtfs:route_id&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;=&nbsp;19-210-s20-1</code><br />',
                ], ['loose' => true]);
                break;
            case 'conditional_on_ways':
                $out[] = $this->helpParagraph([
                    $t('This section lists conditional access statements found on ways used by public transport vehicles.'),
                    $t('PTNA will not completely evaluate conditional access statements, they are too complex.'),
                    $t("If a statement includes a 'yes', 'permissive', 'permit', 'official', 'destination' or 'designated' as first word, PTNA will assume 'access granted'."),
                    $t('Otherwise, PTNA will ignore the statement and search for and analyse further statements tagged on this way.'),
                ]);
                $out[] = $this->helpParagraph([
                    $t('Take a look at the list of conditional access statements.'),
                    $t('Some of them might be outdated, i.e., the restriction was valid in the past.'),
                    '<br />',
                    $t('Example').':<br />',
                    "<code>&nbsp;&nbsp;&nbsp;&nbsp;'access:conditional'='no @ (2024 Aug 01-2024 Oct 30)'</code><br />",
                    $t('Consider removing these restrictions at some point.'),
                ]);
                break;
            case 'excluded':
                $out[] = $this->helpParagraph([$t('This section lists relations which have been excluded from the ... section of the analysis.')], ['indent' => 8]);
                break;
        }

        return $out;
    }

    /** Explanatory text in front of the lists and tables of a section. */
    private function helpBefore(array $node, array &$state): array
    {
        $id = $state['section'] ?? '';
        $out = [];

        if ($node['type'] === 'list') {
            ++$state['lists'];
            if ($id === 'morerelations') {
                $out[] = $this->helpParagraph([
                    $this->tr('The following values and combinations have been found in the provided data but they will not be listed here.'),
                    $this->tr("They represent so called 'well defined' values and are not considered as errors."),
                ]);
            } elseif ($id === 'networkdetails') {
                if ($state['lists'] === 1) {
                    $out[] = $this->helpParagraph([$this->tr("The contents of the 'network' tag will be searched for:")]);
                    $state['network_list'] = true;
                } else {
                    $out[] = $this->helpParagraph([$this->tr("The contents of the 'operator' tag will be searched for:")]);
                }
            }
        } elseif ($node['type'] === 'table') {
            ++$state['tables'];
            if ($id === 'considerednetworks') {
                if ($state['tables'] === 1) {
                    if (!empty($state['network_list'])) {
                        $out[] = $this->helpParagraph([$this->tr("This section lists the 'network'-values which have been considered; i.e. which match to one of the values above.")]);
                    }
                } else {
                    $out[] = $this->helpParagraph([
                        $this->tr("This section lists the 'operator'-values which have not been considered."),
                        $this->tr('They might include typos in values which otherwise should have been considered.'),
                    ]);
                }
            }
        }

        return $out;
    }

    private function columnLabel(string $column): string
    {
        $labels = [
            'ref' => 'Line (ref=)', 'relation' => 'Relation (id=)', 'relations' => 'Relations', 'name' => 'Name (name=)',
            'number' => 'Number', 'network' => 'Network (network=)', 'operator' => 'Operator (operator=)', 'from' => 'From (from=)',
            'via' => 'Via (via=)', 'to' => 'To (to=)', 'issues' => 'Errors', 'notes' => 'Notes', 'type' => 'Type (type=)',
            'route_type' => 'Vehicle (route(_master)=)', 'PTv' => 'PTv', 'CSV-Comment' => 'Comment', 'CSV-From' => 'From',
            'CSV-To' => 'To', 'CSV-Operator' => 'Operator', 'gtfs_feed' => 'GTFS feed', 'date' => 'Date', 'feed_from' => 'GTFS feed from',
            'date_from' => 'Date from', 'conditional_access' => 'Conditional Access', 'ways' => 'Ways',
        ];
        if ($column === 'life-cycle-prefix') {
            return 'prefix:route(_master)';
        }

        return isset($labels[$column]) ? $this->tr($labels[$column]) : $column;
    }

    private function columnLabelOr(string $column, string $fallback): string
    {
        return $column === 'CSV-Comment' || $column === 'CSV-From' || $column === 'CSV-To' || $column === 'CSV-Operator'
            ? $this->columnLabel($column) : $fallback;
    }

    /** The columns which can be sorted in the browser: not for tables with groups (sub-headers). @return array<string, string> */
    private function tableSort(array $table): array
    {
        foreach ($table['rows'] as $row) {
            if ($row['type'] === 'subheader') {
                return [];
            }
        }
        $types = self::SORT_TYPES;
        if (!in_array('feed_from', $table['columns'], true)) {
            $types['gtfs_feed'] = 'string';
        }

        return array_intersect_key($types, array_flip($table['columns']));
    }

    /** @return array<string, mixed> */
    private function table(array $node): array
    {
        $columns = $node['columns'];
        $sort = $this->tableSort($node);
        $rows = [];
        foreach ($node['rows'] as $row) {
            $rows[] = $row['type'] === 'subheader' ? $this->subheader($row, count($columns)) : $this->line($row, $columns);
        }

        return [
            'type' => 'table',
            'sortable' => (bool) $sort,
            'columns' => array_map(fn (string $column): array => [
                'name' => $column,
                'sort' => $sort[$column] ?? null,
                'label' => $this->columnLabel($column),
            ], $columns),
            'prefix' => $sort ? '&#x21C5;' : '',
            'rows' => array_filter($rows),
        ];
    }

    /** @return array<string, mixed>|null */
    private function subheader(array $row, int $columns): ?array
    {
        $refs = array_map($this->rt(...), $row['refs'] ?? []);
        $refsText = implode(' ', $refs);
        if ($columns <= 1 || $refsText === '') {
            return null;
        }
        $csv = $row['csv'] ?? [];
        $refText = implode(' ', array_map(fn (string $ref): string => $this->sketchline($ref, $row['network'] ?? '', $row['operator'] ?? '', $row['pt_type'] ?? '', $row['colour'] ?? '', $row['text_colour'] ?? ''), $refs));
        if ($refText === '') {
            return null;
        }

        $csvText = '';
        if (!empty($csv['comment'])) {
            $comment = sprintf('%s: <span class="foreign" dir="auto">%s</span>; ', $this->columnLabelOr('CSV-Comment', 'Comment'), MessageRenderer::wiki2html($this->rt($csv['comment'])));
            $csvText .= preg_replace('/!([^!]+)!/', '<span class="attention">$1</span>', $comment);
        }
        foreach (['from' => ['CSV-From', 'From'], 'to' => ['CSV-To', 'To'], 'operator' => ['CSV-Operator', 'Operator']] as $key => [$column, $fallback]) {
            if (!empty($csv[$key])) {
                $csvText .= sprintf('%s: %s; ', $this->columnLabelOr($column, $fallback), $this->messages->foreign($this->rt($csv[$key])));
            }
        }
        if (!empty($csv['gtfs'])) {
            $csvText .= $this->rt($csv['gtfs']);
        }
        $csvText = preg_replace('/; $/', '', $csvText);

        return [
            'type' => 'subheader',
            'id' => $row['id'] ?? null,
            'info' => str_replace('"', '_', $csvText !== '' && $csvText !== '0' ? $csvText : '???'),
            'ref' => $refsText,
            'sketch' => $refText,
            'colspan' => $columns - 1,
            'csv' => $csvText,
        ];
    }

    private function sketchline(string $ref, string $network, string $operator, string $type, string $colour, string $textColour): string
    {
        $background = $this->colour($colour);
        $foreground = $textColour !== '' ? $this->colour($textColour) : $this->foregroundFor($background);
        [$begin, $innerBegin, $innerEnd, $end] = $this->colourSpans($colour, $textColour, '&nbsp;', '&nbsp;');

        $anchorBegin = $anchorEnd = '';
        if ($network !== '' || $operator !== '') {
            $extra = '';
            if ($background && $foreground) {
                $extra = '&bg='.MessageRenderer::uriEscape($background).'&fg='.MessageRenderer::uriEscape($foreground);
                $extra .= in_array($type, ['train', 'light_rail'], true) ? '&r=1' : '';
            }
            $anchorBegin = sprintf(
                '<a href="https://overpass-api.de/api/sketch-line?ref=%s&network=%s&operator=%s%s" title="Sketch-Line">',
                MessageRenderer::uriEscape($ref),
                MessageRenderer::uriEscape($network),
                MessageRenderer::uriEscape($operator),
                $extra,
            );
            $anchorEnd = '</a>';
        }

        return $begin.$anchorBegin.$innerBegin.$this->esc($ref).$innerEnd.$anchorEnd.$end;
    }

    /** @return array{string, string, string, string} The outer and inner spans which colour a line, empty without colours. */
    private function colourSpans(string $colour, string $textColour, string $before, string $after): array
    {
        $background = $this->colour($colour);
        $foreground = $textColour !== '' ? $this->colour($textColour) : $this->foregroundFor($background);
        if (!$background || !$foreground) {
            return ['', '', '', ''];
        }

        return [
            sprintf('<span style="background-color: %s; border-style: solid; border-color: gray; border-width: 1px;">%s', $background, $before),
            sprintf('<span style="color: %s">', $foreground),
            '</span>',
            $after.'</span>',
        ];
    }

    private function colour(string $value): ?string
    {
        $value = strtolower($value);
        if (preg_match('/^#[a-f0-9]{6}$/', $value)) {
            return $value;
        }
        if (preg_match('/^#([a-f0-9])([a-f0-9])([a-f0-9])$/', $value, $m)) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return $this->colours[$value] ?? null;
    }

    private function foregroundFor(?string $background): ?string
    {
        if (!$background) {
            return null;
        }
        $rgb = hexdec(ltrim($background, '#'));
        $brightness = ($rgb >> 16) * 0.299 + (($rgb & 0xFF00) >> 8) * 0.587 + ($rgb & 0xFF) * 0.114;

        return $brightness > 160 ? '#000000' : '#ffffff';
    }

    /** The id of a line is the id of its relation, only the first line of a relation gets it. */
    private function lineId(array $row): ?string
    {
        $relation = $row['cells']['relation'] ?? null;
        if (empty($relation['id'])) {
            return null;
        }
        $id = preg_replace('/[^0-9A-Za-z_.-]/', '_', $this->esc($relation['id']));
        if (isset($this->usedIds[$id])) {
            return null;
        }
        $this->usedIds[$id] = true;

        return $id;
    }

    /** @return array<string, mixed> */
    private function line(array $row, array $columns): array
    {
        $cells = $row['cells'] ?? [];
        $out = [];
        foreach ($columns as $column) {
            $cell = $cells[$column] ?? [];
            [$class, $html] = match ($column) {
                'relation' => ['relation', !empty($cell['id']) ? $this->messages->relationLink($cell['link']) : ''],
                'relations' => ['relations', implode(', ', array_map($this->messages->relationLink(...), $cell['links'] ?? [])).$this->andMore($cell)],
                'ways' => ['ways', implode(', ', array_map(fn (array $l): string => $this->messages->objectLink($l, 'way'), $cell['links'] ?? [])).$this->andMore($cell)],
                'issues', 'notes' => [$column, implode('<br>', array_map($this->rt(...), $cell))],
                'gtfs_feed' => ['gtfs_feed', $cell ? $this->messages->gtfsFeedLink($cell) : ''],
                default => [$column.' foreign', implode('<br>', array_map(fn (mixed $v): string => $this->esc($this->rt($v)), is_array($cell) ? $cell : [$cell]))],
            };
            $out[] = ['class' => $class, 'html' => $html];
        }

        return [
            'type' => 'line',
            'id' => $this->lineId($row),
            'info' => $this->esc($row['info'] ?? ''),
            'ref' => $this->esc($row['ref'] ?? ''),
            'cells' => $out,
        ];
    }

    private function andMore(array $cell): string
    {
        return !empty($cell['truncated']) ? ' '.$this->tr('and more ...') : '';
    }
}
