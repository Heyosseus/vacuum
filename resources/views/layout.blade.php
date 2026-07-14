<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Vacuum</title>

    {{-- Styles are inlined rather than fetched. The dashboard is often the thing
         you open when the database is unwell, sometimes from a machine that
         cannot reach a CDN, and it should not itself need the network to render. --}}
    <style>
        :root {
            color-scheme: light dark;
            --bg: #fbfbfa;
            --panel: #ffffff;
            --line: #e6e4e0;
            --ink: #21201c;
            --muted: #63625e;
            --code: #f4f3f1;
            --critical: #b4372a;
            --warning: #a86a1c;
            --info: #4a6fa5;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #191918;
                --panel: #212120;
                --line: #33322f;
                --ink: #edecea;
                --muted: #9a9892;
                --code: #2a2a28;
                --critical: #e8836f;
                --warning: #d9a441;
                --info: #8aabd8;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .wrap { max-width: 62rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }

        header { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        header h1 { font-size: 1.375rem; margin: 0; letter-spacing: -0.01em; }
        header p { margin: 0; color: var(--muted); font-size: 0.8125rem; }

        h2 { font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin: 2.5rem 0 0.75rem; }

        .finding {
            background: var(--panel);
            border: 1px solid var(--line);
            border-left: 3px solid var(--line);
            border-radius: 6px;
            padding: 1rem 1.125rem;
            margin-bottom: 0.75rem;
        }
        .finding--critical { border-left-color: var(--critical); }
        .finding--warning { border-left-color: var(--warning); }
        .finding--info { border-left-color: var(--info); }

        .finding__head { display: flex; align-items: center; gap: 0.625rem; flex-wrap: wrap; }
        .finding__subject { font-weight: 600; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 0.875rem; }

        .badge {
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            padding: 0.125rem 0.4375rem;
            border-radius: 3px;
            border: 1px solid currentColor;
        }
        .badge--critical { color: var(--critical); }
        .badge--warning { color: var(--warning); }
        .badge--info { color: var(--info); }

        .rule { font-size: 0.75rem; color: var(--muted); font-family: ui-monospace, Menlo, monospace; }

        .finding__summary { margin: 0.625rem 0 0; }
        .finding__impact { margin: 0.375rem 0 0; color: var(--muted); font-size: 0.875rem; }

        pre {
            margin: 0.875rem 0 0;
            padding: 0.625rem 0.75rem;
            background: var(--code);
            border-radius: 4px;
            overflow-x: auto;
            font: 0.8125rem/1.5 ui-monospace, "SF Mono", Menlo, monospace;
        }

        .health {
            margin-top: 2rem;
            background: var(--panel);
            border: 1px solid var(--line);
            border-top: 3px solid var(--info);
            border-radius: 6px;
            padding: 1.25rem 1.375rem;
        }
        .health--a { border-top-color: #3f8f5f; }
        .health--b { border-top-color: var(--info); }
        .health--c, .health--d { border-top-color: var(--warning); }
        .health--f { border-top-color: var(--critical); }

        .health__score { display: flex; align-items: baseline; gap: 0.5rem; }
        .health__score strong { font-size: 2.25rem; font-weight: 650; letter-spacing: -0.02em; }
        .health__score span { color: var(--muted); font-size: 0.875rem; }
        .health__score em { margin-left: auto; font-style: normal; font-weight: 600; color: var(--muted); }

        .health__working { margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; }
        .health__capped { margin: 0.75rem 0 0; padding-top: 0.75rem; border-top: 1px solid var(--line); color: var(--critical); font-size: 0.8125rem; }
        .working { font-size: 0.8125rem; color: var(--muted); }
        .working code { font-family: ui-monospace, Menlo, monospace; }

        .evidence { color: var(--muted); white-space: pre-wrap; }

        nav { display: flex; gap: 1rem; font-size: 0.875rem; }
        nav a { color: var(--muted); text-decoration: none; }
        nav a:hover, nav a[aria-current] { color: var(--ink); }

        .console textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            font: 0.875rem/1.6 ui-monospace, "SF Mono", Menlo, monospace;
            resize: vertical;
        }
        .console__foot { display: flex; align-items: center; gap: 1rem; margin-top: 0.625rem; }
        .console__note { margin: 0; font-size: 0.75rem; color: var(--muted); }
        .console button {
            margin-left: auto;
            padding: 0.4375rem 1.125rem;
            border: 1px solid var(--line);
            border-radius: 5px;
            background: var(--ink);
            color: var(--bg);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
        }

        .error {
            margin-top: 1rem;
            padding: 0.75rem 0.875rem;
            border: 1px solid var(--critical);
            border-radius: 5px;
            color: var(--critical);
            font: 0.8125rem/1.5 ui-monospace, Menlo, monospace;
            white-space: pre-wrap;
        }

        .result__meta { color: var(--muted); font-size: 0.8125rem; }
        .result { overflow-x: auto; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); }
        table { border-collapse: collapse; width: 100%; font-size: 0.8125rem; }
        th, td {
            text-align: left;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--line);
            font-family: ui-monospace, Menlo, monospace;
            white-space: nowrap;
        }
        th { color: var(--muted); font-weight: 600; }
        tbody tr:last-child td { border-bottom: 0; }

        .empty {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 2rem;
            text-align: center;
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Vacuum</h1>

        <nav>
            <a href="{{ route('vacuum.dashboard') }}">Findings</a>

            @if (Route::has('vacuum.console'))
                <a href="{{ route('vacuum.console') }}">Console</a>
            @endif
        </nav>

        <p>PostgreSQL {{ $capabilities->majorVersion() }} &middot; {{ $connection }}</p>
    </header>

    @yield('content')
</div>
</body>
</html>
