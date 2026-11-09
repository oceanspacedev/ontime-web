<x-filament-widgets::widget class="fi-wi-presensi">
    @php
        $user = auth()->user();
        $employee = $user?->employee;
        $intern = $user?->intern;
        $freelancer = $user?->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        $attendance = $this->todayAttendance;
        $policy = $this->companyPolicy;
        $selfieMode = $policy?->selfieMode() ?? 'none';
        $needsMasterFace = in_array($selfieMode, ['face', 'liveness'], true);
    @endphp

    @if($profile)
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-5">
            <!-- Header -->
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-gray-950 dark:text-white">Presensi Mandiri Realtime</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Mode presensi: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $policy?->modeLabel() ?? 'Klik saja' }}</span></p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300 rounded-full border border-gray-200 dark:border-gray-700 shadow-sm">
                    {{ now()->translatedFormat('l, d F Y') }}
                </span>
            </div>

            <!-- Master face notice when the selfie mode matches faces and none is registered -->
            @if($needsMasterFace && empty($profile->master_face_photo))
                <div class="p-3 bg-amber-50 dark:bg-amber-950/60 border border-amber-300 dark:border-amber-700/80 rounded-xl flex items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <span class="text-amber-800 dark:text-amber-200 font-medium">Foto Master Wajah belum terdaftar. Silakan daftarkan foto master wajah Anda terlebih dahulu di menu Presensi Mandiri.</span>
                    </div>
                </div>
            @endif

            <!-- Section Kamera Live Stream Preview & Side-by-Side Action Buttons -->
            <div>
                @if(!$attendance || !$attendance->check_out)
                    @include('filament.components.camera-capture', [
                        'policy' => $policy,
                        'selfieMode' => $selfieMode,
                        'requireGps' => $policy?->require_gps ?? false,
                        'hasCheckedIn' => !empty($attendance?->check_in),
                        'hasCheckedOut' => !empty($attendance?->check_out),
                    ])
                @else
                    <!-- Absensi Lengkap -->
                    <div class="p-5 bg-emerald-50/60 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-2xl text-center space-y-2 max-w-sm mx-auto">
                        <div class="w-12 h-12 mx-auto rounded-full bg-emerald-100 dark:bg-emerald-900/60 text-emerald-600 dark:text-emerald-300 flex items-center justify-center shadow-sm">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        <h4 class="text-base font-bold text-emerald-900 dark:text-emerald-200">Absensi Hari Ini Selesai</h4>
                        <p class="text-xs text-emerald-700 dark:text-emerald-300">
                            Check In: <span class="font-bold">{{ $attendance->check_in?->format('H:i:s') }}</span> | Check Out: <span class="font-bold">{{ $attendance->check_out?->format('H:i:s') }}</span>
                        </p>
                    </div>
                @endif
            </div>

            <!-- Status Jam Check-In & Check-Out (DI BAWAH) -->
            <div class="pt-4 border-t border-gray-200 dark:border-white/10">
                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Status Jam Presensi Hari Ini</h4>
                <div class="grid grid-cols-2 gap-3 sm:gap-4">
                    <!-- Jam Check-In -->
                    <div class="p-3.5 sm:p-4 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-800">
                        <span class="text-[11px] sm:text-xs font-semibold text-gray-500 uppercase tracking-wider">Jam Check-In</span>
                        <div class="mt-1 text-xl sm:text-2xl font-black text-gray-950 dark:text-white">
                            {{ $attendance?->check_in ? $attendance->check_in->format('H:i:s') . ' WIB' : '--:--:--' }}
                        </div>
                        <div class="mt-1.5">
                            @if($attendance?->check_in)
                                @if($attendance->status === \App\Enums\AttendanceStatus::PendingApproval)
                                    <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-semibold rounded bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200 border border-amber-200 dark:border-amber-800">
                                        Menunggu Persetujuan
                                    </span>
                                @elseif($attendance->status === \App\Enums\AttendanceStatus::Late)
                                    <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-semibold rounded bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200 border border-amber-200 dark:border-amber-800">
                                        Terlambat ({{ $attendance->late_minutes }}m)
                                    </span>
                                @else
                                    <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-semibold rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200 border border-emerald-200 dark:border-emerald-800">
                                        Tepat Waktu
                                    </span>
                                @endif
                            @else
                                <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-medium rounded bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                    Belum Check In
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Jam Check-Out -->
                    <div class="p-3.5 sm:p-4 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-800">
                        <span class="text-[11px] sm:text-xs font-semibold text-gray-500 uppercase tracking-wider">Jam Check-Out</span>
                        <div class="mt-1 text-xl sm:text-2xl font-black text-gray-950 dark:text-white">
                            {{ $attendance?->check_out ? $attendance->check_out->format('H:i:s') . ' WIB' : '--:--:--' }}
                        </div>
                        <div class="mt-1.5">
                            @if($attendance?->check_out)
                                <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-semibold rounded bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200 border border-blue-200 dark:border-blue-800">
                                    Check-Out Selesai
                                </span>
                            @else
                                <span class="inline-block px-2 py-0.5 text-[10px] sm:text-xs font-medium rounded bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                    Belum Check Out
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
