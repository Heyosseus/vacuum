<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Findings</x-slot>
        <x-slot name="description">What Vacuum believes is wrong, worst first.</x-slot>

        {{--
            Styled with a self-contained stylesheet rather than utility classes: this view
            ships inside a package, and a host application's compiled Tailwind theme does
            not scan it, so any utility class used here would simply not exist in the CSS
            the browser loads. These rules always do. Dark mode follows Filament's own
            `.dark` class on the document.
        --}}
        <style>
            .vac-findings [x-cloak] { display:none !important; }
            .vac-findings { --vac-crit:#e11d48; --vac-warn:#f59e0b; --vac-info:#94a3b8; }
            .vac-triage { display:flex; flex-wrap:wrap; align-items:center; gap:.375rem 1.25rem; margin-bottom:1.25rem; }
            .vac-triage-total { font-size:.75rem; font-weight:500; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; }
            .vac-triage-band { display:inline-flex; align-items:center; gap:.375rem; font-size:.75rem; font-weight:500; color:#4b5563; }
            .dark .vac-triage-band { color:#d1d5db; }
            .vac-dot { width:.5rem; height:.5rem; border-radius:9999px; flex:none; }

            .vac-list { display:flex; flex-direction:column; gap:.75rem; margin:0; padding:0; list-style:none; }
            .vac-case { position:relative; overflow:hidden; border-radius:.75rem; background:#fff; box-shadow:inset 0 0 0 1px rgba(0,0,0,.06); padding:1rem 1rem 1rem 1.5rem; transition:box-shadow .15s ease; }
            .dark .vac-case { background:rgba(255,255,255,.03); box-shadow:inset 0 0 0 1px rgba(255,255,255,.1); }
            .vac-case:hover { box-shadow:inset 0 0 0 1px rgba(0,0,0,.12); }
            .dark .vac-case:hover { box-shadow:inset 0 0 0 1px rgba(255,255,255,.2); }
            .vac-rail { position:absolute; top:0; bottom:0; left:0; width:4px; }

            .vac-head { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; }
            .vac-subject { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-weight:600; font-size:.875rem; line-height:1.3; color:#030712; word-break:break-all; }
            .dark .vac-subject { color:#fff; }
            .vac-rule { margin:.25rem 0 0; font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; }
            .dark .vac-rule { color:#6b7280; }
            .vac-summary { margin:.75rem 0 0; font-size:.875rem; line-height:1.5; color:#374151; }
            .dark .vac-summary { color:#e5e7eb; }
            .vac-impact { margin:.25rem 0 0; font-size:.75rem; line-height:1.5; color:#6b7280; }
            .dark .vac-impact { color:#9ca3af; }

            .vac-fix { margin-top:.75rem; overflow:hidden; border-radius:.5rem; box-shadow:inset 0 0 0 1px rgba(0,0,0,.06); }
            .dark .vac-fix { box-shadow:inset 0 0 0 1px rgba(255,255,255,.1); }
            .vac-fix-bar { display:flex; align-items:center; justify-content:space-between; gap:.5rem; padding:.375rem .5rem .375rem .75rem; background:#f9fafb; border-bottom:1px solid rgba(0,0,0,.06); }
            .dark .vac-fix-bar { background:rgba(255,255,255,.05); border-bottom-color:rgba(255,255,255,.1); }
            .vac-fix-label { font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
            .dark .vac-fix-label { color:#9ca3af; }
            .vac-copy { display:inline-flex; align-items:center; gap:.25rem; border:0; background:transparent; cursor:pointer; border-radius:.375rem; padding:.125rem .375rem; font-size:.6875rem; font-weight:500; color:#6b7280; transition:background .15s ease,color .15s ease; }
            .vac-copy:hover { background:rgba(0,0,0,.05); color:#374151; }
            .dark .vac-copy { color:#9ca3af; }
            .dark .vac-copy:hover { background:rgba(255,255,255,.1); color:#e5e7eb; }
            .vac-copy:focus-visible { outline:2px solid #6366f1; outline-offset:1px; }
            .vac-copy svg { width:.875rem; height:.875rem; }
            .vac-copy-ok { color:#059669; }
            .dark .vac-copy-ok { color:#34d399; }
            .vac-code { margin:0; overflow-x:auto; padding:.625rem .75rem; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.75rem; line-height:1.6; color:#1f2937; }
            .dark .vac-code { color:#e5e7eb; }

            .vac-empty { display:flex; flex-direction:column; align-items:center; gap:.75rem; padding:3rem 1.5rem; text-align:center; }
            .vac-empty-icon { display:flex; align-items:center; justify-content:center; width:3rem; height:3rem; border-radius:9999px; background:#ecfdf5; color:#059669; box-shadow:inset 0 0 0 1px rgba(5,150,105,.2); }
            .dark .vac-empty-icon { background:rgba(16,185,129,.1); color:#34d399; }
            .vac-empty-icon svg { width:1.5rem; height:1.5rem; }
            .vac-empty-title { margin:0; font-size:.875rem; font-weight:600; color:#030712; }
            .dark .vac-empty-title { color:#fff; }
            .vac-empty-text { margin:0; max-width:20rem; font-size:.875rem; line-height:1.5; color:#6b7280; }
            .dark .vac-empty-text { color:#9ca3af; }

            @media (prefers-reduced-motion: reduce) { .vac-case, .vac-copy { transition:none; } }
        </style>

        @php($findings = $this->findings())

        <div class="vac-findings">
            @if (count($findings) === 0)
                <div class="vac-empty">
                    <div class="vac-empty-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                    <p class="vac-empty-title">All clear</p>
                    <p class="vac-empty-text">Nothing the advisor can see needs your attention. Vacuum keeps looking.</p>
                </div>
            @else
                {{-- Triage line: the shape of the list before you read it, colour-keyed to the rails below. --}}
                <div class="vac-triage">
                    <span class="vac-triage-total">
                        {{ count($findings) }} {{ \Illuminate\Support\Str::plural('finding', count($findings)) }}
                    </span>

                    @foreach ($this->triage() as $band)
                        <span class="vac-triage-band">
                            <span class="vac-dot" style="background: {{ $band['rail'] }}"></span>
                            {{ $band['count'] }} {{ $band['label'] }}
                        </span>
                    @endforeach
                </div>

                {{-- The cases themselves. An ordered list because the order is the triage order. --}}
                <ol class="vac-list">
                    @foreach ($findings as $finding)
                        <li class="vac-case">
                            <span class="vac-rail" style="background: {{ $this->rail($finding) }}" aria-hidden="true"></span>

                            <div class="vac-head">
                                <div style="min-width:0">
                                    <div class="vac-subject">{{ $finding->subject }}</div>
                                    <p class="vac-rule">{{ $finding->rule }}</p>
                                </div>

                                <x-filament::badge :color="$this->color($finding)" class="shrink-0">
                                    {{ $this->label($finding->severity) }}
                                </x-filament::badge>
                            </div>

                            <p class="vac-summary">{{ $finding->summary }}</p>
                            <p class="vac-impact">{{ $finding->impact }}</p>

                            @if ($finding->remediation)
                                {{-- The prescription: the fix, one action away, carrying the promise that it is only ever shown. --}}
                                <div class="vac-fix" x-data="{ copied: false, timer: null }">
                                    <div class="vac-fix-bar">
                                        <span class="vac-fix-label">The fix — shown, never run</span>

                                        <button
                                            type="button"
                                            class="vac-copy"
                                            x-on:click="navigator.clipboard.writeText($refs.sql.textContent.trim()); copied = true; clearTimeout(timer); timer = setTimeout(() => copied = false, 2000)"
                                            :aria-label="copied ? 'Copied to clipboard' : 'Copy the fix to the clipboard'"
                                        >
                                            <svg x-show="! copied" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m11.25 3.5h-3.375c-.621 0-1.125-.504-1.125-1.125V4.125C15.75 3.504 15.246 3 14.625 3H12" />
                                            </svg>
                                            <svg x-show="copied" x-cloak class="vac-copy-ok" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                        </button>
                                    </div>

                                    <pre x-ref="sql" class="vac-code"><code>{{ $finding->remediation }}</code></pre>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
