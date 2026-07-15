@extends('vacuum::layout')

@php
    use Heyosseus\Vacuum\Support\Bytes;

    $percent = static fn (?float $ratio): string => $ratio === null ? '—' : number_format($ratio * 100, 1).'%';
    $when = static fn ($moment): string => $moment === null ? 'never' : $moment->diffForHumans();
@endphp

@section('content')
    <section class="panel">
        <div class="panel__bar">
            <span>Table</span>
            <span class="right">{{ Bytes::human($table->totalBytes) }} in all</span>
        </div>

        <div class="panel__body">
            <p class="subject" style="font-size: 1rem">{{ $table->qualifiedName() }}</p>

            {{-- Four sizes rather than one, because they are four different
                 questions. A table that looks enormous is often a table with one text
                 column and a TOAST relation nobody has ever looked at. --}}
            <dl class="facts">
                <div><dt>Rows</dt><dd>{{ number_format($table->liveTuples) }}</dd></div>
                <div><dt>Dead rows</dt><dd>{{ number_format($table->deadTuples) }} <span class="note">{{ $percent($table->deadTupleRatio()) }}</span></dd></div>
                <div><dt>Heap</dt><dd>{{ Bytes::human($table->heapBytes) }}</dd></div>
                <div><dt>Indexes</dt><dd>{{ Bytes::human($table->indexBytes) }}</dd></div>
                <div><dt>TOAST</dt><dd>{{ $table->toastBytes === 0 ? 'none' : Bytes::human($table->toastBytes) }}</dd></div>
                <div><dt>Freeze age</dt><dd>{{ number_format($table->xidAge) }} <span class="note">txns</span></dd></div>
            </dl>
        </div>
    </section>

    @if ($findings !== [])
        <section class="panel">
            <div class="panel__bar">
                <span>What Vacuum thinks</span>
                <span class="right">{{ count($findings) }} {{ Str::plural('finding', count($findings)) }}</span>
            </div>

            {{-- The advisor's findings, narrowed to this table. The page judges
                 nothing of its own: a second opinion that disagreed with the
                 dashboard would be a bug rather than a feature. --}}
            @foreach ($findings as $finding)
                <article class="finding finding--{{ $finding->severity->value }}">
                    <div class="gutter">
                        <span class="gutter__severity">{{ $finding->severity->value }}</span>
                        <span class="gutter__rule">{{ $finding->rule }}</span>
                    </div>

                    <div class="body">
                        <p class="summary">{{ $finding->summary }}</p>

                        @if ($finding->remediation !== null)
                            <div class="actions">
                                <span class="fix">
                                    <pre>{{ $finding->remediation }}</pre>
                                    <button type="button" class="copy" data-copy="{{ $finding->remediation }}">copy</button>
                                </span>
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    <section class="panel">
        <div class="panel__bar">
            <span>How it is read</span>
            <span class="right">since the counters were last reset</span>
        </div>

        <div class="panel__body">
            <dl class="facts">
                <div>
                    <dt>Sequential scans</dt>
                    <dd>{{ number_format($table->sequentialScans) }} <span class="note">{{ $percent($table->sequentialShare()) }} of reads</span></dd>
                </div>
                <div>
                    <dt>Index scans</dt>
                    <dd>{{ number_format($table->indexScans) }}</dd>
                </div>
                <div>
                    <dt>Rows read by scanning</dt>
                    <dd>{{ number_format($table->sequentialTuplesRead) }}</dd>
                </div>
                <div>
                    <dt>Rows found by index</dt>
                    <dd>{{ number_format($table->indexTuplesFetched) }}</dd>
                </div>
            </dl>

            @if ($table->sequentialShare() === null)
                <p class="aside">Nothing has read this table since the counters were reset, which is not the same as nothing needing it.</p>
            @elseif ($table->sequentialShare() > 0.5 && $table->totalBytes > 8 * 1024 * 1024)
                {{-- Deliberately not a finding and deliberately not a CREATE INDEX.
                     A scan is right on a small table and can be right on a large one,
                     and an index guessed from a counter, with no idea what the query
                     asked for, is how a tool starts giving confident bad advice. --}}
                <p class="aside">
                    Most reads of this table scan the whole thing. On a table this size that is worth
                    understanding — but a scan is not automatically wrong, and the query that is doing it
                    is the thing to look at rather than the counter.
                </p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel__bar">
            <span>How it is written</span>
            <span class="right">HOT updates cost no index maintenance</span>
        </div>

        <div class="panel__body">
            <dl class="facts">
                <div><dt>Inserts</dt><dd>{{ number_format($table->inserts) }}</dd></div>
                <div><dt>Updates</dt><dd>{{ number_format($table->updates) }}</dd></div>
                <div><dt>Deletes</dt><dd>{{ number_format($table->deletes) }}</dd></div>
                <div>
                    <dt>HOT updates</dt>
                    <dd>{{ number_format($table->hotUpdates) }} <span class="note">{{ $percent($table->hotUpdateRatio()) }}</span></dd>
                </div>
            </dl>

            @if ($table->hotUpdateRatio() !== null && $table->hotUpdateRatio() < 0.5 && $table->updates > 10_000)
                <p class="aside">
                    Fewer than half of the updates to this table fitted in the page they started in, so the rest
                    rewrote every index on it. A HOT update needs free space in the page: lowering the fillfactor
                    leaves that room, at the cost of a larger table. It only helps when the updated columns are
                    not themselves indexed.
                </p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel__bar">
            <span>Autovacuum</span>
            <span class="right">{{ $table->tuned ? 'tuned for this table' : 'the server defaults' }}</span>
        </div>

        <div class="panel__body">
            <dl class="facts">
                <div><dt>Last vacuum</dt><dd>{{ $when($table->lastVacuumedAt()) }}</dd></div>
                <div><dt>Last analyze</dt><dd>{{ $when($table->lastAnalyzedAt()) }}</dd></div>
                <div>
                    <dt>Vacuums at</dt>
                    <dd>{{ number_format($table->vacuumsAt()) }} <span class="note">dead rows</span></dd>
                </div>
                <div>
                    <dt>Analyzes at</dt>
                    <dd>{{ number_format($table->analyzesAt()) }} <span class="note">changed rows</span></dd>
                </div>
            </dl>

            {{-- The question everybody has and almost nobody can answer, because the
                 setting is a scale factor and not a number. --}}
            <p class="aside">
                Autovacuum starts on this table once it holds <b>{{ number_format($table->vacuumsAt()) }}</b> dead rows.
                It holds <b>{{ number_format($table->deadTuples) }}</b>.
                The threshold is {{ number_format($table->vacuumThreshold) }} plus
                {{ $table->vacuumScaleFactor }} of the {{ number_format($table->liveTuples) }} rows it has —
                which is why a large table can carry an enormous number of dead rows and still be considered fine.

                @unless ($table->tuned)
                    A per-table <code>autovacuum_vacuum_scale_factor</code> is how that is fixed for one table
                    without changing it for every table.
                @endunless
            </p>
        </div>
    </section>

    <section class="panel">
        <div class="panel__bar">
            <span>Indexes</span>
            <span class="right">{{ count($indexes) }} · {{ Bytes::human($table->indexBytes) }}</span>
        </div>

        @if ($indexes === [])
            <div class="empty">
                <p>This table has no indexes.</p>
                <p>Every read of it is a scan, and every scan reads all {{ number_format($table->liveTuples) }} rows.</p>
            </div>
        @else
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Index</th>
                            <th>Scans</th>
                            <th>Size</th>
                            <th>Enforces</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($indexes as $index)
                            <tr>
                                <td>{{ $index->name }}</td>
                                <td>{{ number_format($index->scans) }}</td>
                                <td>{{ Bytes::human($index->bytes) }}</td>
                                <td>{{ $index->primary ? 'primary key' : ($index->unique ? 'unique' : '—') }}</td>
                                <td>
                                    @if (! $index->valid)
                                        <span class="bad">invalid</span>
                                    @elseif ($index->neverUsed() && ! $index->constrains())
                                        <span class="note">never read</span>
                                    @else
                                        ok
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

@section('status')
    <div class="status" role="status">
        <span><b>esc</b> back to findings</span>
        <span><b>c</b> console</span>
        <span class="said" data-said aria-live="polite"></span>
    </div>

    <script>
        (function () {
            const said = document.querySelector('[data-said]');

            document.addEventListener('keydown', function (event) {
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) {
                    return;
                }

                if (event.key === 'Escape') {
                    window.location = @json(route('vacuum.dashboard'));
                } else if (event.key === 'c') {
                    const console = document.querySelector('nav a[href*="console"]');

                    if (console !== null) {
                        window.location = console.href;
                    }
                }
            });

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    try {
                        await navigator.clipboard.writeText(button.dataset.copy);
                        button.textContent = 'copied';
                        said.textContent = 'the fix is on your clipboard';
                    } catch (refused) {
                        // The clipboard needs a secure context, and a dashboard served
                        // over plain http is not one. Say so rather than pretend.
                        button.textContent = 'select it';
                    }

                    setTimeout(function () {
                        button.textContent = 'copy';
                        said.textContent = '';
                    }, 2000);
                });
            });
        })();
    </script>
@endsection
