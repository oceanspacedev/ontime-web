<x-filament-panels::page>
    @php
        $guides = $this->guides();
    @endphp
    {{-- Surfaces, borders and corners use the Mekaya theme tokens (--mky-*), which already have dark values. Amber = the highlight colour in the videos. --}}
    <style>
        .doc-guide { display: grid; gap: 1.5rem; }
        .doc-guide__nav { display: flex; gap: .5rem; overflow-x: auto; margin: 0 -.25rem; padding: 0 .25rem .25rem; scrollbar-width: thin; }
        .doc-guide__nav-heading { display: none; }
        .doc-guide__nav-item { display: flex; flex: none; align-items: center; gap: .75rem; padding: .625rem .875rem; border: 1px solid var(--mky-border-strong, var(--gray-300)); border-radius: var(--mky-radius-surface, .5rem); background: var(--mky-surface, white); color: var(--gray-800); font-size: .9375rem; font-weight: 500; line-height: 1.35; text-align: left; white-space: nowrap; cursor: pointer; transition: background-color .15s ease, border-color .15s ease; }
        .doc-guide__nav-item:hover { background: var(--mky-surface-subtle, var(--gray-100)); color: var(--gray-950); }
        .doc-guide__nav-item.is-active { border-color: #f59e0b; background: #fffbeb; color: var(--gray-950); font-weight: 600; }
        .doc-guide__nav-number { flex: none; display: inline-grid; place-items: center; min-width: 1.75rem; height: 1.5rem; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); font-size: .75rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .doc-guide__nav-item.is-active .doc-guide__nav-number { background: #f59e0b; color: white; }
        .doc-guide__panel { display: grid; gap: 1.25rem; padding: 1.25rem; border: 1px solid var(--mky-border, var(--gray-200)); border-radius: var(--mky-radius-frame, .75rem); background: var(--mky-surface, white); box-shadow: var(--mky-shadow-surface, none); }
        .doc-guide__eyebrow { margin: 0 0 .25rem; font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #b45309; }
        .doc-guide__title { margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--gray-950); }
        .doc-guide__summary { margin: .25rem 0 0; font-size: .875rem; line-height: 1.6; color: var(--gray-600); max-width: 70ch; }
        .doc-guide__player { display: grid; gap: 1rem; align-items: start; }
        .doc-guide__video { display: block; width: 100%; height: auto; aspect-ratio: 16 / 9; border: 1px solid var(--mky-border, var(--gray-200)); border-radius: var(--mky-radius-surface, .5rem); background: var(--gray-100); }
        .doc-guide__stage { position: relative; }
        .doc-guide__error { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .75rem; padding: 1rem; border-radius: var(--mky-radius-surface, .5rem); background: rgba(17, 24, 39, .88); color: white; text-align: center; font-size: .875rem; }
        .doc-guide__error p { margin: 0; }
        .doc-guide__error-retry { padding: .375rem .875rem; border: 1px solid rgba(255, 255, 255, .6); border-radius: .375rem; background: none; color: white; font-weight: 600; cursor: pointer; }
        .doc-guide__error-retry:hover { background: rgba(255, 255, 255, .12); }
        .doc-guide__steps { display: grid; gap: .25rem; margin: 0; padding: 0; list-style: none; }
        .doc-guide__step { display: flex; gap: .75rem; width: 100%; padding: .625rem .75rem; border: 0; border-left: 3px solid transparent; border-radius: .375rem; background: none; color: var(--gray-700); font-size: .875rem; line-height: 1.45; text-align: left; cursor: pointer; }
        .doc-guide__step:hover { background: var(--mky-surface-muted, var(--gray-50)); color: var(--gray-950); }
        .doc-guide__step.is-active { border-left-color: #f59e0b; background: #fffbeb; color: var(--gray-950); font-weight: 600; }
        .doc-guide__step-number { flex: none; width: 1.25rem; color: var(--gray-400); font-variant-numeric: tabular-nums; }
        .doc-guide__step.is-active .doc-guide__step-number { color: #b45309; }
        .doc-guide__footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding-top: 1rem; border-top: 1px solid var(--mky-border, var(--gray-200)); font-size: .8125rem; color: var(--gray-500); }
        .doc-guide :is(button, a, video):focus-visible { outline: 2px solid var(--gray-950); outline-offset: 2px; }
        @media (min-width: 1024px) {
            .doc-guide { grid-template-columns: 17rem minmax(0, 1fr); align-items: start; }
            .doc-guide__nav { position: sticky; top: 5rem; flex-direction: column; gap: .125rem; margin: 0; padding: .75rem; overflow: visible; border: 1px solid var(--mky-border, var(--gray-200)); border-radius: var(--mky-radius-frame, .75rem); background: var(--mky-surface, white); box-shadow: var(--mky-shadow-surface, none); }
            .doc-guide__nav-heading { display: block; margin: .25rem .5rem .375rem; font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--gray-500); }
            .doc-guide__nav-heading:not(:first-child) { margin-top: .75rem; padding-top: .875rem; border-top: 1px solid var(--mky-border, var(--gray-200)); }
            .doc-guide__nav-item { padding: .5rem .75rem; border-color: transparent; border-left: 3px solid transparent; background: none; font-size: .875rem; white-space: normal; }
            .doc-guide__nav-item.is-active { border-color: transparent; border-left-color: #f59e0b; }
        }
        /* Laptop: video as wide as the panel, steps below it in two columns. Side by side only on wide screens. */
        @media (min-width: 640px) and (max-width: 1535px) {
            .doc-guide__steps { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: .75rem; }
        }
        @media (min-width: 1536px) {
            /* The steps column may not be taller than the 16:9 video beside it; anything more scrolls. */
            .doc-guide__player { grid-template-columns: minmax(0, 1fr) 20rem; container-type: inline-size; }
            .doc-guide__steps { max-height: calc((100cqw - 21rem) * 9 / 16); overflow-y: auto; align-content: start; scrollbar-width: thin; }
        }
        .dark .doc-guide__nav-item { color: var(--gray-100); }
        .dark .doc-guide__nav-item:hover { background: rgba(255, 255, 255, .06); }
        .dark .doc-guide__nav-item.is-active { background: rgba(245, 158, 11, .14); }
        .dark .doc-guide__nav-number { background: var(--gray-800); color: var(--gray-300); }
        .dark .doc-guide__nav-item.is-active .doc-guide__nav-number { background: #f59e0b; color: var(--gray-950); }
        .dark .doc-guide__eyebrow { color: #fbbf24; }
        .dark :is(.doc-guide__nav-item.is-active, .doc-guide__nav-item:hover, .doc-guide__title, .doc-guide__step:hover, .doc-guide__step.is-active) { color: white; }
        .dark :is(.doc-guide__summary, .doc-guide__step) { color: var(--gray-400); }
        .dark .doc-guide__step:hover { background: rgba(255, 255, 255, .04); }
        .dark .doc-guide__step.is-active { background: rgba(245, 158, 11, .12); }
        .dark .doc-guide__step.is-active .doc-guide__step-number { color: #fbbf24; }
        .dark .doc-guide__video { background: var(--gray-800); }
        .dark .doc-guide :is(button, a, video):focus-visible { outline-color: white; }
        @media (min-width: 1024px) { .dark .doc-guide__nav-item.is-active { border-color: transparent; border-left-color: #f59e0b; } }
    </style>

    <div
        class="doc-guide"
        x-data="{
            ids: @js(array_column($guides, 'id')),
            active: @js($guides[0]['id']),
            init() {
                const fromHash = location.hash.slice(1);
                if (this.ids.includes(fromHash)) { this.active = fromHash; }
                this.$watch('active', (id) => history.replaceState(null, '', '#' + id));
            },
        }"
    >
        <nav class="doc-guide__nav" aria-label="Pilih topik">
            @foreach ($guides as $guide)
                @if ($loop->first || $guides[$loop->index - 1]['group'] !== $guide['group'])
                    <span class="doc-guide__nav-heading">{{ $guide['group'] }}</span>
                @endif
                <button
                    type="button"
                    class="doc-guide__nav-item"
                    x-bind:class="{ 'is-active': active === @js($guide['id']) }"
                    x-bind:aria-current="active === @js($guide['id']) ? 'true' : null"
                    x-on:click="active = @js($guide['id'])"
                >
                    <span class="doc-guide__nav-number">{{ $loop->iteration }}</span>{{ $guide['title'] }}
                </button>
            @endforeach
        </nav>

        <div>
            @foreach ($guides as $guide)
                {{-- The video plays when the user presses play or clicks a step; the step on screen is highlighted and a click jumps to its second. --}}
                <section
                    id="panduan-{{ $guide['id'] }}"
                    class="doc-guide__panel"
                    aria-labelledby="panduan-{{ $guide['id'] }}-judul"
                    x-cloak
                    x-show="active === @js($guide['id'])"
                    x-data="{
                        step: 0,
                        failed: false,
                        times: @js(array_column($guide['steps'], 'at')),
                        retry() {
                            this.failed = false;
                            this.$refs.video.load();
                        },
                        sync() {
                            const video = this.$refs.video;
                            if (this.active !== @js($guide['id'])) { video.pause(); return; }
                            video.currentTime = 0;
                            this.step = 0;
                        },
                        seek(index) {
                            const video = this.$refs.video;
                            this.step = index;
                            this.failed = false;
                            const jump = () => { video.currentTime = this.times[index]; video.play().catch(() => {}); };
                            {{-- preload="none": jumping to a step's second only works once the metadata has loaded. --}}
                            if (video.readyState >= 1) { jump(); } else { video.addEventListener('loadedmetadata', jump, { once: true }); video.load(); }
                            {{-- A step lower down may have scrolled the video out of view: bring it back so the jump is visible. --}}
                            const box = video.getBoundingClientRect();
                            if (box.top < 72 || box.bottom > window.innerHeight) {
                                video.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                            }
                        },
                        init() {
                            this.$nextTick(() => this.sync());
                            this.$watch('active', () => this.sync());
                        },
                    }"
                >
                    <header>
                        <p class="doc-guide__eyebrow">{{ $guide['group'] }}</p>
                        <h2 class="doc-guide__title" id="panduan-{{ $guide['id'] }}-judul">{{ $guide['title'] }}</h2>
                        <p class="doc-guide__summary">{{ $guide['summary'] }}</p>
                    </header>

                    <div class="doc-guide__player">
                        <div class="doc-guide__stage">
                            <video
                                x-ref="video"
                                class="doc-guide__video"
                                src="{{ $guide['video'] }}"
                                poster="{{ $guide['poster'] }}"
                                width="1920"
                                height="1080"
                                controls
                                playsinline
                                preload="none"
                                aria-label="Video panduan: {{ $guide['title'] }}"
                                x-on:timeupdate="step = Math.max(0, times.findLastIndex((at) => $el.currentTime + 0.05 >= at))"
                                x-on:error="failed = true"
                                x-on:loadeddata="failed = false"
                            ></video>
                            <div class="doc-guide__error" role="alert" x-cloak x-show="failed">
                                <p>Video tidak bisa dimuat. Periksa koneksi Anda lalu coba lagi.</p>
                                <button type="button" class="doc-guide__error-retry" x-on:click="retry()">Coba lagi</button>
                            </div>
                        </div>

                        <ol class="doc-guide__steps" aria-label="Langkah">
                            @foreach ($guide['steps'] as $index => $step)
                                <li>
                                    <button
                                        type="button"
                                        class="doc-guide__step"
                                        x-bind:class="{ 'is-active': step === {{ $index }} }"
                                        x-on:click="seek({{ $index }})"
                                    >
                                        <span class="doc-guide__step-number">{{ $index + 1 }}</span>
                                        <span>{{ $step['text'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <footer class="doc-guide__footer">
                        <span>Untuk: {{ $guide['audience'] }}</span>
                        @if ($guide['action'])
                            <x-filament::button tag="a" :href="$guide['action']['url']" icon="heroicon-m-arrow-right" icon-position="after" size="sm">
                                {{ $guide['action']['label'] }}
                            </x-filament::button>
                        @endif
                    </footer>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
