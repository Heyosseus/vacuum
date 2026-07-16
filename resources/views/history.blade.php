@extends('vacuum::layout')

@section('content')
    <style>
        .spark { width: 100%; height: 4rem; display: block; }
        .spark__line { fill: none; stroke: var(--amber); stroke-width: 1.25; stroke-linejoin: round; stroke-linecap: round; }
        .figure { display: flex; align-items: baseline; gap: 0.5rem; margin-bottom: 0.75rem; }
        .figure b { font: 400 2rem/1 var(--mono); }
        .figure span { font: 400 0.6875rem/1 var(--mono); letter-spacing: 0.12em; text-transform: uppercase; color: var(--faint); }
        .forecast { color: var(--amber); }
    </style>

    @if (! $hasHistory)
        <section class="panel">
            <div class="panel__bar"><span>History</span></div>
            <div class="empty">
                <p>No snapshots yet.</p>
                <p>Once <code>vacuum:snapshot</code> has run, the database's health over time, its new
                    and cleared findings, and its forecasts appear here.</p>
            </div>
        </section>
    @else
        <section class="panel">
            <div class="panel__bar">
                <span>Health over time</span>
                <span class="right">{{ count($scores) }} {{ \Illuminate\Support\Str::plural('snapshot', count($scores)) }}</span>
            </div>

            <div class="panel__body">
                @if ($latestScore !== null)
                    <div class="figure"><b>{{ $latestScore }}</b><span>/ 100 latest</span></div>
                @endif

                @if ($sparkline !== '')
                    <svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" role="img" aria-label="Health score over time">
                        <polyline class="spark__line" points="{{ $sparkline }}" />
                    </svg>
                @endif
            </div>
        </section>

        @if (count($forecasts) > 0)
            <section class="panel">
                <div class="panel__bar"><span>Forecast to cross critical</span></div>
                <div class="scroll">
                    <table>
                        <thead>
                            <tr><th>Subject</th><th>Rule</th><th>Projected</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($forecasts as $view)
                                <tr>
                                    <td>{{ $view->finding->subject }}</td>
                                    <td class="note">{{ $view->finding->rule }}</td>
                                    <td class="forecast">
                                        @if ($view->forecast->days <= 0)
                                            imminently
                                        @else
                                            ~{{ $view->forecast->days }} days
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if (count($newFindings) > 0)
            <section class="panel">
                <div class="panel__bar">
                    <span>New since the previous snapshot</span>
                    <span class="right">{{ count($newFindings) }}</span>
                </div>
                <div class="scroll">
                    <table>
                        <thead><tr><th>Subject</th><th>Rule</th><th>Severity</th></tr></thead>
                        <tbody>
                            @foreach ($newFindings as $finding)
                                <tr>
                                    <td>{{ $finding->subject }}</td>
                                    <td class="note">{{ $finding->rule }}</td>
                                    <td>{{ $finding->severity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if (count($clearedFindings) > 0)
            <section class="panel">
                <div class="panel__bar">
                    <span>Cleared since the previous snapshot</span>
                    <span class="right">{{ count($clearedFindings) }}</span>
                </div>
                <div class="scroll">
                    <table>
                        <thead><tr><th>Subject</th><th>Rule</th></tr></thead>
                        <tbody>
                            @foreach ($clearedFindings as $finding)
                                <tr>
                                    <td>{{ $finding->subject }}</td>
                                    <td class="note">{{ $finding->rule }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
@endsection
