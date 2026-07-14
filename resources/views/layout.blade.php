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
        <p>PostgreSQL {{ $capabilities->majorVersion() }} &middot; {{ $connection }}</p>
    </header>

    @yield('content')
</div>
</body>
</html>
