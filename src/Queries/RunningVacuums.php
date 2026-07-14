<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\RunningVacuum;

/**
 * Reads pg_stat_progress_vacuum: the vacuums running at this moment.
 */
final readonly class RunningVacuums
{
    private const string STATEMENT = 'vacuum_progress';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<RunningVacuum>
     */
    public function all(): array
    {
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toVacuum(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toVacuum(array $row): RunningVacuum
    {
        return new RunningVacuum(
            pid: Cast::integer($row['pid'] ?? null),
            schema: Cast::text($row['schemaname'] ?? null),
            table: Cast::text($row['relname'] ?? null),
            phase: Cast::text($row['phase'] ?? null),
            blocksTotal: Cast::integer($row['heap_blks_total'] ?? null),
            blocksScanned: Cast::integer($row['heap_blks_scanned'] ?? null),
            indexPasses: Cast::integer($row['index_vacuum_count'] ?? null),
            startedAt: Cast::timestamp($row['started_at'] ?? null),
            automatic: Cast::boolean($row['automatic'] ?? null),
        );
    }
}
