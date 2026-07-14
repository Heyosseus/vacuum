<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\BloatEstimate;

/**
 * Estimates the wasted space in every table, worst first.
 */
final readonly class BloatEstimates
{
    private const string STATEMENT = 'table_bloat';

    public function __construct(
        private ReadOnlyExecutor $executor,
        private SqlRepository $sql,
        private IgnoredSchemas $ignored,
    ) {}

    /**
     * @return list<BloatEstimate>
     */
    public function all(): array
    {
        // The statement is a file, so it cannot grow a placeholder per ignored
        // schema the way an assembled string can. PostgreSQL splits the list for
        // us instead, which keeps the binding count fixed at one.
        $ignored = implode(',', $this->ignored->all());

        return array_map(
            $this->toEstimate(...),
            $this->executor->select($this->sql->get(self::STATEMENT), [$ignored]),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toEstimate(array $row): BloatEstimate
    {
        return new BloatEstimate(
            schema: Cast::text($row['schemaname'] ?? null),
            name: Cast::text($row['tblname'] ?? null),
            fillfactor: Cast::integer($row['fillfactor'] ?? null),
            realBytes: Cast::integer($row['real_size'] ?? null),
            bloatBytes: Cast::integer($row['bloat_size'] ?? null),
        );
    }
}
