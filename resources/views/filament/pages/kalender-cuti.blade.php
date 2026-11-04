<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Form Filter -->
        {{ $this->form }}

        <!-- Tampilan Jadwal Cuti -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 dark:bg-gray-900 dark:border-gray-800">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200 dark:border-gray-800">
                <h3 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <x-heroicon-o-calendar class="w-5 h-5 text-blue-600" />
                    Jadwal Cuti Tim ({{ $this->leaveRequests->count() }} Pengajuan)
                </h3>
            </div>

            @if($this->leaveRequests->isEmpty())
                <div class="text-center py-12 text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-calendar-days class="w-12 h-12 mx-auto text-gray-400 mb-3" />
                    <p class="font-medium text-sm">Tidak ada jadwal cuti/izin pada periode ini.</p>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($this->leaveRequests as $leave)
                        @php($worker = $leave->employee ?? $leave->intern ?? $leave->freelancer)
                        <div class="p-4 rounded-lg border border-gray-200 bg-gray-50 dark:bg-gray-800 dark:border-gray-700 flex flex-col justify-between space-y-3">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="px-2 py-0.5 text-xs font-bold rounded {{ $leave->status === 'approved' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' }}">
                                        {{ $leave->status === 'approved' ? 'Disetujui' : 'Pending Approval' }}
                                    </span>
                                    <span class="text-xs font-semibold text-gray-500">
                                        {{ $leave->days_count }} Hari Cuti
                                    </span>
                                </div>

                                <h4 class="font-bold text-sm text-gray-900 dark:text-white">
                                    {{ $worker?->full_name ?? '-' }}
                                </h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    {{ $worker?->company?->name ?? '-' }} ({{ $worker?->division?->name ?? '-' }})
                                </p>
                            </div>

                            <div class="pt-2 border-t border-gray-200 dark:border-gray-700 text-xs space-y-1">
                                <div class="flex items-center justify-between text-gray-700 dark:text-gray-300">
                                    <span>Tgl Mulai:</span>
                                    <span class="font-bold">{{ $leave->start_date ? $leave->start_date->format('d M Y') : '-' }}</span>
                                </div>
                                <div class="flex items-center justify-between text-gray-700 dark:text-gray-300">
                                    <span>Tgl Selesai:</span>
                                    <span class="font-bold">{{ $leave->end_date ? $leave->end_date->format('d M Y') : '-' }}</span>
                                </div>
                                <div class="mt-2 pt-1 text-gray-600 dark:text-gray-400 italic">
                                    "{{ Str::limit($leave->reason, 60) }}"
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
