<?php

namespace App\Statistics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * CPU and disk load of the server, read without executing external commands.
 */
final class ServerLoad
{
    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    /**
     * @return array{1: float, 5: float, 15: float}|null load average over 1, 5 and 15 minutes
     */
    public function cpu(): ?array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if ($load === false) {
            return null;
        }

        return [1 => round($load[0], 2), 5 => round($load[1], 2), 15 => round($load[2], 2)];
    }

    /**
     * @return array{total: float, free: float, used_percent: float}|null sizes in GB of the file system of the work directory
     */
    public function disk(): ?array
    {
        $total = @disk_total_space($this->workDir);
        $free = @disk_free_space($this->workDir);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return [
            'total' => round($total / 1024 ** 3, 1),
            'free' => round($free / 1024 ** 3, 1),
            'used_percent' => round(($total - $free) / $total * 100, 1),
        ];
    }
}
