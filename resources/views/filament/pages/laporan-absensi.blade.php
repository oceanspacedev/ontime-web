<x-filament-panels::page>
    <form wire:submit.prevent="mount" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                Hasil Rekap Absensi
            </h3>
            <button type="button" wire:click="exportExcel" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-xs">
                <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                <span>Export ke Excel</span>
            </button>
        </div>
    </form>

    @if ($this->summary !== [])
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
            @foreach ($this->summary as $figure)
                <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $figure['label'] }}</p>
                    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $figure['value'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
