@extends('vacuum::layout')

@section('content')
    <section class="health health--{{ strtolower($health->grade->value) }}">
        <div class="health__score">
            <strong>{{ $health->score }}</strong><span>/ 100</span>
            <em>Grade {{ $health->grade->value }}</em>
        </div>

        {{-- The arithmetic is shown rather than asserted. A score you cannot
             check is a score you cannot argue with, and this one is only ever
             a hundred minus what the findings below it cost. --}}
        <div class="health__working">
            @forelse ($health->deductions as $rule => $cost)
                <span class="working"><code>{{ $rule }}</code> &minus;{{ $cost }}</span>
            @empty
                <span class="working">Nothing has been deducted.</span>
            @endforelse
        </div>

        {{-- Without this line the score and the letter appear to disagree. They do
             not: the number is the arithmetic, and the letter is a judgement the
             arithmetic is not allowed to overrule. --}}
        @if ($health->capped)
            <p class="health__capped">
                Held at {{ $health->grade->value }} because something below is critical.
                A database with a critical finding does not get a passing grade, whatever the score.
            </p>
        @endif
    </section>

    @if ($vacuums !== [])
        <h2>Vacuuming now</h2>

        <div class="result">
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Phase</th>
                        <th>Heap scanned</th>
                        <th>Index passes</th>
                        <th>Started by</th>
                        <th>PID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vacuums as $vacuum)
                        <tr>
                            <td>{{ $vacuum->qualifiedName() }}</td>
                            <td>{{ $vacuum->phase }}</td>
                            <td>
                                @if ($vacuum->percentScanned() === null)
                                    &mdash;
                                @else
                                    {{ $vacuum->percentScanned() }}%
                                @endif
                            </td>
                            <td>
                                {{ $vacuum->indexPasses }}
                                @if ($vacuum->indexPasses > 1)
                                    <span class="rule">maintenance_work_mem too small</span>
                                @endif
                            </td>
                            <td>{{ $vacuum->automatic ? 'autovacuum' : 'a person' }}</td>
                            <td>{{ $vacuum->pid }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Findings</h2>

    @forelse ($findings as $finding)
        <article class="finding finding--{{ $finding->severity->value }}">
            <div class="finding__head">
                <span class="badge badge--{{ $finding->severity->value }}">{{ $finding->severity->value }}</span>
                <span class="finding__subject">{{ $finding->subject }}</span>
                <span class="rule">{{ $finding->rule }}</span>
            </div>

            <p class="finding__summary">{{ $finding->summary }}</p>

            @if ($finding->evidence !== null)
                <pre class="evidence">{{ $finding->evidence }}</pre>
            @endif

            <p class="finding__impact">{{ $finding->impact }}</p>

            @if ($finding->remediation !== null)
                {{-- Shown, never run. Vacuum has no code path that writes to the
                     database it inspects, and this is where that promise is kept. --}}
                <pre>{{ $finding->remediation }}</pre>
            @endif
        </article>
    @empty
        <p class="empty">Nothing to report. Every table is within its thresholds.</p>
    @endforelse
@endsection
