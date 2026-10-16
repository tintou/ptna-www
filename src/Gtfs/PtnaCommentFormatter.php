<?php

namespace App\Gtfs;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns the comment columns of ptna_trips_comments / ptna_routes_comments into human readable lines.
 * The lines are plain text, escaping is up to the template.
 */
final class PtnaCommentFormatter
{
    /** column => whether the value is a quoted single value (true) or a list of ids (false) */
    private const COLUMNS = [
        'suspicious_start' => true,
        'suspicious_end' => true,
        'suspicious_number_of_stops' => true,
        'suspicious_trip_duration' => true,
        'suspicious_other' => true,
        'subroute_of' => false,
        'same_names_but_different_ids' => false,
        'same_stops_but_different_shape_ids' => false,
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param string|array<string, mixed> $comment a plain comment or a database row
     *
     * @return list<string>
     */
    public function lines(string|array $comment): array
    {
        $text = is_string($comment) ? $comment : (string) ($comment['comment'] ?? '');
        $lines = $text !== '' ? [$this->clean($text)] : [];

        if (is_array($comment)) {
            foreach (self::COLUMNS as $column => $quoted) {
                $value = (string) ($comment[$column] ?? '');
                if ($value === '') {
                    continue;
                }
                $label = $this->translator->trans('comment.' . $column, [], 'gtfs_details');
                $lines[] = $quoted ? $label . " '" . $value . "'" : $label . ' ' . preg_replace('/,\s*/', ', ', $value);
            }
        }

        return array_values(array_filter(
            array_merge(...array_map(static fn (string $l) => explode("\n", $l), $lines ?: [''])),
            static fn (string $l) => $l !== '',
        ));
    }

    private function clean(string $text): string
    {
        return preg_replace('/::[A-Z]+::/', '', $text);
    }
}
