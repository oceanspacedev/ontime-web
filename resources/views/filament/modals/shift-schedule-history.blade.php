@php
    $actionLabels = ['created' => 'Dibuat', 'updated' => 'Diubah', 'deleted' => 'Dihapus'];
    $sourceLabels = [
        'panel' => 'Panel',
        'api' => 'Aplikasi',
        'import' => 'Impor Excel',
        'copy' => 'Salin minggu',
        'swap' => 'Tukar shift',
        'cover' => 'Masuk di luar jadwal',
        'correction' => 'Koreksi absensi',
        'lifecycle' => 'Akhir kontrak / resign',
        'system' => 'Sistem',
    ];
    $describe = function (?array $state): string {
        if ($state === null) {
            return '–';
        }

        if ($state['is_day_off'] ?? false) {
            return 'Libur';
        }

        $parts = array_filter([
            ($state['shift_code'] ?? null) ?: (($state['shift_id'] ?? null) ? 'Shift #'.$state['shift_id'] : 'Tanpa shift'),
            $state['outlet_name'] ?? null,
            $state['section'] ?? null,
        ]);

        return implode(' · ', $parts);
    };
@endphp

<div class="space-y-3 text-sm">
    @forelse ($changes as $change)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-semibold">{{ $actionLabels[$change->action] ?? $change->action }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $change->created_at?->translatedFormat('d M Y H:i') }} · {{ $sourceLabels[$change->source] ?? $change->source }}
                </span>
            </div>
            <div class="mt-1">{{ $describe($change->before) }} → {{ $describe($change->after) }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Oleh {{ $change->changer?->name ?? 'Sistem' }}@if (filled($change->reason)) · Alasan: {{ $change->reason }}@endif
            </div>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">Belum ada riwayat perubahan untuk tanggal ini.</p>
    @endforelse
</div>
