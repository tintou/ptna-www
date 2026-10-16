<?php

namespace App\Network;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The queue of on-demand analyses, an SQLite database shared with the PTNA analysis cron job.
 */
final class AnalysisQueue
{
    private const MAX_QUEUED = 10;

    private readonly string $workDir;

    public function __construct(
        #[Autowire(env: 'PTNA_WORK_DIR')]
        string $workDir,
        private readonly NetworkDetailsReader $details,
    ) {
        $this->workDir = rtrim($workDir, '/') . '/';
    }

    public function exists(): bool
    {
        $file = $this->workDir . 'ptna-analysis-queue-sqlite.db';

        return is_file($file) && filesize($file) > 0;
    }

    /**
     * @return array{http: int, status: string}
     */
    public function enqueue(string $network, string $ip): array
    {
        if (!$this->details->isValidName($network)) {
            return ['http' => 404, 'status' => 'Not Found'];
        }
        if (!$this->exists()) {
            return ['http' => 404, 'status' => 'Queue Not Found'];
        }

        try {
            $db = $this->connect();
            try {
                return $db->transactional(function (Connection $db) use ($network, $ip): array {
                    if ((int) $db->fetchOne("SELECT COUNT(*) FROM queue WHERE status = 'queued'") >= self::MAX_QUEUED) {
                        return ['http' => 429, 'status' => 'Queue Is Full'];
                    }
                    if ((int) $db->fetchOne("SELECT COUNT(*) FROM queue WHERE network = ? AND status = 'queued'", [$network]) > 0) {
                        return ['http' => 429, 'status' => 'Already In Queue'];
                    }
                    if ((int) $db->fetchOne("SELECT COUNT(*) FROM queue WHERE network = ? AND status = 'started'", [$network]) > 0) {
                        return ['http' => 429, 'status' => 'Is Already Running'];
                    }

                    $db->insert('queue', ['network' => $network, 'status' => 'queued', 'queued' => time(), 'ip' => $ip]);

                    return ['http' => 202, 'status' => 'In Queue'];
                });
            } finally {
                $db->close();
            }
        } catch (Exception) {
            return ['http' => 500, 'status' => 'Server Error'];
        }
    }

    /**
     * @return list<array<string, mixed>>|null null if the queue does not exist
     *
     * @throws Exception
     */
    public function entries(): ?array
    {
        if (!$this->exists()) {
            return null;
        }

        $db = $this->connect();
        try {
            $entries = $db->fetchAllAssociative('SELECT * FROM queue ORDER BY queued DESC');
        } finally {
            $db->close();
        }

        foreach ($entries as &$entry) {
            $details = $this->details->read((string) $entry['network']);
            $entry['report'] = $details?->analysisWebPath;
            $entry['diff'] = $details?->diffWebPath;
        }

        return $entries;
    }

    private function connect(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->workDir . 'ptna-analysis-queue-sqlite.db']);
    }
}
