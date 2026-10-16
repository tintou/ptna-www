<?php

namespace App\Network;

/**
 * The key/value pairs of a '<network>-Analysis-details.txt' file written by the PTNA analysis,
 * together with the places of the files belonging to that network.
 */
final class NetworkDetails
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public readonly string $network,
        private readonly array $values,
        public readonly string $catalogFile,
        public readonly string $analysisWebPath,
        public readonly string $analysisFile,
        public readonly string $diffWebPath,
    ) {
    }

    public function found(): bool
    {
        return $this->values !== [];
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function overpassQuery(): string
    {
        $query = $this->get('OVERPASS_QUERY');
        $parts = explode('=', $query, 2);

        return count($parts) > 1 ? $parts[0] . '=' . urldecode($parts[1]) : $query;
    }

    public function routesLink(): string
    {
        $link = $this->get('ROUTES_LINK');
        $parts = explode('=', $link, 2);

        return count($parts) > 1 ? $parts[0] . '=' . urlencode(urldecode($parts[1])) : $link;
    }

    public function osmXmlFileSizeByte(): int
    {
        if ($this->has('OSM_XML_FILE_SIZE_BYTE')) {
            return (int) $this->get('OSM_XML_FILE_SIZE_BYTE');
        }

        return (int) ((float) $this->get('OSM_XML_FILE_SIZE', '0') * 1024);
    }

    public function timezoneName(): string
    {
        return $this->get('TZNAME', $this->get('TZ'));
    }

    public function timezoneShort(): string
    {
        return str_replace(['-', '+'], ['UTC-', 'UTC+'], $this->get('TZSHORT'));
    }

    public function hasChanges(): bool
    {
        return $this->get('OLD_OR_NEW') === 'new';
    }

    public function analysisFileSize(): ?int
    {
        return is_file($this->analysisFile) ? (int) filesize($this->analysisFile) : null;
    }
}
