@extends('vacuum::layout')

@section('content')
    <h2>Console</h2>

    <form method="POST" action="{{ route('vacuum.console.run') }}" class="console">
        @csrf

        <textarea name="statement" rows="5" spellcheck="false"
                  placeholder="SELECT relname, n_dead_tup FROM pg_stat_user_tables ORDER BY n_dead_tup DESC">{{ $statement }}</textarea>

        <div class="console__foot">
            <p class="console__note">
                Statements run inside a READ ONLY transaction that is always rolled back.
                PostgreSQL refuses the write, not this form.
            </p>

            <button type="submit">Run</button>
        </div>
    </form>

    @error('statement')
        <p class="error">{{ $message }}</p>
    @enderror

    @if ($error !== null)
        <pre class="error">{{ $error }}</pre>
    @endif

    @if ($result !== null)
        <p class="result__meta">
            {{ number_format($result->found) }} {{ Str::plural('row', $result->found) }}
            in {{ number_format($result->milliseconds, 1) }} ms
            @if ($result->truncated())
                &middot; showing the first {{ number_format(count($result->rows)) }}
            @endif
        </p>

        @if ($result->rows === [])
            <p class="empty">The statement returned nothing.</p>
        @else
            <div class="result">
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
    @endif
@endsection
