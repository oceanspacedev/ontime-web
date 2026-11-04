<x-filament-panels::page>
    @php
        $pendingLeaves = $this->pendingLeaveRequests;
        $pendingOvertimes = $this->pendingOvertimeRequests;
        $pendingCorrections = $this->pendingCorrections;
        $pendingGeofences = $this->pendingGeofenceAttendances;
        $pendingResignations = $this->pendingResignations;
        $totalPending = $pendingLeaves->count() + $pendingOvertimes->count() + $pendingCorrections->count() + $pendingGeofences->count() + $pendingResignations->count();

        $disk = config('filesystems.default');
        $getFileUrl = function (?string $path) use ($disk) {
            if (! $path) return null;
            if (str_starts_with($path, 'http')) return $path;
            return $disk === 's3'
                ? \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(60))
                : \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
        };
    @endphp

    <x-filament::section class="mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Pusat Persetujuan</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Daftar pengajuan yang membutuhkan tindakan persetujuan Anda.</p>
            </div>
            <x-filament::badge color="primary" size="lg">
                {{ $totalPending }} Menunggu
            </x-filament::badge>
        </div>
    </x-filament::section>

    @if($totalPending === 0)
        <x-filament::section class="text-center py-12">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Tidak ada antrean approval</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Semua pengajuan yang membutuhkan tindakan Anda telah diproses.</p>
        </x-filament::section>
    @else

        {{-- ====================== GEOFENCE ====================== --}}
        @if($pendingGeofences->isNotEmpty())
            <x-filament::section class="mb-6">
                <x-slot name="heading">Presensi Luar Geofence ({{ $pendingGeofences->count() }})</x-slot>

                <div class="space-y-3">
                    @foreach($pendingGeofences as $item)
                        @php
                            $workerProfile = $item->employee ?? $item->intern ?? $item->freelancer;
                            $company = $workerProfile?->company;
                            $checkInPhotoUrl = $getFileUrl($item->check_in_photo);
                            $checkOutPhotoUrl = $getFileUrl($item->check_out_photo);

                            $geofenceInfo = null;
                            if ($company && $item->check_in_lat && $item->check_in_lng) {
                                $geofenceInfo = \App\Services\GeofenceService::validateCompanyGeofence(
                                    $company,
                                    (float) $item->check_in_lat,
                                    (float) $item->check_in_lng
                                );
                            }

                            $firstActiveLoc = $company?->locations()->where('is_active', true)->first();
                            $officeLat = $geofenceInfo['nearest_lat'] ?? $company?->latitude ?? $firstActiveLoc?->latitude;
                            $officeLng = $geofenceInfo['nearest_lng'] ?? $company?->longitude ?? $firstActiveLoc?->longitude;
                        @endphp

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $workerProfile?->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $workerProfile?->company?->name }} · {{ $workerProfile?->division?->name }}</p>
                                </div>
                                <x-filament::badge color="warning">
                                    Tahap {{ $item->current_step }}/{{ $item->approvalSteps->max('step_order') }}
                                </x-filament::badge>
                            </div>

                            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-xs">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Tanggal</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->date->format('d M Y') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Check In</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->check_in ? $item->check_in->format('H:i:s') : '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Check Out</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->check_out ? $item->check_out->format('H:i:s') : '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Catatan / Note</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->notes ?: '-' }}</dd>
                                </div>
                            </dl>

                            @if($item->check_in_lat && $item->check_in_lng)
                                <div class="mt-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 space-y-2">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        {{-- Posisi Karyawan (Saat Absen) --}}
                                        <div class="p-2.5 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 space-y-1">
                                            <div class="flex items-center justify-between gap-1">
                                                <span class="font-semibold text-amber-600 dark:text-amber-400 flex items-center gap-1.5">
                                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                                    Posisi Karyawan
                                                </span>
                                                <a href="https://maps.google.com/?q={{ $item->check_in_lat }},{{ $item->check_in_lng }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-600 dark:text-amber-400 hover:underline">
                                                    <span>Buka Maps</span>
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            </div>
                                            <div class="font-mono text-gray-700 dark:text-gray-300 text-[11px]">
                                                Lat: <span class="font-semibold">{{ number_format((float) $item->check_in_lat, 7) }}</span><br>
                                                Lng: <span class="font-semibold">{{ number_format((float) $item->check_in_lng, 7) }}</span>
                                            </div>
                                        </div>

                                        {{-- Posisi Kantor (Seharusnya) --}}
                                        <div class="p-2.5 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 space-y-1">
                                            <div class="flex items-center justify-between gap-1">
                                                <span class="font-semibold text-blue-600 dark:text-blue-400 flex items-center gap-1.5 truncate max-w-[180px]" title="{{ $geofenceInfo['nearest_location_name'] ?? $company?->name ?? 'Kantor' }}">
                                                    <span class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0"></span>
                                                    <span class="truncate">{{ $geofenceInfo['nearest_location_name'] ?? $company?->name ?? 'Kantor Utama' }}</span>
                                                </span>
                                                @if($officeLat && $officeLng)
                                                    <a href="https://maps.google.com/?q={{ $officeLat }},{{ $officeLng }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                                        <span>Buka Maps</span>
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                    </a>
                                                @endif
                                            </div>
                                            <div class="font-mono text-gray-700 dark:text-gray-300 text-[11px]">
                                                @if($officeLat && $officeLng)
                                                    Lat: <span class="font-semibold">{{ number_format((float) $officeLat, 7) }}</span><br>
                                                    Lng: <span class="font-semibold">{{ number_format((float) $officeLng, 7) }}</span>
                                                @else
                                                    <span class="text-gray-400 italic">Koordinat kantor belum diatur</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    @if(isset($geofenceInfo['nearest_distance']))
                                        <div class="flex items-center justify-between pt-1.5 border-t border-gray-200 dark:border-gray-700 text-xs">
                                            <span class="text-gray-500 dark:text-gray-400">Selisih Jarak Geofence:</span>
                                            <span class="font-semibold text-danger-600 dark:text-danger-400">
                                                {{ round($geofenceInfo['nearest_distance']) }}m dari radius batas {{ $geofenceInfo['allowed_radius'] ?? 100 }}m
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                <div class="flex items-center gap-2">
                                    @if($checkInPhotoUrl)
                                        <a href="{{ $checkInPhotoUrl }}" target="_blank" class="text-center">
                                            <img src="{{ $checkInPhotoUrl }}" class="w-12 h-12 object-cover rounded-md border border-gray-200 dark:border-gray-700" />
                                            <span class="block text-[10px] text-gray-500 mt-0.5">Check In</span>
                                        </a>
                                    @endif
                                    @if($checkOutPhotoUrl)
                                        <a href="{{ $checkOutPhotoUrl }}" target="_blank" class="text-center">
                                            <img src="{{ $checkOutPhotoUrl }}" class="w-12 h-12 object-cover rounded-md border border-gray-200 dark:border-gray-700" />
                                            <span class="block text-[10px] text-gray-500 mt-0.5">Check Out</span>
                                        </a>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2">
                                    {{ ($this->rejectAction)(['type' => 'geofence', 'id' => $item->id]) }}
                                    {{ ($this->approveAction)(['type' => 'geofence', 'id' => $item->id]) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ====================== CUTI / IZIN ====================== --}}
        @if($pendingLeaves->isNotEmpty())
            <x-filament::section class="mb-6">
                <x-slot name="heading">Pengajuan Cuti / Izin ({{ $pendingLeaves->count() }})</x-slot>

                <div class="space-y-3">
                    @foreach($pendingLeaves as $item)
                        @php
                            $workerProfile = $item->employee ?? $item->intern ?? $item->freelancer;
                            $attachmentUrl = $getFileUrl($item->attachment);
                        @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $workerProfile?->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $workerProfile?->company?->name }} · {{ $workerProfile?->division?->name }}</p>
                                </div>
                                <x-filament::badge color="info">
                                    Tahap {{ $item->current_step }}/{{ $item->approvalSteps->max('step_order') }}
                                </x-filament::badge>
                            </div>

                            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3 text-xs">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Jenis</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ \App\Models\LeaveRequest::typeLabel($item->leave_type) }}
                                        ({{ $item->days_count }} hari)
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Rentang</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->start_date->format('d M Y') }} - {{ $item->end_date->format('d M Y') }}</dd>
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <dt class="text-gray-500 dark:text-gray-400">Alasan</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->reason }}</dd>
                                </div>
                            </dl>

                            <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                @if($attachmentUrl)
                                    <x-filament::button tag="a" href="{{ $attachmentUrl }}" target="_blank" color="gray" size="xs">Lampiran</x-filament::button>
                                @else
                                    <span></span>
                                @endif

                                <div class="flex items-center gap-2">
                                    {{ ($this->rejectAction)(['type' => 'leave', 'id' => $item->id]) }}
                                    {{ ($this->approveAction)(['type' => 'leave', 'id' => $item->id]) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ====================== LEMBUR ====================== --}}
        @if($pendingOvertimes->isNotEmpty())
            <x-filament::section class="mb-6">
                <x-slot name="heading">Pengajuan Lembur ({{ $pendingOvertimes->count() }})</x-slot>

                <div class="space-y-3">
                    @foreach($pendingOvertimes as $item)
                        @php $workerProfile = $item->employee ?? $item->intern ?? $item->freelancer; @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $workerProfile?->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $workerProfile?->company?->name }} · {{ $workerProfile?->division?->name }}</p>
                                </div>
                                <x-filament::badge color="warning">
                                    Tahap {{ $item->current_step }}/{{ $item->approvalSteps->max('step_order') }}
                                </x-filament::badge>
                            </div>

                            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3 text-xs">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Tanggal</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->date->format('d M Y') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Jam</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->start_time }} - {{ $item->end_time }} ({{ $item->duration_minutes }}m)</dd>
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <dt class="text-gray-500 dark:text-gray-400">Tugas</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->reason }}</dd>
                                </div>
                            </dl>

                            <div class="flex items-center justify-end gap-2 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                {{ ($this->rejectAction)(['type' => 'overtime', 'id' => $item->id]) }}
                                {{ ($this->approveAction)(['type' => 'overtime', 'id' => $item->id]) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ====================== KOREKSI ABSENSI ====================== --}}
        @if($pendingCorrections->isNotEmpty())
            <x-filament::section class="mb-6">
                <x-slot name="heading">Koreksi Absensi ({{ $pendingCorrections->count() }})</x-slot>

                <div class="space-y-3">
                    @foreach($pendingCorrections as $item)
                        @php
                            $workerProfile = $item->employee ?? $item->intern ?? $item->freelancer;
                            $attachmentUrl = $getFileUrl($item->attachment);
                        @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $workerProfile?->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $workerProfile?->company?->name }} · {{ $workerProfile?->division?->name }}</p>
                                </div>
                                <x-filament::badge color="primary">
                                    Tahap {{ $item->current_step }}/{{ $item->approvalSteps->max('step_order') }}
                                </x-filament::badge>
                            </div>

                            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3 text-xs">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Tanggal Absensi</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->date->format('d M Y') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Usulan Check-In / Check-Out</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $item->corrected_check_in ? $item->corrected_check_in->format('H:i') : '-' }}
                                        /
                                        {{ $item->corrected_check_out ? $item->corrected_check_out->format('H:i') : '-' }}
                                    </dd>
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <dt class="text-gray-500 dark:text-gray-400">Alasan</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->reason }}</dd>
                                </div>
                            </dl>

                            <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                @if($attachmentUrl)
                                    <x-filament::button tag="a" href="{{ $attachmentUrl }}" target="_blank" color="gray" size="xs">Lampiran</x-filament::button>
                                @else
                                    <span></span>
                                @endif

                                <div class="flex items-center gap-2">
                                    {{ ($this->rejectAction)(['type' => 'correction', 'id' => $item->id]) }}
                                    {{ ($this->approveAction)(['type' => 'correction', 'id' => $item->id]) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ====================== PENGUNDURAN DIRI ====================== --}}
        @if($pendingResignations->isNotEmpty())
            <x-filament::section class="mb-6">
                <x-slot name="heading">Pengunduran Diri ({{ $pendingResignations->count() }})</x-slot>

                <div class="space-y-3">
                    @foreach($pendingResignations as $item)
                        @php $workerProfile = $item->employee; @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $workerProfile?->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $workerProfile?->company?->name }} · {{ $workerProfile?->division?->name }}</p>
                                </div>
                                <x-filament::badge color="danger">
                                    Tahap {{ $item->current_step }}/{{ $item->approvalSteps->max('step_order') }}
                                </x-filament::badge>
                            </div>

                            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3 text-xs">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Tgl Pengajuan</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->resignation_date->format('d M Y') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Hari Kerja Terakhir</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->last_working_day->format('d M Y') }}</dd>
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <dt class="text-gray-500 dark:text-gray-400">Alasan</dt>
                                    <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $item->reason }}</dd>
                                </div>
                            </dl>

                            <div class="flex items-center justify-end gap-2 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                {{ ($this->rejectAction)(['type' => 'resignation', 'id' => $item->id]) }}
                                {{ ($this->approveAction)(['type' => 'resignation', 'id' => $item->id]) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif

    @once
        @push('styles')
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        @endpush
        @push('scripts')
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        @endpush
    @endonce
</x-filament-panels::page>