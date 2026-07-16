<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacuum's history tables — the only tables the package owns, and the only place
 * it writes. They live on your application's own connection, never on the
 * database Vacuum inspects.
 *
 * Three tables: one row per capture, its findings, and the raw per-object metrics
 * that let a later reading say which way each number is moving.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->storageConnection());

        $schema->create('vacuum_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('connection');

            $table->timestamp('taken_at');
            $table->integer('server_version');
            $table->unsignedSmallInteger('health_score');
            $table->string('grade', 1);
            $table->timestamp('created_at')->nullable();

            $table->index(['connection', 'taken_at']);
        });

        $schema->create('vacuum_snapshot_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('vacuum_snapshots')
                ->cascadeOnDelete();

            // (rule, subject) is a finding's identity: it is how one snapshot's
            // findings are diffed against the last to see what is new and what cleared.
            $table->string('rule');
            $table->string('subject');
            $table->string('severity');
            // Named table_name, not table: an Eloquent model already has a $table
            // property, and a column called table would shadow it on the model.
            $table->string('table_name')->nullable();
            $table->text('summary');

            // The number the finding turns on — xid age, bloat bytes, a ratio —
            // kept so a finding can be read next to its own metric series.
            $table->decimal('value', 20)->nullable();

            $table->index('snapshot_id');
            $table->index(['rule', 'subject']);
        });

        $schema->create('vacuum_snapshot_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('vacuum_snapshots')
                ->cascadeOnDelete();

            // What is being measured, and of what. 'kind' names the series
            // (table_xid_age, table_bloat_bytes, db_cache, statement...); 'object'
            // is the table, the queryid, or 'database' the series belongs to.
            $table->string('kind');
            $table->string('object');

            $table->decimal('value', 30)->nullable();
            $table->decimal('value2', 30)->nullable();

            $table->index('snapshot_id');
            $table->index(['kind', 'object']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->storageConnection());

        $schema->dropIfExists('vacuum_snapshot_metrics');
        $schema->dropIfExists('vacuum_snapshot_findings');
        $schema->dropIfExists('vacuum_snapshots');
    }

    /**
     * The connection history is stored on. Null in config means the application's
     * default connection, which is what passing null to the schema builder selects.
     */
    private function storageConnection(): ?string
    {
        $connection = config('vacuum.history.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
};
