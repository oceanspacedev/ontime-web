<x-filament-panels::page>
    @php
        $week = $this->week;
        $days = $week['days'];
        $dayNames = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $today = today()->toDateString();
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::button color="gray" icon="heroicon-m-chevron-left" wire:click="previousWeek" size="sm">Minggu lalu</x-filament::button>
        <x-filament::button color="gray" wire:click="thisWeek" size="sm">Minggu ini</x-filament::button>
        <x-filament::button color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="nextWeek" size="sm">Minggu depan</x-filament::button>
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ \Carbon\Carbon::parse($week['start'])->translatedFormat('d M') }} – {{ \Carbon\Carbon::parse($week['end'])->translatedFormat('d M Y') }}
        </span>
        <span class="text-xs text-gray-500">Klik sel untuk mengubah. Salin jadwal minggu lalu di halaman Jadwal Shift.</span>
    </div>

    @if (count($week['workers']) === 0)
        <x-filament::section>
            <p class="text-sm text-gray-500">Belum ada jadwal minggu ini. Gunakan "Salin Minggu" atau "Atur Jadwal" di halaman Jadwal Shift.</p>
        </x-filament::section>
    @else
        <x-filament::section :compact="true">
            <div style="overflow-x:auto">
                <table style="border-collapse:separate;border-spacing:4px;width:100%;font-size:0.8125rem">
                    <thead>
                        <tr>
                            <th style="text-align:left;min-width:12rem">Pekerja</th>
                            @foreach ($days as $index => $day)
                                <th style="min-width:4.5rem;{{ $day === $today ? 'color:rgb(var(--primary-500))' : '' }}">
                                    {{ $dayNames[$index] }}<br>{{ substr($day, 8) }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($week['workers'] as $row)
                            <tr wire:key="week-{{ $row['worker']['type'] }}-{{ $row['worker']['id'] }}">
                                <td>
                                    <div style="font-weight:500">{{ $row['worker']['name'] }}</div>
                                    <div style="font-size:0.7rem;opacity:.6">{{ $row['worker']['number'] }}</div>
                                </td>
                                @foreach ($days as $day)
                                    @php($cell = $row['days'][$day] ?? null)
                                    <td style="text-align:center">
                                        <button type="button"
                                            title="{{ $cell ? collect([$cell['shift']['label'] ?? 'Libur', $cell['outlet'], $cell['section'], $cell['shift']['talenta_code'] ?? null])->filter()->implode(' · ') : 'Belum dijadwalkan' }}"
                                            wire:click="mountAction('editCell', @js([
                                                'type' => $row['worker']['type'],
                                                'id' => $row['worker']['id'],
                                                'company_id' => $row['worker']['company_id'],
                                                'name' => $row['worker']['name'],
                                                'date' => $day,
                                                'mode' => ($cell['is_day_off'] ?? false) ? 'off' : 'shift',
                                                'shift_id' => $cell['shift']['id'] ?? null,
                                                'location_id' => $cell['location_id'] ?? null,
                                                'section' => $cell['section'] ?? null,
                                            ]))"
                                            style="width:100%;min-height:2.25rem;border-radius:0.5rem;background:rgba(127,127,127,{{ $cell ? ($cell['is_day_off'] ? '.08' : '.18') : '.04' }});font-weight:{{ $cell ? 600 : 400 }};{{ $cell ? '' : 'opacity:.5' }}">
                                            {{ $this->cellText($cell) }}
                                        </button>
                                        @if ($cell && ! $cell['is_day_off'] && $cell['outlet'])
                                            <div style="font-size:0.65rem;opacity:.6;line-height:1.1">{{ $cell['outlet'] }}</div>
                                        @endif
                                        @if ($cell && $cell['borrowed_from'])
                                            <div style="font-size:0.65rem;color:rgb(var(--warning-500));line-height:1.1">pinjam dari {{ $cell['borrowed_from'] }}</div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
