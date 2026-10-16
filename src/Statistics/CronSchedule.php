<?php

namespace App\Statistics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The schedule of the periodic analysis cron jobs (Munich time) and which of their logs exist.
 */
final class CronSchedule
{
    private const GROUPS = [
        ['time' => '20:05', 'job' => 'australia-eastern-and-southeastern-asia', 'planet' => 'UTC+10', 'rows' => [
            ['oceania', 'UTC+10', 'AU', 'Australia'],
            ['oceania', 'UTC+09.30', 'AU', 'Australia'],
            ['asia', 'UTC+08', 'CN, PH', 'China, Philippines'],
            ['asia', 'UTC+07', 'ID', 'Indonesia'],
        ]],
        ['time' => '23:05', 'job' => 'india-iran-la-reunion', 'planet' => 'UTC+05.30', 'rows' => [
            ['asia', 'UTC+05.30', 'IN', 'India'],
            ['africa', 'UTC+04', 'FR-974', 'France (La Reunion)'],
            ['asia', 'UTC+03.30', 'IR', 'Iran'],
        ]],
        ['time' => '02:05', 'job' => 'africa-europe-israel', 'planet' => 'UTC+03', 'rows' => [
            ['africa', 'UTC+03', 'ET, MG, RU', 'Ethiopia, Madagascar'],
            ['europe', 'UTC+03', 'ET, MG, RU', 'Russia'],
            ['asia', 'UTC+02', 'IL, BG, EE, FI, RO', 'Israel'],
            ['europe', 'UTC+02', 'IL, BG, EE, FI, RO', 'Bulgaria, Estonia, Finland, Romania'],
            ['africa', 'UTC+01', 'AT, CH, CZ, DE, DK, ES, FR, HR, IT, LI, LU, MA, NL, NO, PL, RS, SI', 'Morocco'],
            ['europe', 'UTC+01', 'AT, CH, CZ, DE, DK, ES, FR, HR, IT, LI, LU, MA, NL, NO, PL, RS, SI', 'Austria, Croatia, Czechia, Denmark, France, Germany, Italy, Liechtenstein, Luxemburg, Netherlands, Norway, Poland, Serbia, Slovenia, Spain, Switzerland'],
            ['africa', 'UTC+00', 'GH, MR, ES, GB, JE', 'Ghana, Mauretania'],
            ['europe', 'UTC+00', 'GH, MR, ES, GB, JE', 'Great Britain, Jersey, Spain (Canaries)'],
        ]],
        ['time' => '08:05', 'job' => 'eastern-america', 'planet' => 'UTC-03', 'rows' => [
            ['south-america', 'UTC-03', 'AR, BR', 'Argentina, Brasil'],
            ['north-america', 'UTC-04', 'BO, CA, CL', 'Canada'],
            ['south-america', 'UTC-04', 'BO, CA, CL', 'Bolivia, Chile'],
            ['central-america', 'UTC-05', 'CA, CO, PA, US', 'Panama'],
            ['north-america', 'UTC-05', 'CA, CO, PA, US', 'Canada, USA'],
            ['south-america', 'UTC-05', 'CA, CO, PA, US', 'Colombia'],
            ['central-america', 'UTC-06', 'CA, NI, US', 'Nicaragua'],
            ['north-america', 'UTC-06', 'CA, NI, US', 'Canada, USA'],
        ]],
        ['time' => '12:05', 'job' => 'western-america', 'planet' => 'UTC-07', 'rows' => [
            ['north-america', 'UTC-07', 'CA, US', 'Canada, USA'],
            ['north-america', 'UTC-08', 'US', 'USA'],
            ['north-america', 'UTC-09', 'US', 'USA'],
        ]],
    ];

    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    /**
     * @return array{planet_mode: bool, groups: list<array<string, mixed>>}
     */
    public function schedule(): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $rows = [];
            foreach ($group['rows'] as [$continent, $timezone, $codes, $countries]) {
                $rows[] = [
                    'continent' => $continent,
                    'continent_log' => $this->exists('handle-continent-' . $continent),
                    'timezone' => $timezone,
                    'codes' => $codes,
                    'countries' => $countries,
                ];
            }
            $groups[] = [
                'time' => $group['time'],
                'job' => $group['job'],
                'job_log' => $this->exists('cron-' . $group['job']),
                'planet' => $group['planet'],
                'planet_log' => $this->exists('handle-planet-' . $group['planet']),
                'manual' => false,
                'rows' => $rows,
            ];
        }

        $planetMode = false;
        foreach (['UTC-all', 'UTC+10', 'UTC+05.30', 'UTC+03', 'UTC-03', 'UTC-07'] as $planet) {
            $planetMode = $planetMode || $this->exists('handle-planet-' . $planet);
        }

        // A manual run for all time zones at once replaces the individual jobs
        if ($this->exists('handle-planet-UTC-all')) {
            $rows = array_merge(...array_column($groups, 'rows'));
            $groups = [[
                'time' => '',
                'job' => 'all-in-one',
                'job_log' => true,
                'planet' => 'UTC-all',
                'planet_log' => true,
                'manual' => true,
                'rows' => $rows,
            ]];
        }

        return ['planet_mode' => $planetMode, 'groups' => $groups];
    }

    private function exists(string $log): bool
    {
        return is_file($this->workDir . 'ptna-' . $log . '.log');
    }
}
