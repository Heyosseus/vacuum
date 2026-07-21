@extends('vacuum::layout')

{{-- The page the domain layer's internals explorers exist for: open one real
     8 kB heap page and see a dead tuple, a HOT chain, and the free space
     fillfactor leaves for the next update -- rather than take any of it on
     faith from a summary. Row versions need nothing beyond an ordinary
     SELECT and are shown whether or not the deeper, pageinspect-backed
     panels above them can run. --}}

@section('content')
    @if ($chosen === null)
        <section class="panel">
            <div class="panel__bar"><span>Internals</span></div>
            <div class="panel__body">
                <p class="subject" style="font-size: 1rem">Choose a table to open one of its pages.</p>
                <p class="aside">
                    This opens a real page the way PostgreSQL stores it: its line pointers, which of
                    them are dead, and the HOT chains a normal <code>SELECT</code> never reveals. The
                    row version panel below needs nothing but an ordinary <code>SELECT</code>; the page
                    itself needs the <code>pageinspect</code> extension and superuser.
                </p>
            </div>
        </section>

        <section class="panel">
            <div class="panel__bar">
                <span>Tables</span>
                <span class="right">{{ count($relations) }} · largest first</span>
            </div>

            @if ($relations === [])
                <div class="empty">
                    <p>This connection has no tables to look inside.</p>
                </div>
            @else
                <div class="scroll">
                    <table>
                        <thead><tr><th>Table</th><th>Live rows</th><th>Dead rows</th></tr></thead>
                        <tbody>
                            @foreach ($relations as $relation)
                                <tr>
                                    <td>
                                        <a class="open" href="{{ route('vacuum.internals', ['schema' => $relation->schema, 'table' => $relation->name]) }}">
                                            {{ $relation->qualifiedName() }}
                                        </a>
                                    </td>
                                    <td>{{ number_format($relation->liveTuples) }}</td>
                                    <td>{{ number_format($relation->deadTuples) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @else
        <section class="panel">
            <div class="panel__bar">
                <span>{{ $chosen['schema'] }}.{{ $chosen['table'] }}</span>
                <span class="right">
                    <a class="open" href="{{ route('vacuum.internals') }}">choose another table</a>
                </span>
            </div>
        </section>

        @if ($error !== null)
            {{-- A block outside the relation's range, or a relation the URL named that
                 no longer exists. Either way it is the reader's URL that is wrong, not
                 the server, so it gets a panel rather than a stack trace. --}}
            <section class="panel">
                <div class="panel__bar"><span>Page</span></div>
                <div class="panel__body">
                    <p class="aside">This table has no block {{ $block }} to show. {{ $error }}</p>
                </div>
            </section>
        @elseif (! $pageAvailability->available)
            {{-- Panels 1-3 need pageinspect and, in practice, superuser -- the common
                 case on managed PostgreSQL is neither. Saying so here, rather than
                 rendering three empty panels, is the whole point of Availability. --}}
            <section class="panel">
                <div class="panel__bar"><span>Page</span></div>
                <div class="panel__body">
                    <p class="aside">
                        {{ $pageAvailability->reason }}
                        @if ($pageAvailability->remedy !== null)
                            <br><code>{{ $pageAvailability->remedy }}</code>
                        @endif
                    </p>
                    <p class="aside">
                        Managed PostgreSQL -- RDS, Cloud SQL, Azure, Supabase, Neon -- typically withholds
                        superuser entirely, which is why <code>pageinspect</code> is often unavailable
                        there no matter what is granted. The row versions below need no extension and
                        still work.
                    </p>
                </div>
            </section>
        @elseif ($page !== null && ! $page->isHeapLayout())
            <section class="panel">
                <div class="panel__bar"><span>Not a heap page</span></div>
                <div class="panel__body">
                    <p>
                        Block {{ $page->block }} reserves a special area, which a heap page never does. It
                        belongs to an index or another access method, and the tuple decoding below would
                        read its bytes as though they were rows -- producing a confident answer made of
                        nothing rather than an error. So nothing is shown.
                    </p>
                </div>
            </section>
        @elseif ($page !== null)
            @php
                $pointerBytes = $page->lower;
                $freeBytes = $page->freeBytes();
                $tupleBytes = $page->pageSize - $page->upper;
                $denominator = max($page->pageSize, 1);
            @endphp

            <section class="panel">
                <div class="panel__bar">
                    <span>Page {{ $page->block }}</span>
                    <span class="right">{{ number_format($page->pageSize) }} bytes</span>
                </div>

                <div class="panel__body">
                    <dl class="facts">
                        <div><dt>LSN</dt><dd>{{ $page->lsn }}</dd></div>
                        <div><dt>Prune xid</dt><dd>{{ $page->pruneXid }}</dd></div>
                        <div><dt>Lower</dt><dd>{{ number_format($page->lower) }}</dd></div>
                        <div><dt>Upper</dt><dd>{{ number_format($page->upper) }}</dd></div>
                        <div><dt>Special</dt><dd>{{ number_format($page->special) }}</dd></div>
                    </dl>

                    {{-- Line pointers grow down from lower, tuples grow up from upper, and
                         the gap between them is what fillfactor leaves for a HOT update to
                         land in. Seeing that gap is what makes the setting stop being an
                         abstraction. --}}
                    <div class="pagebar" role="img" aria-label="{{ number_format($pointerBytes) }} bytes of line pointers, {{ number_format($freeBytes) }} bytes free, {{ number_format($tupleBytes) }} bytes of tuples">
                        <span class="pagebar__seg pagebar__seg--pointers" style="width: {{ $pointerBytes / $denominator * 100 }}%"></span>
                        <span class="pagebar__seg pagebar__seg--free" style="width: {{ $freeBytes / $denominator * 100 }}%"></span>
                        <span class="pagebar__seg pagebar__seg--tuples" style="width: {{ $tupleBytes / $denominator * 100 }}%"></span>
                    </div>
                    <p class="note">{{ number_format($freeBytes) }} bytes free of {{ number_format($page->pageSize) }}.</p>
                </div>
            </section>

            <section class="panel">
                <div class="panel__bar">
                    <span>Line pointers</span>
                    <span class="right">{{ count($page->pointers) }}</span>
                </div>

                <div class="panel__body">
                    <p class="aside">
                        <code>xmax = 0</code> means nothing has touched this row version since it was
                        written. A non-zero <code>xmax</code> does <em>not</em> on its own mean the row
                        is dead: PostgreSQL writes <code>xmax</code> for a lock as well as for a delete,
                        so a row somebody is holding with <code>SELECT ... FOR UPDATE</code> carries one
                        and is entirely current, and a row whose deleting transaction rolled back keeps
                        the <code>xmax</code> that never took effect. The <code>locked only</code> and
                        <code>xmax invalid</code> flags are what tell those apart, and only the rows
                        highlighted below are ones a later version has genuinely replaced -- the row
                        versions <code>VACUUM</code> exists to reclaim.
                    </p>
                </div>

                <div class="scroll">
                    <table>
                        <thead>
                            <tr><th>lp</th><th>state</th><th>off</th><th>len</th><th>xmin</th><th>xmax</th><th>ctid</th><th>flags</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($page->pointers as $pointer)
                                <tr class="
                                    @if ($pointer->isDead) lp--dead
                                    @elseif ($pointer->isRedirect) lp--redirect
                                    @elseif ($pointer->heapOnly) lp--heaponly
                                    @endif
                                ">
                                    <td>{{ $pointer->lineNumber }}</td>
                                    <td>{{ $pointer->state }}</td>
                                    <td>{{ $pointer->offset }}</td>
                                    <td>{{ $pointer->length }}</td>
                                    <td>{{ $pointer->xmin ?? '—' }}</td>
                                    <td class="@if ($pointer->state === 'normal' && $pointer->isSuperseded()) lp__xmax--superseded @endif">
                                        {{ $pointer->xmax ?? '—' }}
                                    </td>
                                    <td>{{ $pointer->ctid ?? '—' }}</td>
                                    <td class="note">{{ $pointer->flags === [] ? '—' : implode(', ', $pointer->flags) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @php $chains = $page->hotChains(); @endphp
            <section class="panel">
                <div class="panel__bar">
                    <span>HOT chains</span>
                    <span class="right">{{ count($chains) }}</span>
                </div>

                <div class="panel__body">
                    @if ($chains === [])
                        <p>No HOT chains on this page.</p>
                        <p class="note">
                            Nothing on this page has been updated in place. Either nothing here has been
                            updated at all, or every update found no room left in the page or touched an
                            indexed column -- and an update that cannot go HOT writes the new version
                            elsewhere and rewrites every index to point at it, which is the cost
                            <code>fillfactor</code> exists to avoid.
                        </p>
                    @else
                        @foreach ($chains as $chain)
                            <p class="chain">
                                @foreach ($chain->lineNumbers as $lineNumber)
                                    <span class="chain__lp">lp {{ $lineNumber }}</span>@if (! $loop->last)<span class="chain__arrow"> → </span>@endif
                                @endforeach
                                <span class="note">(current)</span>
                            </p>
                        @endforeach

                        <p class="aside">
                            A HOT update keeps the new row version on the same page and skips updating
                            every index -- which is why fillfactor matters, why an index on a
                            frequently-updated column is expensive, and why these chains are the cheap
                            kind of update.
                        </p>
                    @endif
                </div>
            </section>
        @endif

        <section class="panel">
            <div class="panel__bar">
                <span>Row versions</span>
                <span class="right">{{ count($rowVersions) }}</span>
            </div>

            <div class="panel__body">
                <p class="aside">
                    These come from <code>ctid</code>, <code>xmin</code> and <code>xmax</code> -- system
                    columns present on every table, needing no extension -- so this panel works on
                    managed PostgreSQL where the ones above may not.
                </p>
            </div>

            @if ($rowVersions === [])
                <div class="empty">
                    <p>No row versions read.</p>
                </div>
            @else
                <div class="scroll">
                    <table>
                        <thead><tr><th>ctid</th><th>block</th><th>offset</th><th>xmin</th><th>xmax</th><th>xmax unset</th></tr></thead>
                        <tbody>
                            @foreach ($rowVersions as $version)
                                <tr>
                                    <td>{{ $version->ctid }}</td>
                                    <td>{{ $version->block }}</td>
                                    <td>{{ $version->offset }}</td>
                                    <td>{{ $version->xmin }}</td>
                                    <td>{{ $version->xmax }}</td>
                                    <td>{{ $version->untouched ? 'yes' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection

@if ($chosen !== null && $blockCount > 0)
    @section('status')
        <div class="status" role="status">
            @if ($block > 0)
                <a href="{{ route('vacuum.internals', ['schema' => $chosen['schema'], 'table' => $chosen['table'], 'block' => $block - 1]) }}">
                    <b>&lt;</b> block {{ $block - 1 }}
                </a>
            @endif

            <span>block {{ $block }} of {{ $blockCount - 1 }}</span>

            @if ($block < $blockCount - 1)
                <a href="{{ route('vacuum.internals', ['schema' => $chosen['schema'], 'table' => $chosen['table'], 'block' => $block + 1]) }}">
                    block {{ $block + 1 }} <b>&gt;</b>
                </a>
            @endif

            <form method="get" action="{{ route('vacuum.internals') }}">
                <input type="hidden" name="schema" value="{{ $chosen['schema'] }}">
                <input type="hidden" name="table" value="{{ $chosen['table'] }}">
                <label for="block-jump">jump to block</label>
                <input id="block-jump" name="block" type="number" min="0" max="{{ $blockCount - 1 }}" value="{{ $block }}">
                <button type="submit">go</button>
            </form>
        </div>
    @endsection
@endif
