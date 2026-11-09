<!-- Master Face Capture Dual-Mode Component -->
<div x-data="{
    photoPath: $wire.entangle('{{ $getStatePath() }}'),
    previewUrl: null,
    activeTab: 'camera',
    uploading: false,
    cameraActive: false,
    cameraError: null,
    facingMode: 'user',
    stream: null,
    isMirrored: true,

    switchTab(tab) {
        this.activeTab = tab;
        if (tab === 'camera') {
            this.initCamera();
        } else {
            this.stopCamera();
        }
    },

    initCamera() {
        if (this.photoPath) return;

        this.cameraError = null;
        this.stopCamera();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.cameraError = 'Browser Anda tidak mendukung akses kamera (perlu HTTPS atau Localhost).';
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: this.facingMode,
                width: { ideal: 640 },
                height: { ideal: 640 }
            },
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
            console.error('Akses kamera gagal:', err);
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                this.cameraError = 'Izin kamera ditolak. Silakan izinkan akses kamera di browser.';
            } else {
                this.cameraError = 'Gagal membuka kamera: ' + (err.message || 'Error tidak diketahui');
            }
            this.cameraActive = false;
        });
    },

    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        if (this.$refs.video) {
            this.$refs.video.srcObject = null;
        }
        this.cameraActive = false;
    },

    toggleCamera() {
        this.facingMode = (this.facingMode === 'user') ? 'environment' : 'user';
        this.isMirrored = (this.facingMode === 'user');
        this.initCamera();
    },

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            this.previewUrl = e.target.result;
        };
        reader.readAsDataURL(file);

        const formData = new FormData();
        formData.append('photo', file);
        this.uploadFormData(formData);
    },

    takeSnapshot() {
        if (!this.$refs.video || !this.cameraActive) return;

        const video = this.$refs.video;
        const canvas = this.$refs.canvas;
        const context = canvas.getContext('2d');

        const size = 480;
        canvas.width = size;
        canvas.height = size;

        const vW = video.videoWidth || 640;
        const vH = video.videoHeight || 480;
        const minDim = Math.min(vW, vH);
        const sx = (vW - minDim) / 2;
        const sy = (vH - minDim) / 2;

        context.save();
        if (this.isMirrored) {
            context.translate(size, 0);
            context.scale(-1, 1);
        }

        context.drawImage(video, sx, sy, minDim, minDim, 0, 0, size, size);
        context.restore();

        const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        this.previewUrl = dataUrl;

        if (canvas.toBlob) {
            canvas.toBlob((blob) => {
                const formData = new FormData();
                if (blob) {
                    formData.append('photo', blob, 'master_face.jpg');
                }
                formData.append('photo_base64', dataUrl);
                this.uploadFormData(formData);
            }, 'image/jpeg', 0.85);
        } else {
            const formData = new FormData();
            formData.append('photo_base64', dataUrl);
            this.uploadFormData(formData);
        }
    },

    uploadFormData(formData) {
        this.uploading = true;
        const csrfToken = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '{{ csrf_token() }}';

        fetch('{{ route('upload.selfie') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: formData
        })
        .then(async (res) => {
            this.uploading = false;
            const text = await res.text();
            let data = null;

            try {
                const start = text.indexOf('{');
                const end = text.lastIndexOf('}');
                if (start !== -1 && end !== -1) {
                    data = JSON.parse(text.substring(start, end + 1));
                }
            } catch (e) {
                console.error('JSON Parsing Error:', e, text);
            }

            if (data && data.success) {
                this.previewUrl = data.url;
                this.photoPath = data.token;
                $wire.set('{{ $getStatePath() }}', data.token);
                this.stopCamera();
            } else {
                const errMsg = data?.message || (text.length > 0 ? text.substring(0, 150) : ('HTTP Status ' + res.status));
                alert('Gagal menyimpan Foto Master Wajah: ' + errMsg);
            }
        })
        .catch((err) => {
            this.uploading = false;
            console.error('Upload error:', err);
            alert('Terjadi kesalahan koneksi saat mengunggah foto master.');
        });
    },

    resetPhoto() {
        this.previewUrl = null;
        this.photoPath = null;
        $wire.set('{{ $getStatePath() }}', null);
        this.activeTab = 'camera';
        this.$nextTick(() => {
            this.initCamera();
        });
    }
}"
x-init="initCamera()"
x-on:unmount="stopCamera()"
class="space-y-3">

    <!-- Canvas Hidden -->
    <canvas x-ref="canvas" class="hidden"></canvas>

    <!-- Error Validation Display -->
    @error($getStatePath())
        <div class="p-2.5 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800/50 rounded-lg text-center">
            <p class="text-xs font-semibold text-red-600 dark:text-red-400">
                {{ $message }}
            </p>
        </div>
    @enderror

    <!-- Preview Foto Master jika sudah diunggah/diambil -->
    <template x-if="photoPath">
        <div class="space-y-3 text-center">
            <div class="relative w-44 h-44 mx-auto rounded-full overflow-hidden border-4 border-emerald-500/80 shadow-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                @php
                    $statePath = $getStatePath();
                    $state = data_get($this, $statePath);
                    $initialUrl = is_string($state) ? (\App\Support\StorageUrl::for($state) ?? '') : '';
                @endphp
                <img :src="previewUrl || '{{ $initialUrl }}'"
                     class="w-full h-full object-cover" />
            </div>
            <div class="flex items-center justify-center gap-2 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                <span>Foto Master Wajah Berhasil Terdaftar</span>
            </div>
            <button type="button" @click="resetPhoto()" class="px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                Ganti Foto Master
            </button>
        </div>
    </template>

    <!-- Pilihan Mode: Kamera vs Upload Berkas -->
    <template x-if="!photoPath">
        <div class="space-y-3">
            <!-- Navigation Tabs -->
            <div class="flex rounded-lg bg-gray-100 dark:bg-gray-800 p-1 border border-gray-200 dark:border-gray-700 max-w-xs mx-auto">
                <button type="button" @click="switchTab('camera')"
                        :class="activeTab === 'camera' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm font-semibold' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 font-medium'"
                        class="flex-1 py-1.5 px-3 text-xs rounded-md transition flex items-center justify-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h0.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span>Kamera Langsung</span>
                </button>
                <button type="button" @click="switchTab('upload')"
                        :class="activeTab === 'upload' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm font-semibold' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 font-medium'"
                        class="flex-1 py-1.5 px-3 text-xs rounded-md transition flex items-center justify-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    <span>Upload File</span>
                </button>
            </div>

            <!-- Tab 1: Kamera Langsung -->
            <div x-show="activeTab === 'camera'" class="space-y-2">
                <div class="relative w-full aspect-video sm:aspect-square max-w-xs mx-auto rounded-lg overflow-hidden bg-black border border-gray-300 dark:border-gray-700 flex items-center justify-center">
                    <video x-ref="video" autoplay playsinline muted :class="{ '-scale-x-100': isMirrored }" class="w-full h-full object-cover"></video>

                    <!-- Face Guide Overlay -->
                    <template x-if="cameraActive && !uploading">
                        <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
                            <div class="w-44 h-52 border-2 border-dashed border-emerald-400 bg-emerald-500/10 rounded-full flex items-center justify-center">
                                <span class="text-[10px] font-bold text-white bg-emerald-600/90 px-2 py-0.5 rounded-full uppercase tracking-wider">Posisikan Wajah</span>
                            </div>
                        </div>
                    </template>

                    <!-- Camera Loading / Error -->
                    <template x-if="!cameraActive">
                        <div class="absolute inset-0 flex flex-col items-center justify-center p-4 text-center text-gray-400 bg-gray-900/90 space-y-2">
                            <template x-if="!cameraError">
                                <p class="text-xs">Membuka Kamera...</p>
                            </template>
                            <template x-if="cameraError">
                                <div class="space-y-2">
                                    <p class="text-xs text-red-400" x-text="cameraError"></p>
                                    <button type="button" @click="initCamera()" class="px-2.5 py-1 text-xs bg-gray-800 text-white rounded border border-gray-700 hover:bg-gray-700">
                                        Coba Lagi
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Upload Loading Overlay -->
                    <template x-if="uploading">
                        <div class="absolute inset-0 flex items-center justify-center bg-black/75 text-white text-xs font-semibold gap-2">
                            <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span>Mengunggah Foto Master...</span>
                        </div>
                    </template>
                </div>

                <!-- Camera Action Buttons -->
                <template x-if="cameraActive && !uploading">
                    <div class="flex items-center justify-center gap-2 max-w-xs mx-auto">
                        <button type="button" @click="takeSnapshot()" class="flex-1 py-2 px-3 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg text-xs transition flex items-center justify-center gap-1.5 shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h0.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            <span>Ambil Foto Wajah</span>
                        </button>
                        <button type="button" @click="toggleCamera()" class="py-2 px-3 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium rounded-lg text-xs transition border border-gray-300 dark:border-gray-600">
                            Ganti Kamera
                        </button>
                    </div>
                </template>
            </div>

            <!-- Tab 2: Upload File dari Penyimpanan -->
            <div x-show="activeTab === 'upload'" class="space-y-2">
                <label class="relative flex flex-col items-center justify-center w-full aspect-video sm:aspect-square max-w-xs mx-auto rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-700 hover:border-emerald-500 dark:hover:border-emerald-500 bg-gray-50 dark:bg-gray-900/50 cursor-pointer transition p-4 group">
                    <div class="flex flex-col items-center justify-center space-y-2 text-center">
                        <div class="p-3 bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 rounded-full group-hover:scale-110 transition duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 002-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Pilih Foto Master dari Galeri / Penyimpanan</p>
                            <p class="text-[10px] text-gray-400">Format: JPG, PNG, WEBP (Maks 5MB)</p>
                        </div>
                    </div>
                    <input type="file" accept="image/*" @change="handleFileSelect($event)" class="hidden" />
                </label>
            </div>
        </div>
    </template>
</div>
