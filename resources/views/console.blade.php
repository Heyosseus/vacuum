@extends('vacuum::layout')

@section('content')
    <section class="panel">
        <div class="panel__bar">
            <span>Console</span>
            <span class="right">read only · rolled back · {{ $connection }}</span>
        </div>

        <div class="panel__body">
            <form method="POST" action="{{ route('vacuum.console.run') }}" class="console">
                @csrf

                <textarea name="statement" rows="8" spellcheck="false" autofocus
                          aria-label="Statement to run"
                          placeholder="SELECT relname, n_dead_tup FROM pg_stat_user_tables ORDER BY n_dead_tup DESC">{{ $statement }}</textarea>

                <div class="console__foot">
                    {{-- The promise, stated as narrowly as it is actually true. A
                         READ ONLY transaction stops this backend writing through
                         MVCC; it does not stop a function that opens a second
                         connection, or one with a side effect outside MVCC. What
                         bounds those is the role this connects as. Saying
                         "PostgreSQL refuses the write" full stop was a claim the
                         database does not make. --}}
                    <p class="promise">
                        Runs inside a <b>read-only transaction</b> that is always rolled back,
                        so PostgreSQL refuses the write rather than this form.
                        Beyond that, this console can do whatever
                        <b>{{ $connection }}</b>'s role may do &mdash; grant it little.
                    </p>

                    <button type="submit" class="run">Run</button>
                </div>
            </form>

            @error('statement')
                <p class="error">{{ $message }}</p>
            @enderror

            @if ($error !== null)
                {{-- PostgreSQL's own words, the way PostgreSQL said them. A console
                     that rewrites the database's errors is a console that lies about
                     them. --}}
                <pre class="error">{{ $error }}</pre>
            @endif
        </div>
    </section>

    @if ($result !== null)
        <section class="panel">
            <div class="panel__bar">
                <span>Result</span>
                {{-- A capped result cannot say how many rows there were: finding out
                     means producing them all, which is the thing the cap prevents.
                     So it says what it knows -- the first N, and there was more. --}}
                <span class="right">
                    @if ($result->capped)
                        first {{ number_format(count($result->rows)) }} {{ Str::plural('row', count($result->rows)) }}, there are more
                    @else
                        {{ number_format(count($result->rows)) }} {{ Str::plural('row', count($result->rows)) }}
                    @endif

                    in {{ number_format($result->milliseconds, 1) }} ms
                </span>
            </div>

            @if ($result->rows === [])
                <div class="empty">
                    <p>The statement returned nothing.</p>
                    <p>It ran, and it matched no rows.</p>
                </div>
            @else
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                @foreach ($result->columns as $column)
                                    <th>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result->rows as $row)
                                <tr>
                                    @foreach ($result->columns as $column)
                                        <td>{{ $row[$column] ?? '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection

@section('status')
    <div class="status" role="status">
        <span><b>ctrl</b> + <b>enter</b> run</span>
        <span><b>esc</b> back to findings</span>
        <span class="said">every statement is rolled back</span>
    </div>

    <script>
        (function () {
            const form = document.querySelector('.console');
            const box = form.querySelector('textarea');

            // The habit every SQL client has taught: run what is in the box without
            // reaching for the mouse.
            box.addEventListener('keydown', function (event) {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    form.submit();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    window.location = @json(route('vacuum.dashboard'));
                }
            });

            // A statement that arrived from a finding is left with the caret at the
            // end rather than selected. Nobody wants their first keystroke to delete
            // the query they came here to read.
            box.setSelectionRange(box.value.length, box.value.length);
        })();
    </script>
@endsection
