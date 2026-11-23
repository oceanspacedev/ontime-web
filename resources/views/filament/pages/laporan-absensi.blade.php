<x-filament-panels::page>
    <form wire:submit.prevent="mount" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end">
            <button type="button" wire:click="exportExcel" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-white bg-emerald-600 hover:bg-emerald-500 transition shadow-xs">
                <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                <span>Export ke Excel</span>
            </button>
        </div>
    </form>

    {{ $this->table }}
</x-filament-panels::page>
