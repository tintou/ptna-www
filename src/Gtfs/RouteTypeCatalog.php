<?php

namespace App\Gtfs;

/**
 * Maps GTFS route_type values to a label, an OSM route value and a sort key.
 * The values of the feed's own 'gtfs_route_types' table win, otherwise the standard
 * (extended) GTFS route types are used.
 *
 * Not a service: build it with fromDatabase().
 */
final class RouteTypeCatalog
{
    // https://developers.google.com/transit/gtfs/reference/extended-route-types
    private const LABELS = [
        0 => 'Tram, Streetcar, Light rail',
        1 => 'Subway, Metro',
        2 => 'Rail',
        3 => 'Bus',
        4 => 'Ferry',
        5 => 'Cable tram',
        6 => 'Aerialway',
        7 => 'Funicular',
        11 => 'Trolleybus',
        12 => 'Monorail',
        100 => 'Railway Service',
        101 => 'High Speed Rail Service',
        102 => 'Long Distance Trains',
        103 => 'Inter Regional Rail Service',
        104 => 'Car Transport Rail Service',
        105 => 'Sleeper Rail Service',
        106 => 'Regional Rail Service',
        107 => 'Tourist Railway Service',
        108 => 'Rail Shuttle (Within Complex)',
        109 => 'Suburban Railway',
        110 => 'Replacement Rail Service',
        111 => 'Special Rail Service',
        112 => 'Lorry Transport Rail Service',
        113 => 'All Rail Services',
        114 => 'Cross-Country Rail Service',
        115 => 'Vehicle Transport Rail Service',
        116 => 'Rack and Pinion Railway',
        117 => 'Additional Rail Service',
        200 => 'Coach Service',
        201 => 'International Coach Service',
        202 => 'National Coach Service',
        203 => 'Shuttle Coach Service',
        204 => 'Regional Coach Service',
        205 => 'Special Coach Service',
        206 => 'Sightseeing Coach Service',
        207 => 'Tourist Coach Service',
        208 => 'Commuter Coach Service',
        209 => 'All Coach Services',
        400 => 'Urban Railway Service',
        401 => 'Metro Service',
        402 => 'Underground Service',
        403 => 'Urban Railway Service',
        404 => 'All Urban Railway Services',
        405 => 'Monorail',
        700 => 'Bus Service',
        701 => 'Regional Bus Service',
        702 => 'Express Bus Service',
        703 => 'Stopping Bus Service',
        704 => 'Local Bus Service',
        705 => 'Night Bus Service',
        706 => 'Post Bus Service',
        707 => 'Special Needs Bus',
        708 => 'Mobility Bus Service',
        709 => 'Mobility Bus for Registered Disabled',
        710 => 'Sightseeing Bus',
        711 => 'Shuttle Bus',
        712 => 'School Bus',
        713 => 'School and Public Service Bus',
        714 => 'Rail Replacement Bus Service',
        715 => 'Demand and Response Bus Service',
        716 => 'All Bus Services',
        800 => 'Trolleybus Service',
        900 => 'Tram Service',
        901 => 'City Tram Service',
        902 => 'Local Tram Service',
        903 => 'Regional Tram Service',
        904 => 'Sightseeing Tram Service',
        905 => 'Shuttle Tram Service',
        906 => 'All Tram Services',
        907 => 'Aerial Lift Service',
        1000 => 'Water Transport Service',
        1002 => 'National Car Ferry Service',
        1004 => 'Local Car Ferry Service',
        1008 => 'Local Passenger Ferry Service',
        1013 => 'Car High-Speed Ferry Service',
        1014 => 'Passenger High-Speed Ferry Service',
        1015 => 'Sightseeing Boat Service',
        1100 => 'Air Service',
        1200 => 'Ferry Service',
        1300 => 'Aerial Lift Service',
        1400 => 'Funicular Service',
        1500 => 'Taxi Service',
        1501 => 'Communal Taxi Service',
        1502 => 'Water Taxi Service',
        1503 => 'Rail Taxi Service',
        1504 => 'Bike Taxi Service',
        1505 => 'Licensed Taxi Service',
        1506 => 'Private Hire Service Vehicle',
        1507 => 'All Taxi Services',
        1700 => 'Miscellaneous Service',
        1702 => 'Horse-drawn Carriage',
        9999 => 'Light Rail (OSM)',
    ];

    /** @var array<int, GtfsRouteType> */
    private array $types = [];

    private const VEHICLES = [
        'en' => [
            'trolleybus' => 'Trolleybus',
            'share_taxi' => 'Share Taxi',
            'tram' => 'Tram',
            'bus' => 'Bus',
            'monorail' => 'Monorail',
            'ferry' => 'Ferry',
            'train' => 'Train',
            'light_rail' => 'Light Rail',
            'funicular' => 'Funicular',
            'subway' => 'Subway',
            'aerialway' => 'Aerialway',
        ],
        'de' => [
            'trolleybus' => 'Oberleitungsbus',
            'share_taxi' => 'Sammeltaxi',
            'tram' => 'Tram',
            'bus' => 'Bus',
            'monorail' => 'Einschienenbahn',
            'ferry' => 'Fähre',
            'train' => 'Zug',
            'light_rail' => 'Light Rail',
            'funicular' => 'Drahtseilbahn',
            'subway' => 'U-Bahn',
            'aerialway' => 'Seilbahn',
        ],
    ];

    /**
     * @param list<GtfsRouteType>                  $types
     * @param array<string, array<string, string>> $osmRouteNames OSM route => language ('en', 'de') => vehicle name, from the 'osm_routes' table
     */
    public function __construct(array $types = [], private readonly array $osmRouteNames = [])
    {
        foreach ($types as $type) {
            $this->types[$type->routeType] = $type;
        }
    }

    public static function fromDatabase(GtfsDatabase $database): self
    {
        return new self($database->routeTypes(), $database->osmRouteNames());
    }

    public function label(int $routeType): string
    {
        return isset($this->types[$routeType]) ? $this->types[$routeType]->label : (self::LABELS[$routeType] ?? (string) $routeType);
    }

    public function osmRoute(int $routeType): string
    {
        if ($this->types !== []) {
            return $this->types[$routeType]->osmRoute ?? 'unknown';
        }

        $label = strtolower($this->label($routeType));

        return match (true) {
            str_contains($label, 'trolleybus') => 'trolleybus',
            str_contains($label, 'demand and response bus') => 'share_taxi',
            str_contains($label, 'tram') => 'tram',
            str_contains($label, 'bus') => 'bus',
            str_contains($label, 'monorail') => 'monorail',
            str_contains($label, 'light rail') => 'light_rail',
            str_contains($label, 'ferry'), str_contains($label, 'water transport service') => 'ferry',
            str_contains($label, 'rail'), str_contains($label, 'train') => 'train',
            str_contains($label, 'funicular') => 'funicular',
            str_contains($label, 'aerial') => 'aerialway',
            str_contains($label, 'metro'), str_contains($label, 'subway'), str_contains($label, 'underground') => 'subway',
            str_contains($label, 'undefined') => $this->label($routeType),
            $routeType >= 1000 && $routeType < 2000 => 'ferry',
            default => 'bus',
        };
    }

    /**
     * Importance of the route type: the smaller the sort key, the more important the route.
     */
    public function sortKey(int $routeType): string
    {
        if ($this->types !== []) {
            return (string) ($this->types[$routeType]->sortKey ?? '6300');
        }

        $label = strtolower($this->label($routeType));

        if (str_contains($label, 'metro') || str_contains($label, 'subway') || str_contains($label, 'underground')) {
            return '2000';
        }
        if (str_contains($label, 'tram') || str_contains($label, 'streetcar')) {
            return '3000';
        }
        if (str_contains($label, 'coach')) {
            return '40' . sprintf('%02d', $routeType % 200);
        }
        if ($routeType === 3 || ($routeType >= 700 && $routeType < 800)) {
            // Regional Bus service shall have lower prio than Express Bus service
            $mapped = match ($routeType) {
                701 => 702,
                702, 3 => 701,
                default => $routeType,
            };

            return '50' . sprintf('%02d', $mapped % 700);
        }
        if (str_contains($label, 'taxi')) {
            return '6900';
        }
        if (str_contains($label, 'monorail')) {
            return '7000';
        }
        if (str_contains($label, 'funicular')) {
            return '8000';
        }
        if ($routeType === 4 || ($routeType >= 1000 && $routeType < 1100)) {
            return '99' . sprintf('%02d', $routeType % 1000);
        }
        if ($routeType === 6 || ($routeType >= 1300 && $routeType < 1400)) {
            return '9000';
        }
        if (str_contains($label, 'rail') || str_contains($label, 'train')) {
            return match (true) {
                str_contains($label, 'high speed') => '1000',
                str_contains($label, 'long distance') => '1100',
                str_contains($label, 'inter regional') => '1200',
                str_contains($label, 'regional') => '1400',
                str_contains($label, 'suburban') => '1600',
                str_contains($label, 'light rail') => '1599',
                default => '1900',
            };
        }

        return '6300';
    }

    /**
     * The name of the vehicle for an OSM route value, e.g. 'bus' => 'Bus', in 'de' or (for any other language) 'en'.
     */
    public function vehicle(string $osmRoute, ?string $language): string
    {
        $language = in_array($language, ['en', 'de'], true) ? $language : 'en';

        if ($this->osmRouteNames !== []) {
            return $this->osmRouteNames[$osmRoute][$language] ?? 'unknown';
        }

        return self::VEHICLES[$language][$osmRoute] ?? 'unknown';
    }
}
