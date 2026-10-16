<?php

namespace App\Gtfs;

/**
 * Display of GTFS dates ('YYYYMMDD') and the CSS classes marking dates in the future (start) or in the past (end).
 */
final class GtfsDate
{
    public static function display(string $date): string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $parts) ? $parts[1] . '-' . $parts[2] . '-' . $parts[3] : $date;
    }

    public static function startClass(string $date, ?\DateTimeInterface $today = null): string
    {
        $today ??= new \DateTimeImmutable();

        return preg_match('/^\d{8}$/', $date) && $date > $today->format('Ymd') ? 'gtfs-datenew' : 'gtfs-date';
    }

    public static function endClass(string $date, ?\DateTimeInterface $today = null): string
    {
        $today ??= new \DateTimeImmutable();

        return preg_match('/^\d{8}$/', $date) && $date < $today->format('Ymd') ? 'gtfs-dateold' : 'gtfs-date';
    }
}
