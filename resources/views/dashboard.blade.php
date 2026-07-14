@extends('vacuum::layout')

@section('content')
    <h2>Findings</h2>

    @forelse ($findings as $finding)
        <article class="finding finding--{{ $finding->severity->value }}">
            <div class="finding__head">
                <span class="badge badge--{{ $finding->severity->value }}">{{ $finding->severity->value }}</span>
                <span class="finding__subject">{{ $finding->subject }}</span>
                <span class="rule">{{ $finding->rule }}</span>
            </div>

            <p class="finding__summary">{{ $finding->summary }}</p>
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
