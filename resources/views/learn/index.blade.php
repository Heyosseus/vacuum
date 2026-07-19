@extends('vacuum::layout')

{{-- The index of the Learn section. It is a contents page and nothing more: the
     tiers come from the curriculum, and a lesson earns a row here by being
     registered, so this file never has to be edited to add one. --}}

@php
    /** Counting the roots would undercount every tier that nests. */
    $countNodes = static function (array $nodes) use (&$countNodes): int {
        $total = 0;

        foreach ($nodes as $node) {
            $total += 1 + $countNodes($node->children);
        }

        return $total;
    };
@endphp

@section('content')
    <section class="panel">
        <div class="panel__bar">
            <span>Learn</span>
            <span class="right">{{ count($lessons) }} {{ count($lessons) === 1 ? 'lesson' : 'lessons' }}</span>
        </div>

        <div class="panel__body">
            <div class="lesson">
                <p>
                    These lessons explain how PostgreSQL actually works, and then prove each one
                    against <b>this</b> database rather than an invented example. The tables named
                    in them are your tables, and the numbers are the ones your server is carrying
                    right now.
                </p>

                <p>
                    Every one of them reads the system catalog and the statistics views only.
                    Nothing on these pages writes, and nothing on them is slow enough to notice.
                </p>
            </div>
        </div>
    </section>

    @foreach ($tiers as $label => $tierNodes)
        @php $tierNodeCount = $countNodes($tierNodes); @endphp
        <section class="panel">
            <div class="panel__bar">
                <span>{{ $label }}</span>
                <span class="right">{{ $tierNodeCount }} {{ $tierNodeCount === 1 ? 'lesson' : 'lessons' }}</span>
            </div>

            <div class="scroll">
                <table>
                    <tbody>
                        @foreach ($tierNodes as $node)
                            @include('vacuum::learn.node', ['node' => $node, 'depth' => 0])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
@endsection

@section('status')
    <div class="status" role="status">
        <span>{{ count($lessons) }} {{ count($lessons) === 1 ? 'lesson' : 'lessons' }}</span>
        <span>every page here reads only the catalog and the statistics views</span>
    </div>
@endsection
