<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Vacuum</title>

    {{-- Inlined, and every typeface is already on the machine. This is the page you
         open when the database is unwell, sometimes from a laptop that cannot reach
         the internet, and it must not need the network to say what is wrong.

         It is built as a text-mode tool, not a web page: boxed panels with title
         bars, inverse-video selection, and the keys it answers to printed along the
         bottom where a tool has always printed them. Monospace carries the
         structure. The interface face appears only where a person wrote a sentence,
         because long prose in a monospace column is a wall, and the point of this
         page is to be read quickly by somebody in a hurry. --}}
    <style>
        :root {
            color-scheme: dark light;

            /* Warm phosphor on a warm black. A cold blue-black terminal is the
               costume everybody wears; a CRT was never neutral grey. */
            --bg: #14120f;
            --panel: #1b1815;
            --raised: #23201b;
            --line: #322d26;
            --ink: #ece4d4;
            --muted: #9c9284;
            --faint: #6b6357;

            --amber: #e0a02e;
            --green: #7fb069;
            --red: #e2604f;
            --cyan: #6ea8c4;

            --critical: var(--red);
            --warning: var(--amber);
            --info: var(--cyan);
            --good: var(--green);
            --empty: #2b2721;

            --ui: system-ui, -apple-system, "Segoe UI Variable Text", "Segoe UI", sans-serif;
            --mono: ui-monospace, "Cascadia Mono", "SF Mono", Menlo, Consolas, monospace;
        }

        @media (prefers-color-scheme: light) {
            :root {
                --bg: #e8e3d7;
                --panel: #f2eee4;
                --raised: #e2ddd0;
                --line: #c9c2b2;
                --ink: #23201a;
                --muted: #5f594d;
                --faint: #8b8477;

                --amber: #9a6a06;
                --green: #3d7a2f;
                --red: #b03a2a;
                --cyan: #2c6f8e;
                --empty: #d6d0c1;
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            padding-bottom: 2.25rem;
            background: var(--bg);
            color: var(--ink);
            font: 400 13.5px/1.6 var(--ui);
            font-variant-numeric: tabular-nums;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 64rem; margin: 0 auto; padding: 1rem 1rem 2rem; }

        :focus-visible { outline: 2px solid var(--amber); outline-offset: 1px; }

        .hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        /* ---- panels: a box with a title welded into its top edge ------------ */

        .panel { border: 1px solid var(--line); background: var(--panel); margin-bottom: 0.75rem; }

        .panel__bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.625rem;
            background: var(--raised);
            border-bottom: 1px solid var(--line);
            font: 400 0.6875rem/1.4 var(--mono);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--faint);
        }

        .panel__bar b { color: var(--ink); font-weight: 400; }
        .panel__bar .right { margin-left: auto; text-transform: none; letter-spacing: 0; }
        .panel__body { padding: 0.875rem 0.9375rem; }

        /* ---- masthead -------------------------------------------------------- */

        .masthead {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            flex-wrap: wrap;
            padding: 0.4375rem 0.625rem;
            background: var(--raised);
            border: 1px solid var(--line);
            font: 400 0.75rem/1.4 var(--mono);
            margin-bottom: 0.75rem;
        }

        .masthead__name { margin: 0; font: 600 0.75rem/1.4 var(--mono); letter-spacing: 0.22em; color: var(--amber); }
        .masthead__server { color: var(--faint); }
        .masthead__server b { color: var(--muted); font-weight: 400; }
        .masthead nav { margin-left: auto; display: flex; gap: 0.375rem; }

        .masthead nav a {
            padding: 0.125rem 0.4375rem;
            color: var(--muted);
            text-decoration: none;
        }

        .masthead nav a:hover { color: var(--ink); }

        /* Inverse video for the current tab: this is how a text-mode tool has always
           said "you are here", and it needs no colour to do it. */
        .masthead nav a[aria-current="page"] { color: var(--bg); background: var(--ink); }

        /* ---- score ----------------------------------------------------------- */

        .score { display: grid; grid-template-columns: auto 1fr; gap: 1.5rem; align-items: center; }

        .score__figure { text-align: center; }

        .score__number { font: 400 3.25rem/1 var(--mono); letter-spacing: -0.02em; }

        .score--a .score__number { color: var(--good); }
        .score--b .score__number { color: var(--cyan); }
        .score--c .score__number, .score--d .score__number { color: var(--amber); }
        .score--f .score__number { color: var(--red); }

        .score__grade {
            margin: 0.375rem 0 0;
            font: 400 0.6875rem/1 var(--mono);
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--faint);
        }

        /* A hundred cells, because a table is pages, and every rule below is arguing
           about pages being wasted. Each rule eats exactly what it cost. */
        .map { display: grid; grid-template-columns: repeat(25, 1fr); gap: 2px; margin: 0 0 0.625rem; }

        .cell { aspect-ratio: 1; padding: 0; border: 0; background: var(--empty); }
        .cell--critical { background: var(--critical); }
        .cell--warning { background: var(--warning); }
        .cell--info { background: var(--info); }
        .cell--spent { cursor: pointer; }
        .cell--spent:hover, .cell[aria-pressed="true"] { outline: 1px solid var(--ink); outline-offset: 1px; }

        .legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; margin: 0; font: 400 0.75rem/1.5 var(--mono); color: var(--muted); }
        .legend__cost { color: var(--ink); }
        .legend__free { color: var(--faint); }

        .capped {
            grid-column: 1 / -1;
            margin: 0.875rem 0 0;
            padding: 0.5rem 0.625rem;
            background: var(--raised);
            border-left: 3px solid var(--red);
            font-size: 0.8125rem;
        }

        /* ---- toolbar ---------------------------------------------------------- */

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding: 0.4375rem 0.625rem;
            background: var(--raised);
            border: 1px solid var(--line);
            border-bottom: 0;
            font: 400 0.75rem/1.4 var(--mono);
        }

        .filters { display: flex; gap: 0.25rem; }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.125rem 0.4375rem;
            font: inherit;
            color: var(--muted);
            background: transparent;
            border: 0;
            cursor: pointer;
        }

        .chip:hover { color: var(--ink); }
        .chip[aria-pressed="true"] { color: var(--bg); background: var(--ink); }
        .chip[aria-pressed="true"] .chip__count { color: var(--bg); }

        .chip__key { color: var(--amber); }
        .chip[aria-pressed="true"] .chip__key { color: var(--bg); }
        .chip__mark { width: 7px; height: 7px; }
        .chip__mark--critical { background: var(--critical); }
        .chip__mark--warning { background: var(--warning); }
        .chip__mark--info { background: var(--info); }
        .chip__count { color: var(--faint); }

        .search {
            flex: 1;
            min-width: 9rem;
            padding: 0.125rem 0.375rem;
            font: inherit;
            color: var(--ink);
            background: var(--bg);
            border: 1px solid var(--line);
        }

        .search:focus { outline: none; border-color: var(--amber); }
        .search::placeholder { color: var(--faint); }

        .sort { padding: 0.125rem; font: inherit; color: var(--muted); background: transparent; border: 0; cursor: pointer; }
        .tally { margin: 0; color: var(--faint); }

        /* ---- findings ---------------------------------------------------------- */

        .findings { border: 1px solid var(--line); background: var(--panel); }

        .finding {
            display: grid;
            grid-template-columns: 6.5rem 1fr;
            gap: 0 1rem;
            padding: 0.75rem 0.9375rem;
            border-bottom: 1px solid var(--line);
            cursor: pointer;
        }

        .finding:last-child { border-bottom: 0; }
        .finding:hover { background: var(--raised); }

        /* The selected row is inverse-video down its left edge, the way a text-mode
           list has always shown the cursor. */
        .finding[data-on] { background: var(--raised); box-shadow: inset 3px 0 0 var(--amber); }

        .gutter { font: 400 0.6875rem/1.6 var(--mono); }
        .gutter__severity { display: block; letter-spacing: 0.1em; text-transform: uppercase; }
        .finding--critical .gutter__severity { color: var(--critical); }
        .finding--warning .gutter__severity { color: var(--warning); }
        .finding--info .gutter__severity { color: var(--info); }
        .gutter__rule { display: block; color: var(--faint); word-break: break-word; }

        .body { min-width: 0; }
        .subject { margin: 0; font: 500 0.875rem/1.4 var(--mono); word-break: break-word; }
        .summary { margin: 0.25rem 0 0; font-size: 0.9375rem; }

        /* The impact is the paragraph that teaches you the internals, and it is why
           this package exists. It is also four lines long, and a reader scanning ten
           findings does not want forty. So it waits until it is asked for. */
        .why {
            margin-top: 0.5rem;
            padding: 0;
            font: 400 0.75rem/1 var(--mono);
            color: var(--faint);
            background: transparent;
            border: 0;
            cursor: pointer;
        }

        .why:hover { color: var(--amber); }
        .why::before { content: "[+] "; }
        .why[aria-expanded="true"]::before { content: "[-] "; }

        .impact {
            margin: 0.5rem 0 0;
            padding-left: 0.75rem;
            border-left: 1px solid var(--line);
            color: var(--muted);
            max-width: 66ch;
        }

        pre {
            margin: 0;
            padding: 0.4375rem 0.625rem;
            background: var(--bg);
            border: 1px solid var(--line);
            overflow-x: auto;
            font: 400 0.75rem/1.6 var(--mono);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .evidence { margin-top: 0.5rem; color: var(--muted); }

        .actions { display: flex; align-items: stretch; gap: 0.625rem; margin-top: 0.625rem; flex-wrap: wrap; }

        /* Inspect opens a SELECT and is safe. Copy hands over a statement that
           writes, for somewhere Vacuum cannot reach. They must not look alike. */
        .inspect {
            display: inline-flex;
            align-items: center;
            padding: 0.3125rem 0.625rem;
            font: 400 0.75rem/1.4 var(--mono);
            color: var(--bg);
            background: var(--amber);
            border: 1px solid var(--amber);
            text-decoration: none;
            white-space: nowrap;
        }

        .inspect:hover { filter: brightness(1.12); }

        .fix { display: flex; flex: 1; min-width: 14rem; align-items: stretch; }
        .fix pre { flex: 1; border-right: 0; }

        .copy {
            padding: 0 0.625rem;
            font: 400 0.75rem/1.4 var(--mono);
            color: var(--muted);
            background: var(--raised);
            border: 1px solid var(--line);
            cursor: pointer;
            white-space: nowrap;
        }

        .copy:hover { color: var(--ink); }

        /* ---- facts: the drill-down grid ------------------------------------------
           A definition list, because that is what it is: a term and the number that
           answers it. Laid out in columns so the eye can run down them. */

        .facts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
            gap: 0.875rem 1.5rem;
            margin: 0.875rem 0 0;
        }

        .facts dt {
            font: 400 0.625rem/1.4 var(--mono);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--faint);
        }

        .facts dd { margin: 0.125rem 0 0; font: 400 1.0625rem/1.3 var(--mono); }
        .facts .note { font-size: 0.75rem; }

        .aside {
            margin: 1rem 0 0;
            padding-left: 0.75rem;
            border-left: 2px solid var(--line);
            font-size: 0.875rem;
            color: var(--muted);
            max-width: 74ch;
        }

        .aside b { color: var(--ink); font-weight: 600; }
        .aside code { font: 400 0.8125rem/1 var(--mono); color: var(--amber); }

        .bad { color: var(--red); }

        .open {
            color: var(--muted);
            text-decoration: none;
            border-bottom: 1px dotted var(--faint);
        }

        .open:hover { color: var(--amber); border-bottom-color: var(--amber); }

        /* ---- tables ------------------------------------------------------------- */

        .scroll { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font: 400 0.75rem/1.5 var(--mono); }
        th, td { text-align: left; padding: 0.4375rem 0.625rem; border-bottom: 1px solid var(--line); white-space: nowrap; }

        th {
            font-weight: 400;
            font-size: 0.625rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--faint);
            background: var(--raised);
        }

        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: var(--raised); }
        .note { color: var(--faint); }

        /* ---- page bar: the one visual this whole pillar exists for -------------
           A page is 8 kB. The pointer array grows down from `lower`, tuples grow up
           from `upper`, and the gap between them is free -- fillfactor's entire
           argument in one proportional strip. */
        .pagebar { display: flex; height: 0.875rem; overflow: hidden; border: 1px solid var(--line); }
        .pagebar__seg { display: block; height: 100%; }
        .pagebar__seg--pointers { background: var(--cyan); }
        .pagebar__seg--free { background: var(--empty); }
        .pagebar__seg--tuples { background: var(--amber); }

        /* ---- line pointers: colour carries the meaning ---------------------------
           A dead tuple is red because it is the thing this page exists to make
           visible. A redirect is a HOT chain's root; a heap-only tuple is reachable
           only through that chain, so it reads the same amber, dimmer. */
        .lp--dead { color: var(--red); }
        .lp--redirect { color: var(--amber); }
        .lp--heaponly { color: var(--amber); opacity: 0.72; }
        .lp__xmax--superseded { color: var(--red); }

        /* ---- HOT chains: drawn as a chain, not summarised ------------------------ */
        .chain { margin: 0 0 0.375rem; font: 400 0.8125rem/1.6 var(--mono); }
        .chain__lp { color: var(--amber); }
        .chain__arrow { color: var(--muted); }

        /* ---- console -------------------------------------------------------------- */

        .console textarea {
            display: block;
            width: 100%;
            padding: 0.75rem;
            font: 400 0.8125rem/1.7 var(--mono);
            color: var(--ink);
            background: var(--bg);
            border: 1px solid var(--line);
            resize: vertical;
            tab-size: 2;
        }

        .console textarea:focus { outline: none; border-color: var(--amber); }

        .console__foot { display: flex; align-items: center; gap: 0.875rem; margin-top: 0.625rem; flex-wrap: wrap; }
        .promise { margin: 0; flex: 1; min-width: 16rem; font-size: 0.8125rem; color: var(--muted); }
        .promise b { color: var(--ink); font-weight: 500; }

        .run {
            padding: 0.375rem 1.125rem;
            font: 400 0.75rem/1.4 var(--mono);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--bg);
            background: var(--amber);
            border: 1px solid var(--amber);
            cursor: pointer;
        }

        .run:hover { filter: brightness(1.12); }

        .error {
            margin: 0.75rem 0 0;
            padding: 0.5rem 0.625rem;
            background: var(--panel);
            border: 1px solid var(--red);
            border-left-width: 3px;
            color: var(--red);
            font: 400 0.75rem/1.6 var(--mono);
            white-space: pre-wrap;
        }

        .empty { padding: 2rem 1rem; text-align: center; }
        .empty p { margin: 0; }
        .empty p + p { margin-top: 0.25rem; color: var(--muted); font-size: 0.8125rem; }

        /* ---- status bar: the keys, where a tool has always printed them ---------- */

        .status {
            position: fixed;
            inset: auto 0 0 0;
            z-index: 5;
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding: 0.4375rem 1rem;
            background: var(--raised);
            border-top: 1px solid var(--line);
            font: 400 0.6875rem/1.4 var(--mono);
            color: var(--muted);
            white-space: nowrap;
        }

        .status b { color: var(--bg); background: var(--amber); padding: 0 0.25rem; font-weight: 400; }
        .status .said { margin-left: auto; color: var(--green); }

        /* The block navigator on the internals page prints its own keys and a jump
           box into this same bar, rather than inventing a second footer. */
        .status a { color: var(--muted); text-decoration: none; }
        .status a:hover { color: var(--ink); }
        .status form { display: flex; align-items: center; gap: 0.375rem; margin-left: auto; }
        .status input[type="number"] {
            width: 4.5rem;
            padding: 0.125rem 0.25rem;
            font: inherit;
            color: var(--ink);
            background: var(--bg);
            border: 1px solid var(--line);
        }
        .status button {
            padding: 0.125rem 0.4375rem;
            font: inherit;
            color: var(--muted);
            background: transparent;
            border: 1px solid var(--line);
            cursor: pointer;
        }
        .status button:hover { color: var(--ink); }

        [hidden] { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition: none !important; animation: none !important; }
        }

        @media (max-width: 40rem) {
            .score { grid-template-columns: 1fr; }
            .finding { grid-template-columns: 1fr; gap: 0.375rem; }
            .gutter { display: flex; gap: 0.625rem; }
            .status { display: none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="masthead">
        <h1 class="masthead__name">VACUUM</h1>

        <span class="masthead__server">
            PostgreSQL <b>{{ $capabilities->majorVersion() }}</b> · <b>{{ $connection }}</b>
        </span>

        <nav>
            <a href="{{ route('vacuum.dashboard') }}"
               @if (request()->routeIs('vacuum.dashboard')) aria-current="page" @endif>findings</a>

            @if (Route::has('vacuum.history'))
                <a href="{{ route('vacuum.history') }}"
                   @if (request()->routeIs('vacuum.history')) aria-current="page" @endif>history</a>
            @endif

            @if (Route::has('vacuum.internals'))
                <a href="{{ route('vacuum.internals') }}"
                   @if (request()->routeIs('vacuum.internals')) aria-current="page" @endif>internals</a>
            @endif

            @if (Route::has('vacuum.console'))
                <a href="{{ route('vacuum.console') }}"
                   @if (request()->routeIs('vacuum.console*')) aria-current="page" @endif>console</a>
            @endif
        </nav>
    </header>

    @yield('content')
</div>

@yield('status')
</body>
</html>
