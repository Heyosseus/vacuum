<x-filament-widgets::widget>
    <div wire:poll.10s>
        <x-filament::section>
            <x-slot name="heading">Running vacuums</x-slot>
            <x-slot name="description">The database reclaiming space right now. Nothing here is a problem — it is the work the rest of the page is asking for.</x-slot>

            {{--
                Self-contained styles, for the same reason as the findings widget: a host
                application's compiled theme does not scan this package's views, so utility
                classes used here would not exist in the CSS the browser loads. Dark mode
                follows Filament's own `.dark` class on the document.
            --}}
            <style>
                .vac-vac-empty { margin:0; padding:1.5rem 0; font-size:.875rem; color:#6b7280; }
                .dark .vac-vac-empty { color:#9ca3af; }
                .vac-vac-list { display:flex; flex-direction:column; gap:1.25rem; }
                .vac-vac-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.5rem; }
                .vac-vac-name { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.875rem; font-weight:600; color:#030712; word-break:break-all; }
                .dark .vac-vac-name { color:#fff; }
                .vac-vac-meta { font-size:.75rem; color:#6b7280; }
                .dark .vac-vac-meta { color:#9ca3af; }
                .vac-vac-track { margin-top:.5rem; height:.5rem; width:100%; overflow:hidden; border-radius:9999px; background:rgba(0,0,0,.08); }
                .dark .vac-vac-track { background:rgba(255,255,255,.1); }
                .vac-vac-fill { height:100%; border-radius:9999px; background:#6366f1; transition:width .3s ease; }
                .vac-vac-pct { margin:.375rem 0 0; font-size:.75rem; color:#6b7280; }
                .dark .vac-vac-pct { color:#9ca3af; }
                .vac-vac-note { margin:.5rem 0 0; font-size:.75rem; color:#6b7280; }
                .dark .vac-vac-note { color:#9ca3af; }
                @media (prefers-reduced-motion: reduce) { .vac-vac-fill { transition:none; } }
            </style>

            @php($vacuums = $this->vacuums())

            @if (count($vacuums) === 0)
                <p class="vac-vac-empty">No vacuums running.</p>
            @else
                <div class="vac-vac-list">
                    @foreach ($vacuums as $vacuum)
                        @php($percent = $vacuum->percentScanned())

                        <div>
                            <div class="vac-vac-head">
                                <span class="vac-vac-name">{{ $vacuum->qualifiedName() }}</span>
                                <span class="vac-vac-meta">
                                    {{ $vacuum->phase }} · {{ $vacuum->automatic ? 'autovacuum' : 'manual' }}
                                    @if ($vacuum->indexPasses > 1)
                                        · {{ $vacuum->indexPasses }} index passes
                                    @endif
                                </span>
                            </div>

                            @if ($percent !== null)
                                <div class="vac-vac-track">
                                    <div class="vac-vac-fill" style="width: {{ $percent }}%"></div>
                                </div>
                                <p class="vac-vac-pct">{{ $percent }}% of the heap scanned</p>
                            @else
                                <p class="vac-vac-note">In a phase PostgreSQL does not count blocks for, so there is no honest percentage to show.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
