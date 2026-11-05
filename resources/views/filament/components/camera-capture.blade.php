{{--
    Presensi Mandiri: one adaptive check-in / check-out flow driven by the company policy.

    Selfie mode "none" shows no camera, "photo" and "face" take one selfie, "liveness" runs the
    server issued head movement challenge (one photo per step). GPS is only read when the policy
    requires it. Photos go to the upload endpoint, which answers with an upload token; only tokens
    are sent to processCheckIn / processCheckOut. Every rule is checked on the server.

    Expects the Livewire component to use App\Filament\Concerns\HasPresensiActions.
--}}
@php
    $policy = $policy ?? $this->companyPolicy;
    $selfieMode = $selfieMode ?? ($policy?->selfieMode() ?? 'none');
    $requireGps = (bool) ($requireGps ?? $policy?->require_gps ?? false);
    $hasCheckedIn = $hasCheckedIn ?? false;
    $hasCheckedOut = $hasCheckedOut ?? false;
    $needsCamera = $selfieMode !== 'none';
@endphp

<div x-data="{
    selfieMode: @js($selfieMode),
    needsCamera: @js($needsCamera),
    requireGps: @js($requireGps),

    busy: false,
    busyMode: null,
    busyText: '',
    feedback: null,

    cameraActive: false,
    cameraError: null,
    facingMode: 'user',
    stream: null,
    isMirrored: true,

    lat: null,
    lng: null,
    gpsLoading: false,
    gpsError: null,

    challenge: null,
    challengePurpose: null,
    challengeLoading: false,
    stepIndex: 0,
    frames: [],
    expiresAt: null,
    remaining: 0,
    timerId: null,

    init() {
        if (this.needsCamera) {
            this.initCamera();
        }
        this.initGps();
    },

    destroy() {
        this.stopTimer();
        this.stopCamera();
    },

    actionLabel(mode) {
        return mode === 'check_in' ? 'Check In' : 'Check Out';
    },

    initGps() {
        if (!this.requireGps) return;
        this.gpsLoading = true;
        this.gpsError = null;
        if (!navigator.geolocation) {
            this.gpsLoading = false;
            this.gpsError = 'Browser tidak mendukung GPS.';
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                this.lat = pos.coords.latitude;
                this.lng = pos.coords.longitude;
                this.gpsLoading = false;
            },
            (err) => {
                this.gpsLoading = false;
                this.gpsError = err.code === 1
                    ? 'Izin lokasi ditolak. Izinkan akses lokasi di browser lalu klik Ulangi.'
                    : 'Gagal mengambil lokasi GPS. Klik Ulangi.';
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    },

    initCamera() {
        this.cameraError = null;
        this.stopCamera();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.cameraError = 'Browser Anda tidak mendukung akses kamera (perlu HTTPS atau localhost).';
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: this.facingMode, width: { ideal: 640 }, height: { ideal: 640 } },
            audio: false
        })
        .then((s) => {
            this.stream = s;
            this.cameraActive = true;
            this.$nextTick(() => {
                if (this.$refs.video) {
                    this.$refs.video.srcObject = s;
                    this.$refs.video.play().catch(() => {});
                }
            });
        })
        .catch((err) => {
            this.cameraActive = false;
            this.cameraError = (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')
                ? 'Izin kamera ditolak. Izinkan akses kamera di browser.'
                : 'Gagal membuka kamera. Coba lagi.';
        });
    },

    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
            this.stream = null;
        }
        if (this.$refs.video) {
            this.$refs.video.srcObject = null;
        }
        this.cameraActive = false;
    },

    toggleCamera() {
        this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';
        this.isMirrored = this.facingMode === 'user';
        this.initCamera();
    },

    captureBlob() {
        return new Promise((resolve, reject) => {
            const video = this.$refs.video;
            if (!video || !this.cameraActive || !video.videoWidth) {
                reject(new Error('Kamera belum siap. Tunggu sebentar lalu coba lagi.'));
                return;
            }
            const canvas = this.$refs.canvas;
            const context = canvas.getContext('2d');
            const size = 480;
            canvas.width = size;
            canvas.height = size;
            const minDim = Math.min(video.videoWidth, video.videoHeight);
            const sx = (video.videoWidth - minDim) / 2;
            const sy = (video.videoHeight - minDim) / 2;
            context.save();
            if (this.isMirrored) {
                context.translate(size, 0);
                context.scale(-1, 1);
            }
            context.drawImage(video, sx, sy, minDim, minDim, 0, 0, size, size);
            context.restore();
            canvas.toBlob((blob) => {
                blob ? resolve(blob) : reject(new Error('Gagal mengambil foto. Coba lagi.'));
            }, 'image/jpeg', 0.85);
        });
    },

    async upload(blob) {
        const formData = new FormData();
        formData.append('photo', blob, 'selfie.jpg');
        const csrfToken = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || @js(csrf_token());

        let response;
        try {
            response = await fetch(@js(route('upload.selfie')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: formData
            });
        } catch (e) {
            throw new Error('Koneksi terputus saat mengunggah foto. Coba lagi.');
        }

        let data = null;
        try {
            data = await response.json();
        } catch (e) {}

        if (!response.ok || !data || !data.success || !data.token) {
            throw new Error(data?.message || 'Foto gagal diunggah. Coba lagi.');
        }

        return data.token;
    },

    ensureLocation() {
        if (!this.requireGps || (this.lat !== null && this.lng !== null)) {
            return true;
        }
        this.feedback = { type: 'error', text: 'Lokasi GPS belum terdeteksi. Izinkan akses lokasi lalu coba lagi.' };
        this.initGps();
        return false;
    },

    async send(mode, photoToken, challengeId, frameTokens) {
        const lat = this.requireGps ? this.lat : null;
        const lng = this.requireGps ? this.lng : null;
        const result = mode === 'check_in'
            ? await $wire.processCheckIn(photoToken, lat, lng, challengeId, frameTokens)
            : await $wire.processCheckOut(photoToken, lat, lng, challengeId, frameTokens);

        if (!result) {
            return { ok: false, status: 500, field: null, message: 'Terjadi kesalahan. Coba lagi.' };
        }

        this.feedback = {
            type: result.ok ? (result.requires_approval ? 'warning' : 'success') : 'error',
            text: result.message
        };

        return result;
    },

    async submitAttendance(mode) {
        if (this.busy) return;
        this.feedback = null;

        if (this.selfieMode === 'liveness') {
            if (!this.challenge || this.challengePurpose !== mode) {
                await this.startChallenge(mode);
            }
            return;
        }

        if (!this.ensureLocation()) return;

        this.busy = true;
        this.busyMode = mode;

        try {
            let photoToken = null;
            if (this.needsCamera) {
                this.busyText = 'Mengunggah foto...';
                photoToken = await this.upload(await this.captureBlob());
            }
            this.busyText = 'Memproses ' + this.actionLabel(mode) + '...';
            await this.send(mode, photoToken, null, []);
        } catch (e) {
            this.feedback = { type: 'error', text: e.message || 'Terjadi kesalahan. Coba lagi.' };
        } finally {
            this.busy = false;
            this.busyMode = null;
        }
    },

    async startChallenge(mode) {
        this.stopTimer();
        this.challenge = null;
        this.frames = [];
        this.stepIndex = 0;
        this.challengePurpose = mode;
        this.challengeLoading = true;

        try {
            const res = await $wire.issueLivenessChallenge(mode);
            if (!res || !res.ok) {
                this.challengePurpose = null;
                this.feedback = { type: 'error', text: res?.message || 'Gagal memulai verifikasi liveness.' };
                return;
            }
            this.challenge = res;
            this.frames = res.steps.map(() => null);
            this.expiresAt = Date.now() + (res.expires_in * 1000);
            this.tick();
            this.timerId = setInterval(() => this.tick(), 1000);
        } catch (e) {
            this.challengePurpose = null;
            this.feedback = { type: 'error', text: 'Gagal memulai verifikasi liveness. Coba lagi.' };
        } finally {
            this.challengeLoading = false;
        }
    },

    tick() {
        if (!this.expiresAt) return;
        this.remaining = Math.max(0, Math.ceil((this.expiresAt - Date.now()) / 1000));
        if (this.remaining === 0 && !this.busy) {
            const mode = this.challengePurpose;
            this.feedback = { type: 'warning', text: 'Waktu verifikasi habis. Langkah dimulai ulang.' };
            this.startChallenge(mode);
        }
    },

    stopTimer() {
        if (this.timerId) {
            clearInterval(this.timerId);
            this.timerId = null;
        }
        this.expiresAt = null;
    },

    cancelChallenge() {
        this.stopTimer();
        this.revokeFrames();
        this.challenge = null;
        this.challengePurpose = null;
        this.frames = [];
        this.stepIndex = 0;
    },

    revokeFrames() {
        this.frames.forEach((frame) => frame && URL.revokeObjectURL(frame.url));
    },

    get currentStep() {
        return this.challenge ? this.challenge.steps[this.stepIndex] : null;
    },

    get allFramesCaptured() {
        return this.frames.length > 0 && this.frames.every((frame) => frame !== null);
    },

    get remainingLabel() {
        const m = Math.floor(this.remaining / 60);
        const s = this.remaining % 60;
        return m + ':' + String(s).padStart(2, '0');
    },

    async captureStep() {
        if (!this.challenge || this.busy) return;
        try {
            const blob = await this.captureBlob();
            const old = this.frames[this.stepIndex];
            if (old) URL.revokeObjectURL(old.url);
            this.frames[this.stepIndex] = { blob: blob, url: URL.createObjectURL(blob) };
            const next = this.frames.findIndex((frame) => frame === null);
            this.stepIndex = next === -1 ? this.stepIndex : next;
        } catch (e) {
            this.feedback = { type: 'error', text: e.message };
        }
    },

    retakeStep(index) {
        if (this.busy) return;
        const old = this.frames[index];
        if (old) URL.revokeObjectURL(old.url);
        this.frames[index] = null;
        this.stepIndex = index;
    },

    async submitLiveness() {
        if (!this.challenge || !this.allFramesCaptured || this.busy) return;
        this.feedback = null;
        if (!this.ensureLocation()) return;

        const mode = this.challengePurpose;
        this.busy = true;
        this.busyMode = mode;
        let result = null;

        try {
            const tokens = [];
            for (let i = 0; i < this.frames.length; i++) {
                this.busyText = 'Mengunggah foto ' + (i + 1) + ' dari ' + this.frames.length + '...';
                tokens.push(await this.upload(this.frames[i].blob));
            }
            this.busyText = 'Memverifikasi wajah...';
            result = await this.send(mode, null, this.challenge.challenge_id, tokens);
        } catch (e) {
            this.feedback = { type: 'error', text: e.message || 'Terjadi kesalahan. Coba lagi.' };
        } finally {
            this.busy = false;
            this.busyMode = null;
        }

        if (result && result.ok) {
            this.cancelChallenge();
            return;
        }

        // The challenge is single use on the server and refused uploads are deleted: start over,
        // except when retrying cannot help (already checked in / out, or the face service is down).
        const feedback = this.feedback;
        this.cancelChallenge();
        if (!result || (result.status !== 409 && result.status !== 503)) {
            await this.startChallenge(mode);
        }
        this.feedback = feedback;
    }
}"
class="space-y-4 max-w-sm mx-auto">

    <canvas x-ref="canvas" class="hidden"></canvas>

    @if($needsCamera)
        <div class="relative aspect-square w-full rounded-2xl overflow-hidden bg-gray-950 border border-gray-200 dark:border-gray-800 shadow-lg">
            <video x-ref="video" autoplay playsinline muted :class="{ '-scale-x-100': isMirrored }" class="w-full h-full object-cover"></video>

            {{-- Face guide --}}
            <div x-show="cameraActive && !busy" class="absolute inset-0 pointer-events-none flex items-center justify-center">
                <div class="w-48 h-60 rounded-full border-2 border-dashed border-white/70 shadow-[0_0_0_9999px_rgba(0,0,0,0.25)]"></div>
            </div>

            {{-- Liveness step instruction --}}
            <template x-if="challenge && currentStep && !busy">
                <div class="absolute top-2 left-2 right-12 z-10 rounded-lg bg-gray-950/80 backdrop-blur px-3 py-2 text-white border border-white/10">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-primary-300">
                            Langkah <span x-text="stepIndex + 1"></span> dari <span x-text="challenge.steps.length"></span>
                        </span>
                        <span class="text-[11px] font-mono" :class="remaining <= 30 ? 'text-amber-300' : 'text-gray-300'" x-text="remainingLabel"></span>
                    </div>
                    <p class="text-sm font-bold" x-text="currentStep.label"></p>
                    <p class="text-xs text-gray-300" x-text="currentStep.instruction"></p>
                </div>
            </template>

            <button type="button" @click="toggleCamera()" title="Ganti kamera" class="absolute top-3 right-3 z-10 p-2 bg-black/50 hover:bg-black/80 backdrop-blur text-white rounded-full transition border border-white/20 shadow">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </button>

            <template x-if="busy">
                <div class="absolute inset-0 flex flex-col items-center justify-center bg-black/80 text-white backdrop-blur-sm gap-2 z-20">
                    <svg class="animate-spin w-8 h-8 text-emerald-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <span class="text-xs font-semibold text-gray-200" x-text="busyText"></span>
                </div>
            </template>

            <template x-if="!cameraActive">
                <div class="absolute inset-0 flex flex-col items-center justify-center p-4 text-center text-gray-400 bg-gray-900/95 space-y-2 z-10">
                    <template x-if="!cameraError">
                        <div class="flex flex-col items-center gap-2">
                            <svg class="animate-spin w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <p class="text-xs text-gray-300">Membuka kamera...</p>
                        </div>
                    </template>
                    <template x-if="cameraError">
                        <div class="space-y-2">
                            <p class="text-xs text-red-400" x-text="cameraError"></p>
                            <button type="button" @click="initCamera()" class="px-3 py-1.5 text-xs font-medium bg-gray-800 text-white rounded-lg border border-gray-700 hover:bg-gray-700">
                                Coba Lagi
                            </button>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    @endif

    {{-- Location status (only when the policy requires GPS) --}}
    @if($requireGps)
        <div class="flex items-center justify-between gap-2 text-xs px-3 py-2 rounded-lg border bg-gray-50 dark:bg-gray-800/60 border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-1.5 min-w-0">
                <svg class="w-4 h-4 shrink-0 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                <span x-show="gpsLoading" class="text-amber-600 dark:text-amber-400 animate-pulse">Mencari lokasi...</span>
                <span x-show="!gpsLoading && lat !== null" class="text-emerald-700 dark:text-emerald-400 font-mono truncate" x-text="lat !== null ? lat.toFixed(6) + ', ' + lng.toFixed(6) : ''"></span>
                <span x-show="!gpsLoading && lat === null" class="text-red-600 dark:text-red-400" x-text="gpsError || 'Lokasi belum terdeteksi'"></span>
            </div>
            <button type="button" @click="initGps()" class="shrink-0 text-gray-500 hover:text-gray-900 dark:hover:text-white underline">Ulangi</button>
        </div>
    @endif

    {{-- Inline result / error --}}
    <template x-if="feedback">
        <div class="flex items-start gap-2 text-xs px-3 py-2.5 rounded-lg border"
             :class="{
                'bg-red-50 text-red-800 border-red-200 dark:bg-red-950/50 dark:text-red-200 dark:border-red-800': feedback.type === 'error',
                'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-200 dark:border-amber-800': feedback.type === 'warning',
                'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-200 dark:border-emerald-800': feedback.type === 'success',
             }">
            <span class="flex-1" x-text="feedback.text"></span>
            <button type="button" @click="feedback = null" class="shrink-0 opacity-60 hover:opacity-100" title="Tutup">&times;</button>
        </div>
    </template>

    @if($selfieMode === 'liveness')
        {{-- Liveness stepper: one photo per step, tap a photo to retake it --}}
        <template x-if="challenge">
            <div class="space-y-3">
                <div class="grid grid-cols-3 gap-2">
                    <template x-for="(step, index) in challenge.steps" :key="step.key">
                        <button type="button" @click="frames[index] ? retakeStep(index) : (stepIndex = index)" :disabled="busy"
                                class="relative aspect-square rounded-lg overflow-hidden border-2 text-left transition"
                                :class="index === stepIndex ? 'border-primary-500' : (frames[index] ? 'border-emerald-500' : 'border-dashed border-gray-300 dark:border-gray-700')">
                            <template x-if="frames[index]">
                                <img :src="frames[index].url" class="w-full h-full object-cover" alt="">
                            </template>
                            <span class="absolute inset-x-0 bottom-0 px-1.5 py-1 text-[10px] font-semibold bg-gray-950/70 text-white truncate"
                                  x-text="(index + 1) + '. ' + step.label + (frames[index] ? ' · Ulangi' : '')"></span>
                        </button>
                    </template>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button type="button" @click="captureStep()" :disabled="busy || !cameraActive"
                            class="py-3 px-3 text-xs sm:text-sm font-bold rounded-xl shadow-md bg-primary-600 hover:bg-primary-500 text-white disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-text="'Ambil: ' + (currentStep ? currentStep.label : '')"></span>
                    </button>
                    <button type="button" @click="submitLiveness()" :disabled="busy || !allFramesCaptured"
                            class="py-3 px-3 text-xs sm:text-sm font-bold rounded-xl shadow-md text-white disabled:opacity-50 disabled:cursor-not-allowed"
                            :class="challengePurpose === 'check_in' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500'">
                        <span x-text="'Kirim ' + actionLabel(challengePurpose)"></span>
                    </button>
                </div>
                <button type="button" @click="cancelChallenge()" :disabled="busy" class="w-full text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white underline disabled:opacity-50">
                    Batalkan verifikasi
                </button>
            </div>
        </template>
    @endif

    {{-- Check in / check out buttons (liveness: they start the challenge) --}}
    <div class="grid grid-cols-2 gap-3" x-show="!challenge">
        <button type="button"
                @click="submitAttendance('check_in')"
                :disabled="busy || challengeLoading || {{ $hasCheckedIn ? 'true' : 'false' }} || (needsCamera && !cameraActive)"
                class="py-3 px-3 text-xs sm:text-sm font-bold rounded-xl shadow-md transition-all flex items-center justify-center gap-1.5 {{ $hasCheckedIn ? 'bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-600 border border-gray-300 dark:border-gray-700' : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-600/20 active:scale-95' }} disabled:opacity-50 disabled:cursor-not-allowed">
            <svg x-show="(busy && busyMode === 'check_in') || (challengeLoading && challengePurpose === 'check_in')" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span>{{ $hasCheckedIn ? 'Sudah Check In' : 'Check In' }}</span>
        </button>

        <button type="button"
                @click="submitAttendance('check_out')"
                :disabled="busy || challengeLoading || {{ ! $hasCheckedIn || $hasCheckedOut ? 'true' : 'false' }} || (needsCamera && !cameraActive)"
                class="py-3 px-3 text-xs sm:text-sm font-bold rounded-xl shadow-md transition-all flex items-center justify-center gap-1.5 {{ ! $hasCheckedIn || $hasCheckedOut ? 'bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-600 border border-gray-300 dark:border-gray-700' : 'bg-rose-600 hover:bg-rose-500 text-white shadow-rose-600/20 active:scale-95' }} disabled:opacity-50 disabled:cursor-not-allowed">
            <svg x-show="(busy && busyMode === 'check_out') || (challengeLoading && challengePurpose === 'check_out')" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span>{{ $hasCheckedOut ? 'Sudah Check Out' : 'Check Out' }}</span>
        </button>
    </div>

    @if($selfieMode === 'liveness')
        <p x-show="!challenge" class="text-[11px] text-center text-gray-500 dark:text-gray-400">
            Verifikasi liveness: Anda akan diminta mengambil 3 foto sesuai arah kepala yang diminta.
        </p>
    @endif
</div>
