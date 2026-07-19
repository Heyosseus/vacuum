@extends('vacuum::layout')

{{-- One lesson, in four bands, always in this order: what is going on, what is
     going on *here*, what to do about it when there is a fork to resolve, and
     something to go and run. The middle two are the reason the section exists --
     an explainer on the internet can write the first band and the last, and
     neither can name your largest table or say which arm of the fork it took. --}}

@section('content')
    <section class="panel">
        <div class="panel__bar">
            <span>{{ $lesson->title() }}</span>
            <span class="right">{{ $lesson->tier()->label() }}</span>
        </div>

        <div class="panel__body">
            {{-- The partial is named for the slug the resolved lesson reports, never
                 for the string the URL supplied. --}}
            <div class="lesson">
                @include('vacuum::learn.lessons.'.$lesson->slug())
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__bar">
            <span>In your database</span>
            <span class="right">read just now, from the statistics views</span>
        </div>

        <div class="panel__body">
            <p class="summary">@include('vacuum::learn.headline', ['headline' => $observation->headline])</p>

            {{-- An empty observation is never rendered as an empty table. The lesson
                 knows why it found nothing, and that sentence is more use to a reader
                 than a header row with no rows under it. --}}
            @if ($observation->isEmpty())
                @if ($observation->note !== null)
                    <p class="aside">{{ $observation->note }}</p>
                @endif
            @endif
        </div>

        @if (! $observation->isEmpty())
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            @foreach ($observation->columns as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($observation->rows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($tree !== null)
        <section class="panel">
            <div class="panel__bar">
                <span>What to do about it</span>
                <span class="right">your tables, sorted onto the answer they landed on</span>
            </div>

            <div class="panel__body">
                {{-- Every branch renders whether or not this database demonstrates it.
                     The tree is teaching material first and a verdict second: a reader
                     needs to know the other arm exists, and a fresh install with no
                     statistics still deserves a whole lesson. --}}
                <p class="summary">{{ $tree->question }}</p>

                <ul class="tree">
                    @foreach ($tree->branches as $branch)
                        <li class="tree__branch @if ($branch->isTaken()) tree__branch--taken @endif">
                            <b class="tree__condition">{{ $branch->condition }}</b>
                            <span class="tree__outcome">{{ $branch->outcome }}</span>

                            @if ($branch->isTaken())
                                <span class="tree__landed">{{ implode(', ', $branch->landed) }}</span>
                            @endif

                            @if ($branch->fix !== null)
                                <span class="fix">
                                    <pre>{{ $branch->fix }}</pre>
                                    <button type="button" class="copy" data-copy="{{ $branch->fix }}">copy</button>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($tryIt !== null)
        <section class="panel">
            <div class="panel__bar">
                <span>Try it</span>
                <span class="right">a SELECT, handed over rather than run</span>
            </div>

            <div class="panel__body">
                {{-- The same affordance the dashboard hands a fix over with: shown,
                     copyable, and never executed by this page. --}}
                <span class="fix">
                    <pre>{{ $tryIt }}</pre>
                    <button type="button" class="copy" data-copy="{{ $tryIt }}">copy</button>
                </span>

                @if (Route::has('vacuum.console'))
                    <p class="aside">
                        <a class="open" href="{{ route('vacuum.console', ['statement' => $tryIt]) }}">
                            open it in the console
                        </a>
                        &mdash; it arrives typed in and not run.
                    </p>
                @endif
            </div>
        </section>
    @endif
@endsection

@section('status')
    <div class="status" role="status">
        @if ($previous !== null)
            <a href="{{ route('vacuum.lesson', ['lesson' => $previous->slug()]) }}">
                <b>&lt;</b> {{ $previous->title() }}
            </a>
        @endif

        @if ($after !== null)
            <a href="{{ route('vacuum.lesson', ['lesson' => $after->slug()]) }}">
                builds on {{ $after->title() }}
            </a>
        @endif

        <a href="{{ route('vacuum.learn') }}">all lessons</a>

        @if ($next !== null)
            <a href="{{ route('vacuum.lesson', ['lesson' => $next->slug()]) }}">
                {{ $next->title() }} <b>&gt;</b>
            </a>
        @endif

        <span class="said" data-said aria-live="polite"></span>
    </div>

    {{-- Two behaviours, both lifted from the dashboard rather than reinvented: the
         [+] fold that keeps the deep material off the first screen, and the copy
         button that reports honestly when the clipboard is unavailable. --}}
    <script>
        (function () {
            const said = document.querySelector('[data-said]');

            function announce(message) {
                said.textContent = message;
                setTimeout(function () { said.textContent = ''; }, 2000);
            }

            document.querySelectorAll('[data-why]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const open = button.getAttribute('aria-expanded') === 'true';

                    button.setAttribute('aria-expanded', String(!open));
                    button.closest('.lesson').querySelector('[data-impact]').hidden = open;
                });
            });

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    try {
                        await navigator.clipboard.writeText(button.dataset.copy);
                        button.textContent = 'copied';
                        announce('the statement is on your clipboard');
                    } catch (refused) {
                        // The clipboard needs a secure context, and a dashboard served
                        // over plain http is not one. Say so rather than pretend.
                        button.textContent = 'select it';
                    }

                    setTimeout(function () { button.textContent = 'copy'; }, 2000);
                });
            });
        })();
    </script>
@endsection
