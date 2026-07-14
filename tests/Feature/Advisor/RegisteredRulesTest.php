<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\VacuumServiceProvider;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS parcels');
    DB::statement('CREATE TABLE parcels (id serial PRIMARY KEY, label text)');

    // Indexed, and then never queried by anything: the DELETE below reaches for
    // the primary key, so an index on id would not have been unused at all.
    DB::statement('CREATE INDEX parcels_label_index ON parcels (label)');
    DB::insert("INSERT INTO parcels (label) SELECT 'parcel ' || i FROM generate_series(1, 5000) i");

    // Most of the rows go, but not all of them: a table with nothing left in it
    // has no statistics for the bloat estimate to reconstruct a size from.
    DB::delete('DELETE FROM parcels WHERE id > 500');
    DB::statement('ANALYZE parcels');
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS parcels');
});

it('resolves an advisor already carrying the packaged rules', function (): void {
    expect(array_column(app(Advisor::class)->findings(), 'rule'))->toContain('dead-tuples');
});

it('gathers findings from every inspection, not merely the first', function (): void {
    // The test tables are nowhere near the shipped thresholds, which are set for
    // databases somebody depends on rather than for a table made a moment ago.
    config()->set('vacuum.thresholds.bloat_bytes', 1);
    config()->set('vacuum.thresholds.unused_index_min_size', 1);

    $rules = array_column(app(Advisor::class)->findings(), 'rule');

    expect($rules)->toContain('dead-tuples')
        ->and($rules)->toContain('table-bloat')
        ->and($rules)->toContain('unused-index');
});

it('lets an application add a rule of its own', function (): void {
    app()->tag([HouseRule::class], VacuumServiceProvider::TABLE_RULES);

    expect(array_column(app(Advisor::class)->findings(), 'rule'))->toContain('house-rule');
});

final class HouseRule implements TableRule
{
    public function inspect(TableStatistic $table): Finding
    {
        return new Finding(
            rule: 'house-rule',
            subject: $table->qualifiedName(),
            severity: Severity::Info,
            summary: 'summary',
            impact: 'impact',
        );
    }
}
