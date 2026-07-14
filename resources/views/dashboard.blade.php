@extends('vacuum::layout')

@section('content')
    @php
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $worst = [];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;

            // Each rule's cells take the colour of the worst thing that rule found.
            // A rule that fired once as critical and nine times as a warning is a
            // critical problem, and an average would soften it into a shrug.
            $current = $worst[$finding->rule] ?? null;

            if ($current === null || $finding->severity->rank() < $current->rank()) {
                $worst[$finding->rule] = $finding->severity;
            }
        }

        $cells = [];

        foreach ($health->deductions as $rule => $cost) {
            for ($i = 0; $i < $cost; $i++) {
                $cells[] = ['rule' => $rule, 'severity' => $worst[$rule]->value];
            }
        }
    @endphp

    <section class="panel">
        <div class="panel__bar">
            <span>Health</span>
            <span class="right">a hundred, minus what the findings below cost</span>
        </div>

        {{-- The signature. The score has always claimed to be a hundred minus what
             the findings cost, so here it is spent in front of you: a hundred cells,
             because a table is pages, and every rule below argues about pages being
             wasted. Press a rule's cells and the list filters to it. --}}
        <div class="panel__body score score--{{ strtolower($health->grade->value) }}">
            <div class="score__figure">
                <div class="score__number">{{ $health->score }}</div>
                <p class="score__grade">Grade {{ $health->grade->value }}</p>
            </div>

            <div>
                <div class="map" role="group" aria-label="What each rule cost the score">
                    @foreach ($cells as $cell)
                        <button type="button"
                                class="cell cell--spent cell--{{ $cell['severity'] }}"
                                data-cell="{{ $cell['rule'] }}"
                                aria-pressed="false">
                            <span class="hidden">{{ $cell['rule'] }}</span>
                        </button>
                    @endforeach

                    @for ($i = 0; $i < $health->score; $i++)
                        <span class="cell"></span>
                    @endfor
                </div>

                <p class="legend">
                    @forelse ($health->deductions as $rule => $cost)
                        <span>{{ $rule }} <span class="legend__cost">&minus;{{ $cost }}</span></span>
                    @empty
                        <span class="legend__free">Nothing has been deducted.</span>
                    @endforelse

                    @if ($health->score > 0 && $health->deductions !== [])
                        <span class="legend__free">{{ $health->score }} left</span>
                    @endif
                </p>
            </div>

            {{-- Without this the score and the letter look as though they disagree.
                 They do not: the number is arithmetic, and the letter is a judgement
                 the arithmetic is not allowed to overrule. --}}
            @if ($health->capped)
                <p class="capped">
                    Held at {{ $health->grade->value }} because something below is critical.
                    A database with a critical finding does not get a passing grade, whatever the score.
                </p>
            @endif
        </div>
    </section>

    {{-- Only while something is running. A panel that exists to say "no vacuums"
         costs a reader a glance every time, in order to tell them nothing. --}}
    @if ($vacuums !== [])
        <section class="panel">
            <div class="panel__bar">
                <span>Vacuuming now</span>
                <span class="right">{{ count($vacuums) }} running</span>
            </div>

            <div class="scroll">
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
                                <td>{{ $vacuum->percentScanned() === null ? '—' : $vacuum->percentScanned().'%' }}</td>
                                <td>
                                    {{ $vacuum->indexPasses }}
                                    @if ($vacuum->indexPasses > 1)
                                        <span class="note">maintenance_work_mem is too small</span>
                                    @endif
                                </td>
                                <td>{{ $vacuum->automatic ? 'autovacuum' : 'a person' }}</td>
                                <td>{{ $vacuum->pid }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($findings === [])
        <section class="panel">
            <div class="panel__bar"><span>Findings</span></div>

            <div class="empty">
                <p>Nothing to report.</p>
                <p>Every table, index and session is inside the thresholds you set.</p>
            </div>
        </section>
    @else
        <div class="toolbar">
            <div class="filters">
                <button type="button" class="chip" aria-pressed="true" data-severity="all">
                    <span class="chip__key">0</span> all <span class="chip__count">{{ count($findings) }}</span>
                </button>

                @foreach (['critical' => '1', 'warning' => '2', 'info' => '3'] as $severity => $key)
                    @if ($counts[$severity] > 0)
                        <button type="button" class="chip" aria-pressed="false"
                                data-severity="{{ $severity }}" data-key="{{ $key }}">
                            <span class="chip__key">{{ $key }}</span>
                            <span class="chip__mark chip__mark--{{ $severity }}"></span>
                            {{ $severity }} <span class="chip__count">{{ $counts[$severity] }}</span>
                        </button>
                    @endif
                @endforeach
            </div>

            <input type="search" class="search" data-search
                   placeholder="/ filter by table, index or rule" aria-label="Filter findings">

            <select class="sort" data-sort aria-label="Sort findings">
                <option value="severity">most serious first</option>
                <option value="subject">subject a–z</option>
                <option value="rule">rule a–z</option>
            </select>

            <p class="tally" data-count aria-live="polite"></p>
        </div>

        <div class="findings" data-findings>
            @foreach ($findings as $finding)
                <article class="finding finding--{{ $finding->severity->value }}"
                         data-finding
                         data-severity="{{ $finding->severity->value }}"
                         data-rank="{{ $finding->severity->rank() }}"
                         data-subject="{{ $finding->subject }}"
                         data-rule="{{ $finding->rule }}"
                         @if ($finding->query !== null && Route::has('vacuum.console'))
                             data-open="{{ route('vacuum.console', ['statement' => $finding->query]) }}"
                         @endif>
                    {{-- The gutter is what PostgreSQL said. The column is what Vacuum
                         made of it. Keeping the two apart is the whole idea of the
                         package, so the page is built the same way. --}}
                    <div class="gutter">
                        <span class="gutter__severity">{{ $finding->severity->value }}</span>
                        <span class="gutter__rule">{{ $finding->rule }}</span>
                    </div>

                    <div class="body">
                        <p class="subject">{{ $finding->subject }}</p>
                        <p class="summary">{{ $finding->summary }}</p>

                        @if ($finding->evidence !== null)
                            <pre class="evidence">{{ $finding->evidence }}</pre>
                        @endif

                        {{-- The impact teaches the internals, and it is why this
                             package exists. It is also four lines long, and somebody
                             scanning ten findings does not want forty. It waits until
                             it is asked for. --}}
                        <button type="button" class="why" aria-expanded="false" data-why>why this matters</button>

                        <p class="impact" data-impact hidden>{{ $finding->impact }}</p>

                        @if ($finding->query !== null || $finding->remediation !== null)
                            <div class="actions">
                                {{-- Opens the console with the query typed in and not
                                     run. Always the SELECT that shows what the rule
                                     saw, never the fix: the fix writes, and the
                                     console refuses to write. --}}
                                @if ($finding->query !== null && Route::has('vacuum.console'))
                                    <a class="inspect"
                                       href="{{ route('vacuum.console', ['statement' => $finding->query]) }}">
                                        inspect in console
                                    </a>
                                @endif

                                @if ($finding->remediation !== null)
                                    {{-- Shown, never run. Vacuum has no code path that
                                         writes to the database it inspects, and this
                                         is where that promise is kept. --}}
                                    <span class="fix">
                                        <pre>{{ $finding->remediation }}</pre>
                                        <button type="button" class="copy" data-copy="{{ $finding->remediation }}">
                                            copy
                                        </button>
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach

            <div class="empty" data-nothing hidden>
                <p>No finding matches that.</p>
                <p>Press escape to clear the filter.</p>
            </div>
        </div>
    @endif
@endsection

@section('status')
    <div class="status" role="status">
        <span><b>/</b> filter</span>
        <span><b>1</b>–<b>3</b> severity</span>
        <span><b>j</b> <b>k</b> move</span>
        <span><b>enter</b> inspect</span>
        <span><b>c</b> console</span>
        <span><b>esc</b> clear</span>
        <span class="said" data-said aria-live="polite"></span>
    </div>

    {{-- Filtering and sorting happen here rather than on the server: the findings are
         already on the page, and a round trip to the database to hide a row would be
         a query the database did not need to answer. --}}
    <script>
        (function () {
            const list = document.querySelector('[data-findings]');
            const said = document.querySelector('[data-said]');

            function announce(message) {
                said.textContent = message;
                setTimeout(function () { said.textContent = ''; }, 2000);
            }

            document.addEventListener('keydown', function (event) {
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);

                if (event.key === 'c' && !typing) {
                    const console = document.querySelector('nav a[href*="console"]');

                    if (console !== null) {
                        window.location = console.href;
                    }
                }
            });

            if (list === null) {
                return;
            }

            const cards = Array.from(list.querySelectorAll('[data-finding]'));
            const chips = Array.from(document.querySelectorAll('[data-severity]'));
            const cells = Array.from(document.querySelectorAll('[data-cell]'));
            const search = document.querySelector('[data-search]');
            const sort = document.querySelector('[data-sort]');
            const count = document.querySelector('[data-count]');
            const nothing = document.querySelector('[data-nothing]');

            let severity = 'all';
            let rule = null;
            let cursor = -1;

            function shown() {
                return cards.filter(function (card) { return !card.hidden; });
            }

            function apply() {
                const term = search.value.trim().toLowerCase();
                let showing = 0;

                cards.forEach(function (card) {
                    const haystack = (card.dataset.subject + ' ' + card.dataset.rule).toLowerCase();

                    const visible = (severity === 'all' || card.dataset.severity === severity)
                        && (rule === null || card.dataset.rule === rule)
                        && (term === '' || haystack.includes(term));

                    card.hidden = !visible;
                    showing += visible ? 1 : 0;
                });

                count.textContent = 'showing ' + showing + ' of ' + cards.length;
                nothing.hidden = showing > 0;

                select(-1);
            }

            function select(index) {
                cards.forEach(function (card) { card.removeAttribute('data-on'); });

                const visible = shown();
                cursor = Math.max(-1, Math.min(index, visible.length - 1));

                if (cursor >= 0) {
                    visible[cursor].setAttribute('data-on', '');
                    visible[cursor].scrollIntoView({ block: 'nearest' });
                }
            }

            function order() {
                const by = sort.value;

                cards.slice().sort(function (a, b) {
                    if (by === 'severity') {
                        // The rank is the Severity enum's own, so the page cannot
                        // disagree with the advisor about what is worse.
                        return a.dataset.rank - b.dataset.rank
                            || a.dataset.subject.localeCompare(b.dataset.subject);
                    }

                    return a.dataset[by].localeCompare(b.dataset[by]);
                }).forEach(function (card) {
                    list.insertBefore(card, nothing);
                });

                select(-1);
            }

            function filter(chosen) {
                severity = chosen;

                chips.forEach(function (chip) {
                    chip.setAttribute('aria-pressed', String(chip.dataset.severity === chosen));
                });

                apply();
            }

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () { filter(chip.dataset.severity); });
            });

            // The cells a rule ate. Press them and the list is the findings that spent
            // them; press again and the budget is handed back.
            cells.forEach(function (cell) {
                cell.addEventListener('click', function () {
                    const pressed = cell.getAttribute('aria-pressed') === 'true';

                    rule = pressed ? null : cell.dataset.cell;

                    cells.forEach(function (other) {
                        other.setAttribute('aria-pressed', String(rule !== null && other.dataset.cell === rule));
                    });

                    apply();
                    announce(rule === null ? 'showing every rule' : 'filtered to ' + rule);
                });
            });

            cards.forEach(function (card, index) {
                card.addEventListener('click', function (event) {
                    if (event.target.closest('button, a') === null) {
                        select(shown().indexOf(card));
                    }
                });
            });

            list.querySelectorAll('[data-why]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const open = button.getAttribute('aria-expanded') === 'true';

                    button.setAttribute('aria-expanded', String(!open));
                    button.closest('.body').querySelector('[data-impact]').hidden = open;
                });
            });

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    try {
                        await navigator.clipboard.writeText(button.dataset.copy);
                        button.textContent = 'copied';
                        announce('the fix is on your clipboard');
                    } catch (refused) {
                        // The clipboard needs a secure context, and a dashboard served
                        // over plain http is not one. Say so rather than pretend.
                        button.textContent = 'select it';
                    }

                    setTimeout(function () { button.textContent = 'copy'; }, 2000);
                });
            });

            search.addEventListener('input', apply);
            sort.addEventListener('change', order);

            document.addEventListener('keydown', function (event) {
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);

                if (event.key === 'Escape') {
                    search.value = '';
                    rule = null;
                    cells.forEach(function (cell) { cell.setAttribute('aria-pressed', 'false'); });
                    filter('all');
                    search.blur();

                    return;
                }

                if (typing) {
                    return;
                }

                if (event.key === '/') {
                    event.preventDefault();
                    search.focus();
                } else if (['0', '1', '2', '3'].includes(event.key)) {
                    const chip = chips.find(function (each) {
                        return (each.dataset.key ?? '0') === event.key;
                    });

                    if (chip !== undefined) {
                        filter(chip.dataset.severity);
                    }
                } else if (event.key === 'j' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    select(cursor + 1);
                } else if (event.key === 'k' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    select(cursor - 1);
                } else if (event.key === 'Enter' && cursor >= 0) {
                    const target = shown()[cursor].dataset.open;

                    if (target !== undefined) {
                        window.location = target;
                    }
                }
            });

            apply();
        })();
    </script>
@endsection
