<?php

namespace App\Gtfs;

/**
 * Builds the model of the time line of available releases of a feed, rendered by templates/gtfs/_timeline.html.twig.
 */
final class GtfsTimeline
{
    private const SHOWN_MONTHS = 14;

    public function __construct(private readonly GtfsFeedLocator $locator)
    {
    }

    /**
     * @param string $release the release being viewed: '', a date, 'previous', 'long-term' or 'error'
     *
     * @return array{
     *     rows: int,
     *     columns: list<array{name: string, month: int, year: int, bar: bool}>,
     *     entries: list<array{column: string, row: int, date: string, kind: string, viewed: bool, empty: bool, longTerm: bool}>
     * }
     */
    public function build(string $feed, string $release = ''): array
    {
        $dates = $this->locator->releaseDates($feed);

        $current = $this->locator->linkTarget($feed, '');
        $previous = $this->locator->linkTarget($feed, 'previous');
        $longTerm = $this->locator->linkTarget($feed, 'long-term');
        $error = $this->locator->linkTarget($feed, 'error');

        $viewing = match ($release) {
            '' => $current,
            'previous' => $previous,
            'long-term' => $longTerm,
            'error' => $error,
            default => $feed . '-' . $release . '-ptna-gtfs-sqlite.db',
        };

        $perMonth = [];
        $entries = [];
        foreach ($dates as $date) {
            $month = substr($date, 0, 4) . substr($date, 5, 2);
            $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
        }
        $rows = max(1, ...array_values($perMonth ?: [1]));

        $remaining = $perMonth;
        foreach ($dates as $date) {
            $month = substr($date, 0, 4) . substr($date, 5, 2);
            $file = $feed . '-' . $date . '-ptna-gtfs-sqlite.db';

            $kind = match (true) {
                $current === $file => 'current',
                $previous === $file => 'previous',
                $error === $file => 'error',
                default => 'plain',
            };

            $entries[] = [
                'column' => 'm' . $month,
                'row' => $remaining[$month]--,
                'date' => $date,
                'kind' => $kind,
                'viewed' => $viewing === $file,
                'empty' => $this->locator->size($feed, $date) === 0,
                'longTerm' => $longTerm === $file,
            ];
        }

        return ['rows' => $rows, 'columns' => $this->columns($dates), 'entries' => $entries];
    }

    /**
     * @param list<string> $dates
     *
     * @return list<array{name: string, month: int, year: int, bar: bool}>
     */
    private function columns(array $dates): array
    {
        $now = new \DateTimeImmutable('first day of this month');
        $first = $now->modify('-' . self::SHOWN_MONTHS . ' months');
        $last = $now;

        foreach ($dates as $date) {
            $d = new \DateTimeImmutable(substr($date, 0, 7) . '-01');
            $first = min($first, $d);
            $last = max($last, $d);
        }

        $columns = [];
        for ($d = $first; $d <= $last; $d = $d->modify('+1 month')) {
            $month = (int) $d->format('n');
            $columns[] = [
                'name' => 'm' . $d->format('Ym'),
                'month' => $month,
                'year' => (int) $d->format('Y'),
                'bar' => $month === 12 && $d < $last,
            ];
        }

        return $columns;
    }
}
