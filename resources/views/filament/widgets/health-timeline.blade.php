<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Health over time</x-slot>
        <x-slot name="description">Where the database has been, and where it is heading.</x-slot>

        {{--
            Self-contained styles, for the same reason the findings widget carries its own:
            a host application's compiled Tailwind never scans a package view, so a utility
            class used here would not exist in the CSS the browser loads. Dark mode follows
            Filament's `.dark` class.
        --}}
        <style>
            .vac-hist { --vac-crit:#e11d48; --vac-warn:#f59e0b; --vac-ok:#059669; }
            .vac-hist-chart { width:100%; height:auto; display:block; }
            .vac-hist-line { fill:none; stroke:#6366f1; stroke-width:1.25; stroke-linejoin:round; stroke-linecap:round; }
            .vac-hist-score { display:flex; align-items:baseline; gap:.5rem; margin-bottom:1rem; }
            .vac-hist-score b { font-size:1.75rem; font-weight:700; color:#030712; }
            .dark .vac-hist-score b { color:#fff; }
            .vac-hist-score span { font-size:.75rem; font-weight:500; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; }

            .vac-hist-group { margin-top:1.5rem; }
            .vac-hist-group h3 { margin:0 0 .5rem; font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
            .dark .vac-hist-group h3 { color:#9ca3af; }
            .vac-hist-rows { display:flex; flex-direction:column; gap:.375rem; margin:0; padding:0; list-style:none; }
            .vac-hist-row { display:flex; align-items:baseline; gap:.5rem; font-size:.8125rem; line-height:1.4; padding:.5rem .75rem; border-radius:.5rem; box-shadow:inset 0 0 0 1px rgba(0,0,0,.06); }
            .dark .vac-hist-row { box-shadow:inset 0 0 0 1px rgba(255,255,255,.1); }
            .vac-hist-subject { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-weight:600; color:#030712; word-break:break-all; }
            .dark .vac-hist-subject { color:#fff; }
            .vac-hist-note { color:#6b7280; }
            .dark .vac-hist-note { color:#9ca3af; }
            .vac-hist-forecast { color:#b45309; }
            .dark .vac-hist-forecast { color:#fbbf24; }

            .vac-hist-empty { padding:2.5rem 1.5rem; text-align:center; font-size:.875rem; line-height:1.6; color:#6b7280; }
            .dark .vac-hist-empty { color:#9ca3af; }
        </style>

        @php($panel = $this->panel())

        <div class="vac-hist">
            @if (! $panel->hasHistory())
                <div class="vac-hist-empty">
                    No snapshots yet. Once <code>vacuum:snapshot</code> has run, the database's
                    health, its new and cleared findings, and its forecasts appear here.
                </div>
            @else
                @php($score = $panel->latestScore())
                @if ($score !== null)
                    <div class="vac-hist-score">
                        <b>{{ $score }}</b><span>/ 100 latest</span>
                    </div>
                @endif

                @php($points = $panel->healthSparkline())
                @if ($points !== '')
                    <svg class="vac-hist-chart" viewBox="0 0 100 30" preserveAspectRatio="none" role="img" aria-label="Health score over time">
                        <polyline class="vac-hist-line" points="{{ $points }}" />
                    </svg>
                @endif

                @if (count($panel->forecasts()) > 0)
                    <div class="vac-hist-group">
                        <h3>Forecast to cross critical</h3>
                        <ul class="vac-hist-rows">
                            @foreach ($panel->forecasts() as $view)
                                <li class="vac-hist-row">
                                    <span class="vac-hist-subject">{{ $view->finding->subject }}</span>
                                    <span class="vac-hist-forecast">
                                        @if ($view->forecast->days <= 0)
                                            imminently
                                        @else
                                            in about {{ $view->forecast->days }} days
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($panel->newFindings()) > 0)
                    <div class="vac-hist-group">
                        <h3>New since the previous snapshot</h3>
                        <ul class="vac-hist-rows">
                            @foreach ($panel->newFindings() as $finding)
                                <li class="vac-hist-row">
                                    <span class="vac-hist-subject">{{ $finding->subject }}</span>
                                    <span class="vac-hist-note">{{ $finding->rule }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($panel->clearedFindings()) > 0)
                    <div class="vac-hist-group">
                        <h3>Cleared since the previous snapshot</h3>
                        <ul class="vac-hist-rows">
                            @foreach ($panel->clearedFindings() as $finding)
                                <li class="vac-hist-row">
                                    <span class="vac-hist-subject">{{ $finding->subject }}</span>
                                    <span class="vac-hist-note">{{ $finding->rule }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
