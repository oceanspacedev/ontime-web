# Rencana QA staging OnTime — web, Android, dan iOS

Disusun 16 November 2026, Asia/Jakarta. Baseline source: `fed60513f0558b4bbfc0e1374fb8a852200fb5c8`.

**Status: rencana pengujian, bukan laporan hasil staging.** Seluruh run dimulai dengan `Not Run`. Analisis menggunakan source backend, mobile, seeders, aturan akses, dan test yang sudah tersedia. Belum ada verifikasi host staging, login staging, eksekusi skenario, atau bukti bug staging. Hipotesis di bawah harus dibuktikan sebelum menjadi temuan terkonfirmasi.

Paket ini mencakup katalog kasus terperinci di bagian akhir dan workbook pendamping dengan daftar run per platform, status, hasil aktual, bukti, dan log temuan. Tidak ada perubahan kode produk atau data staging. Pemeriksaan lokal mobile `npm run lint` dan `npm run typecheck` lulus saat penyusunan; ini tidak membuktikan fitur staging lulus. Test backend tidak dijalankan untuk pekerjaan dokumentasi ini.

## 1. Tujuan, batas, dan cara memakai

Target: alur dari Superadmin membuat klien/PIC sampai pekerja melakukan presensi dan pengajuan, atasan memutuskan, serta HR memeriksa laporan. Cari pelanggaran aturan yang dapat diulang dan ditelusuri, bukan mengejar jumlah tiket duplikat.

1. Lengkapi gate dan data uji. Jalankan `ENV-001`, `ENV-002`, lalu login/isolasi akses.
2. Jalankan `MOD-001`–`MOD-008` pada **Employee, Intern, Freelancer × web, Android, iOS**: 72 run masuk–keluar, masing-masing memakai fixture sendiri.
3. Jalankan P0 berikutnya, P1, kemudian P2. Gunakan filter platform/modul/role pada workbook.
4. Untuk kasus dengan beberapa variasi, catat hasil tiap variasi di hasil aktual. Jika salah satu gagal, run gagal. Jika sebagian belum diuji, run belum `Pass`. Tambahkan sub-run bila perlu bukti terpisah.
5. Kasus dengan banyak aktor, misalnya PIC A dan PIC B, harus menjalankan seluruh pergantian aktor pada langkah. Banyak aktor bukan pilihan salah satu.
6. Catat build, identitas fixture, perangkat/browser, policy snapshot dan bukti. Rekam tiket yang sama untuk gejala dengan akar masalah sama; hubungkan seluruh Run ID terdampak.
7. Reproduksi pada data baru, lokalisasi akar masalah, perbaiki, retest kasus gagal dan kasus tetangganya. Jangan mengubah hasil awal menjadi Pass tanpa menyimpan hasil run pertama.

**Tiga arti “absensi manual” yang diuji terpisah:** klik masuk/keluar tanpa GPS/foto (waktu server), pengajuan koreksi waktu oleh pekerja (perlu approval), dan edit/override Data Absensi oleh admin berwenang (integritas serta audit wajib diperiksa). Check-in biasa bukan fitur bebas memilih timestamp.

## 2. Gate sebelum mulai staging

| Gate | Yang harus dicatat | Kondisi lulus |
|---|---|---|
| G1 Target | Host web/API yang ditetapkan tim, request host aktual, DB/storage environment | Web dan build native benar-benar menuju staging yang sama |
| G2 Build | SHA backend/mobile, build Android/iOS, release/deploy time | Build uji diketahui dan tidak berubah di tengah run tanpa pencatatan |
| G3 Akun/data | Tenant QA/QB, akun tiap persona, profil worker, scope, ID fixture | Tidak memakai record pelanggan; akses sesuai matriks |
| G4 Integrasi | Queue worker sesuai driver, scheduler, cache, storage, OTP sink, push project, face driver | Uji kecil berhasil; tidak mengirim notifikasi ke penerima nyata |
| G5 Waktu | Timezone aplikasi/scheduler, jam server/perangkat, tanggal T0 | Oracle tanggal jelas; default source Asia/Jakarta bukan bukti config deploy |
| G6 Perangkat | Model/OS, build native, browser/version, GPS/kamera nyata | Android dan iOS fisik tersedia untuk tes hardware; minimum OS diambil dari metadata build |
| G7 Oracle | Keputusan bisnis pada bagian 6 dan target performa | Tidak menandai selisih asumsi sebagai bug |
| G8 Observability | Akses log tersanitasi, request correlation, read-only DB atau export kontrol | Transaksi bisa ditelusuri tanpa menyalin secret ke tiket |
| G9 Pemulihan | Prefix data run, snapshot konfigurasi, pemilik cleanup | Perubahan policy/queue/provider dikembalikan setelah run |

**Perhatian konkret dari source:** `mobile/src/lib/session.ts:16` menetapkan API host secara konstan; hydrate menghapus base URL lama. Profil build bernama preview tidak membuktikan bahwa tujuannya staging. **Verifikasi source 16 November 2026 pada `fed60513` (pembacaan kode oleh tiga agen per baris, tanpa eksekusi atau reproduksi): G1 terkonfirmasi.** `mobile/src/lib/session.ts:16` menetapkan `https://ontime.oceanspace.co.id/api/v1`, yang oleh `API.md:14` disebut Produksi. Tidak ada kode yang membaca `process.env`/`EXPO_PUBLIC_*`, tidak ada `app.config.*`, dan profil `preview`/`production` di `eas.json` tidak membawa env host. `EXPO_PUBLIC_API_URL` di `mobile/.env` tidak dibaca kode mana pun. Test `session.test.ts:205–223` mengunci host ini. Artinya build native mana pun, termasuk `preview`, menulis ke host produksi. Seluruh kasus mutasi Android/iOS `Blocked` sampai host dapat dikonfigurasi per profil build (misalnya `EXPO_PUBLIC_API_URL` per profil di `eas.json`) dan host aktif ditampilkan di aplikasi sebagai bukti.

Face driver `basic` adalah pembanding piksel, bukan pengenalan wajah sungguhan. Liveness membutuhkan driver yang mendukung pose (CompreFace). Jika layanan ini tidak tersedia, kasus face production-grade/liveness terblokir oleh environment, bukan otomatis fitur lulus atau gagal.

## 3. Persona dan oracle akses

Hak efektif adalah irisan **tenant × role/capability × data scope × profil/status pekerja**. Menu tersembunyi tidak cukup: ulangi akses URL, API, ID record, filter, export, dan link lama.

| Persona | Fixture | Hak yang diuji | Larangan/kasus negatif utama |
|---|---|---|---|
| Superadmin | SA tanpa tenant | Membuat klien/PIC; shared master; monitor lintas tenant; dukungan override sesuai kode | Bukan approval rutin wajib; tidak memakai endpoint client mobile sebagai PIC |
| PIC | PIC-A dengan Admin+PIC, scope All tenant QA | Onboarding badan usaha, lokasi, divisi, policy, worker, akun Admin | Tidak memberi PIC/Superadmin/custom role; tidak menyentuh tenant QB |
| Admin semua | ADM-ALL-A | Kelola operasional dalam tenant; akun tanpa worker bila role target diizinkan | Tidak memberi role Admin/PIC/Superadmin; tidak membuat badan usaha sebagai PIC |
| Admin perusahaan | ADM-C-A1 | Kelola A1; konfigurasi perusahaan yang diizinkan | A2/B1 dan akun tanpa profil worker tidak ikut otomatis |
| Admin divisi | ADM-D-A1 | Divisi induk dan turunannya, termasuk turunan baru | Sibling dan pengaturan tingkat perusahaan tidak boleh bocor |
| Koordinator | KOOR-X / KOOR-Y | Roster, jadwal, freelance, rekap, approval crew sesuai jangkauan | Pinjaman crew tidak otomatis memberi kepemilikan profil; tenant lain ditolak |
| Approver | APR-A1 / APR-B1 | Tahap sesuai role dan scope | Scope luas tidak menggantikan role tahap |
| BOD | BOD-A | Tahap BOD sesuai flow | Tidak otomatis boleh tahap Approver; final self-approval ditolak |
| Karyawan | EMP-A, EMP-B | Presensi dan pengajuan sendiri; annual leave bila entitlement aktif | Tidak kelola tim/akun; akses peer calendar bukan hak edit pengajuan peer |
| Magang | INT-A, INT-B | Presensi, izin/lembur/koreksi sesuai fitur sendiri | Tidak annual leave, kalender cuti tim, atau pengunduran diri khusus Employee |
| Freelancer | FRE-A, FRE-B | Presensi termasuk split shift; pengajuan yang didukung | Tidak annual leave; kontrak expired tidak bisa bekerja |

Gunakan juga akun multi-role, akun tanpa profil, profil berstatus ended, tenant nonaktif, worker numerik ID sama pada tiga tabel, dan admin scope Own. `All` pada PIC/Admin berarti seluruh tenant sendiri, bukan seluruh platform.

Baseline dokumen lama yang tidak boleh dijadikan oracle tunggal: `UAT.md` 7.3 masih menyebut final Superadmin; flow sekarang mengikuti konfigurasi dan default Approver. UAT 1.2 juga tidak mencerminkan delegasi scope Admin yang sekarang diizinkan sampai batas aksesnya. Periksa source/test dan keputusan produk untuk build yang benar-benar dideploy.

## 4. Data uji yang dapat disiapkan ulang

| Data | Nilai/variasi yang disiapkan |
|---|---|
| Tenant | QA dengan perusahaan A1/A2/A3; QB dengan B1; satu legacy tanpa tenant untuk uji fail-closed |
| Hierarki | A1: Sales → Brand X → Outlet X1/X2, sibling Finance; Brand Y terpisah |
| Lokasi | HQ, L1/L2 aktif dan berjauhan, L3 nonaktif; pusat uji L1 = −6.2000000, 106.8166000; radius 100 m |
| Worker | EMP/INT/FRE aktif di setiap mode; salinan pembanding tenant QB; akun/tanggal berbeda per Run ID |
| Identitas batas | Nomor ID worker sama pada tabel Employee/Intern/Freelancer; kode perusahaan sama lintas tenant; email beda case/spasi |
| Status | Aktif, akun nonaktif, tenant nonaktif, ended, kontrak berakhir T0−1/T0/T0+1, resignation future/effective |
| Shift reguler | P = 08:00–17:00, toleransi 15 menit |
| Shift malam | N = T0 22:00–T0+1 06:00, 480 menit kerja bila lengkap |
| Split shift | S = 07:45–13:00 dan 17:45–22:00; segmen 2 buka 16:45; titik tengah jeda 15:22:30 |
| Jadwal | Default shift, roster bertanggal berbeda, OFF, hari libur, Minggu kerja, pekerja pinjaman outlet |
| Mode | M0–M7 sesuai tabel berikut; jangan ubah satu policy bersama saat run paralel |
| Foto | JPEG/PNG/WebP valid; corrupt; file teks inert berakhiran .jpg; 5120 KB dan 5121 KB; orientasi/mirrored; cahaya rendah |
| Wajah | Master pekerja sendiri, belum ada master, berbeda, dihapus di perangkat lain; provider test score T−1/T/T+1 |
| GPS | Pusat, radius−0.01 m, tepat radius, radius+0.01 m; lat/lng nol dan batas domain; accuracy 0/5/5000/negatif; mock true/false |
| Approval | Satu tahap, Approver→BOD, named user, tidak ada approver aktif, requester sendiri, tahap sudah diproses |
| Kuota | Employee entitled/non-entitled; sisa kuota 0/3; dua request masing-masing 2 hari; Intern/Freelancer tanpa annual |
| Rekonsiliasi | Dua worker satu tanggal: A hadir, B mangkir; cuti D1–D10 ditanya D3–D5; segmen 1 kosong segmen 2 hadir |
| Periode | Awal/akhir bulan/tahun, rentang satu hari, reversed range, tanggal leap-day pada fixture yang sesuai |

`database/seeders/QaDailyOpsSeeder.php` sudah menyediakan sekitar 1.550 pekerja pada dua klien dan tiga kebijakan dasar QA (klik/GPS/GPS+foto), hierarki, shift, dan pengajuan. Seeder **tidak mencakup seluruh delapan mode**; buat fixture tambahan face/liveness dan boundary. `UatAccountSeeder` adalah alternatif dataset kecil. Jangan memasukkan password ke dokumen/tiket.

Seeder tidak dijalankan pada pekerjaan ini. Operator boleh menggunakannya setelah G1–G3 terverifikasi di staging khusus, bukan di tengah run atau pada database berisi fixture yang sedang dipakai:

```sh
php artisan db:seed --class=QaDailyOpsSeeder --no-interaction
```

Seeder menolak production, tetapi label APP_ENV saja bukan jaminan target aman. Seeder melakukan update/upsert dan dapat mengubah fixture yang sudah ada. Simpan konfigurasi awal dan lakukan cleanup hanya pada ID/prefix milik run. Tidak perlu seed besar untuk setiap pengujian.

## 5. Delapan mode dan state presensi

| Mode | GPS wajib | Selfie mode | Persyaratan happy path |
|---|---|---|---|
| M0 | Tidak | none | Klik saja, izin kamera/lokasi tidak boleh menjadi penghalang |
| M1 | Ya | none | GPS valid pada lokasi yang diizinkan |
| M2 | Tidak | photo | Foto tanpa wajib master atau face match |
| M3 | Ya | photo | Foto + GPS |
| M4 | Tidak | face | Foto + master + face match |
| M5 | Ya | face | GPS + foto + master + face match |
| M6 | Tidak | liveness | Master + challenge baru + tiga pose + face match |
| M7 | Ya | liveness | Semua persyaratan M6 + GPS |

State yang harus dibuktikan: belum masuk → masuk → keluar; split: segmen 1 closed → segmen 2 masuk → segmen 2 closed. Checkout tanpa masuk dan submit berulang tidak boleh menciptakan record baru. Pisahkan state approval pending/approved/rejected dari state jam masuk/keluar. Approval check-in yang sudah selesai dapat diikuti ronde baru pada checkout bermasalah; jika ronde awal masih pending, jangan menggandakan ronde.

Oracle kode yang penting:

- Di luar geofence menghasilkan `pending_approval`, bukan selalu reject. Mock GPS dan face mismatch mengikuti pilihan reject/approval. Alasan gabungan harus tetap lengkap.
- Batas radius inklusif; jarak backend dibulatkan dua desimal. Uji boundary secara deterministik pada API dan verifikasi hardware di lokasi fisik; GPS nyata tidak cukup presisi untuk membuktikan selisih 1 cm.
- Toleransi P: 08:15:00 masih on time; 08:15:01 late dengan menit penuh dari 08:00 (dibulatkan integer), bukan hanya kelebihan setelah toleransi. Kebijakan ini perlu diketahui HR.
- Shift malam terbuka memakai tanggal masuk. Jendela row kemarin adalah 20 jam sejak check-in. Tepat batas dan 1 detik setelahnya harus dibedakan.
- OFF tidak otomatis menolak presensi; perhitungan late adalah nol sesuai kode. Jangan mengasumsikan semua hari Senin–Jumat wajib kerja.
- Liveness challenge terikat user/purpose, default TTL 180 detik sesuai config, single-use termasuk percobaan gagal. Pose default front |yaw|≤15°, side |yaw|≥20°, |pitch|≤25°; sisi harus berlawanan. Nilai deploy perlu dicatat.
- Token selfie web terikat user dan default 15 menit. Token bukan otomatis sekali pakai; freshness/reuse membutuhkan oracle tersendiri.
- API foto mempunyai validasi gambar dan batas 5120 KB per file. Uji jalur web terpisah karena implementasi upload berbeda.

## 6. Keputusan bisnis yang perlu dipastikan agar temuan valid

Tandai `Blocked` pada penilaian kasus terkait bila oracle belum disepakati; perilaku aktual tetap boleh direkam. Ini tidak menghalangi kasus lain.

| Topik | Keputusan yang diperlukan |
|---|---|
| GPS segar | Maksimum usia/accuracy yang dapat diterima saat submit; penanganan approximate location dan GPS indoor |
| Anti-spoof | Apakah target hanya indikasi mock GPS/pose atau menjamin capture baru dan identitas seluruh frame; jangan menyebut liveness menjamin anti-replay tanpa bukti |
| Kontrak malam terakhir | Cara checkout setelah end_date terlewati dan akun tidak lagi boleh bekerja |
| Shift setelah tengah malam | Business date untuk check-in pertama 00:30 pada shift yang dimulai kemarin |
| Histori | Policy/jadwal/radius yang dipakai saat data lama diapprove atau dikoreksi; kebutuhan snapshot dan audit |
| Koreksi manual | Tanggal masa depan, dua waktu kosong, koreksi overnight/split, dan siapa boleh override approval |
| Laporan | Definisi absent vs leave, hari vs worker-day vs row, pending/rejected, hari kerja custom, sumber angka yang resmi |
| Kuota | Tahun alokasi cuti lintas tahun, pending reservation, dua approval bersamaan, konflik dengan attendance existing |
| Privasi | Siapa boleh melihat foto/alasan cuti; aturan signed URL dan foto di kalender rekan |
| Logout offline | Apakah logout lokal cukup sementara atau token harus segera dicabut; keterbatasan best-effort harus eksplisit |
| Performa | Volume, jaringan, hardware dan SLA disepakati. Target pada OPS-004/005 adalah usulan, bukan SLA yang sudah berlaku |

## 7. Urutan eksekusi dan tanggung jawab

| Gelombang | Pemilik utama | Fokus dan keluaran |
|---|---|---|
| 0 | QA lead + DevOps | Lengkapi gate; fixture; build; provider/sink; keputusan oracle |
| 1 | QA web + QA mobile | Login, tenant isolation, 72 run matriks mode, alur sehari per jenis pekerja |
| 2 | QA API/backend + QA web | Concurrency, waktu/shift, koreksi, upload, approval dan mutasi scope |
| 3 | QA mobile | Native permissions, camera/GPS lifecycle, offline/retry, cache akun, deep link dan push |
| 4 | QA lead + HR/PIC | Rekonsiliasi data detail, laporan, kalender, export; lifecycle worker/kontrak/resign |
| 5 | QA + engineer pemilik modul | Reproduksi temuan, root cause, fix, regression, sign-off |

Alur sehari lintas peran: SA membuat QA+PIC → PIC membuat perusahaan/divisi/lokasi/policy/flow → HR membuat tiga tipe worker+akun → Koordinator menyusun reguler/malam/split → worker check-in → pengajuan/exception → Approver/BOD/Koordinator sesuai flow → worker check-out → HR memeriksa detail dan rekap → SA memeriksa kesehatan platform. Ulangi lintas tenant untuk membuktikan batas akses.

Mulai dengan satu perangkat fisik Android dan iPhone serta dua browser desktop. Tambahkan OS minimum dan OS dominan pengguna, perangkat Android kelas rendah, layar kecil/font besar, Chrome/Safari mobile untuk web, dan kondisi jaringan berbeda. Jangan menebak versi minimum dari dependency package.

Workbook berisi 180 skenario dan 918 run berdasarkan platform/aktor, termasuk 72 run matriks mode. Perkiraan awal 10–15 menit/run menghasilkan 153–230 jam pengujian, sebelum tambahan triage/retest. Dengan cadangan 30% dan 2 QA × 6 jam efektif/hari, alokasikan sekitar 17–25 hari kerja; ukur ulang setelah 20 run pertama. Kasus multi-variasi dapat lebih lama. Boundary waktu memakai fixture/test clock terisolasi atau jadwal nyata, tidak menggeser jam global staging bersama. Tidak semua kombinasi browser/OS harus dikalikan penuh: matriks mode mencakup ketiga jenis pekerja dan platform, lalu pairwise perangkat/jaringan untuk P1/P2 dan pengulangan penuh pada P0 terdampak temuan.

## 8. Jalur investigasi akar masalah

Urutan diagnosis: UI state → request aktual → validasi/otorisasi → policy/schedule resolver → transaksi database → storage/provider → queue → pembacaan cache/report. Jangan menyimpulkan “backend belum menerima” hanya dari timeout perangkat.

| ID | Gejala/hipotesis | Cara membuktikan | Titik awal source | Verifikasi source (`fed60513`) |
|---|---|---|---|---|
| RSK-01 | Kolom foto admin gagal dibuka | Aktifkan Foto Masuk/Keluar pada row berfoto; cocokkan stack class-not-found dan deployed SHA | `app/Filament/Resources/Attendances/AttendanceResource.php:216` memakai StorageUrl tanpa import yang terlihat | **Terkonfirmasi di source** (dugaan severity Medium). `AttendanceResource.php` (namespace `App\Filament\Resources\Attendances`) tidak meng-import `App\Support\StorageUrl`. Closure `->state()` baris 216 dan 222 me-resolve ke `App\Filament\Resources\Attendances\StorageUrl` yang tidak ada, sehingga Error class not found muncul begitu kolom toggleable diaktifkan. Tidak ada alias atau class_alias. |
| RSK-02 | Upload web menerima nonimage/oversize | Gunakan teks inert base64/.jpg dan gambar >limit; bandingkan API; cek token lalu evidence record | `app/Http/Controllers/AttendancePhotoController.php:42` | **Terkonfirmasi di source** (dugaan severity Medium). `upload()` tanpa `validate()` atau FormRequest. Cabang multipart hanya `isValid()` lalu `FileNamingService::storeUploadedFile` (ekstensi dari nama file klien, tanpa cek MIME/ukuran). Cabang base64 hanya cek base64 valid lalu menulis byte mentah sebagai `selfie.jpg`. Token tetap diterbitkan (baris 71). |
| RSK-03 | Dua checkout sama-sama sukses | Sinkronkan dua request berbeda foto/GPS, simpan timing, response dan state final | `app/Services/AttendanceCheckService.php:308`; periksa guard sebelum update dan lock/conditional update | **Terkonfirmasi di source** (dugaan severity Medium). `attendanceToCheckOut()` (357–370) membaca row dengan SELECT tanpa lock di luar transaksi; guard `check_out` (317–319) memeriksa salinan di memori; `runChecks` (face/liveness) memperlebar jendela race; `update()` di `DB::transaction` tanpa `whereNull('check_out')` atau baca ulang. |
| RSK-04 | Check-in pada row kosong saling menimpa | Siapkan row hasil koreksi dengan check_in null, kirim dua check-in | `app/Services/AttendanceCheckService.php:269`; unique insert tidak otomatis melindungi update | **Terkonfirmasi di source** (dugaan severity Medium). `performCheckIn` (193–194) SELECT tanpa lock; guard `check_in` (204) null di kedua request; `saveCheckIn` (264–292) memakai row yang ada sehingga UPDATE, unique index (worker, date, segment) tidak terpicu dan catch `UniqueConstraintViolationException` (239–244) tidak jalan. Last write wins; `generateSteps` bisa dobel. Row `check_in` null muncul dari koreksi check-out saja (`AttendanceCorrection::applyCorrection`, firstOrNew) atau row cuti (`LeaveRequest::applyLeave`). Temuan tambahan: check-in di atas row cuti menimpa status cuti tanpa guard. |
| RSK-05 | Koreksi malam menghasilkan 0 jam | Koreksi D 22:00 sampai D+1 06:00, approve, bandingkan kedua tanggal dan 480 menit | `app/Models/AttendanceCorrection.php:32`; anchoring tanggal + clamp durasi | **Terkonfirmasi di source** (dugaan severity High). Hook saving `AttendanceCorrection` memindahkan kedua jam ke `date` lewat `Carbon::parse(date)->setTimeFrom()`, tanpa rollover hari berikutnya atau validasi urutan: 22:00 menjadi D 22:00, 06:00 menjadi D 06:00. `Attendance::workedMinutes()` = max(0, −960) = 0. Datetime lengkap D+1 dari API juga dipindah ke D. |
| RSK-06 | Input jam koreksi rusak menjadi 500 | Kirim abc/25:61/kedua waktu kosong, lihat validasi sebelum casting | Lihat path `StoreAttendanceCorrectionRequest` pada ATT-047 | **Sebagian terkonfirmasi di source** (dugaan severity Medium). Urutan pada hipotesis salah: FormRequest berjalan sebelum casting. Akar sebenarnya: rule `corrected_check_in/out` hanya `nullable|string` (Store dan Update), sehingga `abc`/`25:61` lolos lalu cast datetime saat `fill()` melempar Carbon `InvalidFormatException` → 500 JSON. Kedua jam kosong tidak 500: tersimpan 201, dan saat approve hanya `is_corrected=true` (koreksi tanpa efek, celah validasi). |
| RSK-07 | Late berubah sesudah approval | Simpan roster awal, ubah jadwal saat pending, approve lalu bandingkan late | `app/Models/Attendance.php:134`; resolve roster terkini | **Terkonfirmasi di source** (dugaan severity Medium). Check-in yang butuh approval disimpan `late_minutes=0` dan `PendingApproval` (`AttendanceCheckService` 231–236). Saat approval final, `Attendance::applyGeofenceApproval` memanggil ulang `WorkScheduleService::resolve()` dari roster, default shift, dan policy terkini, mengabaikan `shift_id` yang tersimpan. |
| RSK-08 | Lokasi lama tetap terkirim | Fix di dalam area, pindah keluar, background/resume, submit; bandingkan payload koordinat | `mobile/src/app/attendance/check.tsx:183`; cached location | **Terkonfirmasi di source** (dugaan severity Medium). Query `device-location` (check.tsx:116–124) dibaca sekali. Listener AppState (128–138) hanya refetch bila pembacaan gagal; `focusManager` tidak dihubungkan ke AppState sehingga refetchOnWindowFocus tidak jalan di React Native; tidak ada refetchInterval/watchPosition. `mutationFn` (180–188) menyalin koordinat dari cache tanpa membaca ulang. |
| RSK-09 | Sesi B menampilkan profil A | Tahan response me A, logout/login B, lepaskan response; cocokkan identity tanpa merekam token | `mobile/src/hooks/useRefreshUser.ts:9` | **Sebagian terkonfirmasi di source** (dugaan severity Medium). `onSuccess` di `useRefreshUser` memanggil `setUser` tanpa cek token/sesi; logout tidak membatalkan request; respons /me A bisa menimpa user setelah B login dan ikut tersimpan di SecureStore. Terbatas: harus selesai dalam timeout 30 detik (client.ts:168) dan respons A tiba setelah /me milik B (`(app)/_layout.tsx:30–32`), atau /me B gagal. |
| RSK-10 | Offline pending tak dapat dibatalkan | Offline sebelum submit, tunggu, online lagi; catat kapan mutation benar-benar mulai | `mobile/src/components/OfflineBanner.tsx:20`; pause onlineManager dan modal pending | **Sebagian terkonfirmasi di source** (dugaan severity High). Mutation memakai networkMode `online` dan di-pause saat offline (onlineManager di OfflineBanner.tsx:21–27); UI hanya spinner, tombol tutup disabled, tidak ada cancel. Saat online, `resumePausedMutations` mengirim check-in dengan GPS/liveness saat tap, meski layar sudah ditutup. Terbatas: layar tak bisa ditutup hanya di iOS (fullScreenModal, gestureEnabled:false); di Android tombol back tetap bisa, tetapi mutasi tetap terkirim. Pause hanya terjadi bila expo-network melaporkan offline secara eksplisit. |
| RSK-11 | Satu pekerja hadir menutupi absent rekan | Dataset 2 worker/1 hari; bandingkan individu vs agregat tim | `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:149` | **Terkonfirmasi di source** (dugaan severity High). `laporanAbsensi` menghitung absent tanpa catatan dari `$existingDates` (149–151), daftar tanggal distinct tanpa kunci worker, dan `$approvedLeaveDates` per tanggal. Loop hari kerja (158–169) menambah maksimal 1 per tanggal, hanya jika tidak ada worker yang punya row atau cuti. Tidak ada proses yang menulis row absent untuk no-show. |
| RSK-12 | Cuti melingkupi rentang hilang/berlebih | Cuti D1–D10, report D3–D5; kontrol irisan =3 hari | `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:122` | **Terkonfirmasi di source** (dugaan severity Medium). Filter cuti (120–126) hanya `whereBetween(start_date) OR whereBetween(end_date)`; cuti yang melingkupi rentang (D1–D10 vs D3–D5) tidak terambil → `total_leave` 0 dan hari itu terhitung absent. Cuti yang terambil diekspansi dari start sampai end tanpa dipotong ke rentang → berlebih. `kalenderCuti` di file yang sama (43–49) punya cabang ketiga. |
| RSK-13 | Signed export hidup sesudah tenant nonaktif | Ambil link, nonaktifkan tenant, buka link sebelum expiry; bandingkan auth API | `app/Http/Controllers/TeamRecapDownloadController.php:19` | **Terkonfirmasi di source** (dugaan severity Medium). Route `exports.team-recap` hanya middleware `signed` (routes/web.php:52–54); baris 19 hanya cek `is_active` user dan `canManageWorkers()`. Menonaktifkan tenant (`Tenant::booted`) hanya menghapus token Sanctum, tidak mengubah `users.is_active`; cek tenant aktif hanya ada di `User::canSignIn()`. Link 15 menit tetap mengunduh data terbaru. |
| RSK-14 | Named approver lama menembus scope | Mutasi yang didukung aplikasi ketika pending; ulangi inbox/detail/process dan bandingkan role-based flow | `app/Services/ApprovalFlowService.php:575` | **Sebagian terkonfirmasi di source** (dugaan severity High). Cabang direct-user `isUserAuthorizedToApprove` (575–577) hanya membandingkan ID, tanpa cek tenant/penempatan seperti cabang role (580–589). Terbatas: inbox sudah difilter tenant (`getPendingRequestsForUser` 278–283). Celah ada di aksi process: `PUT /api/v1/approvals/{type}/{id}/process` (`find` tanpa scope, ApprovalApiController:66) dan aksi Livewire `ApprovalSaya` (142–153, 176–187). Mutasi pemicunya (tenant_id user/company) hanya bisa dilakukan Superadmin. |

Semua baris ini adalah **hipotesis/observasi source**, bukan jumlah bug terkonfirmasi. Kolom verifikasi berisi hasil verifikasi source 16 November 2026 pada `fed60513` (pembacaan kode oleh tiga agen per baris, tanpa eksekusi atau reproduksi): 10 terkonfirmasi, 4 sebagian terkonfirmasi, 0 terbantah. Status itu menunjukkan jalur kode ada pada baseline, bukan bahwa bug sudah direproduksi di staging; tetap isi workbook `Temuan` hanya setelah ada reproduksi. Untuk menyatakan root cause terkonfirmasi, hubungkan jalur kode pada SHA deploy dengan log dan state transaksi; kemudian buktikan perbaikan menutup reproduksi tanpa merusak kasus tetangga.

### 8a. Status perbaikan di branch `fix/qa-staging-findings` (17 November 2026, belum di-commit/deploy)

Perbaikan diverifikasi dengan regression test lokal (backend 950 test lulus, mobile jest 73 test lulus, typecheck dan lint bersih) serta review adversarial independen per RSK. **Ini bukan hasil staging:** semua run di workbook tetap `Not Run` sampai build berisi perbaikan ini di-deploy ke staging dan diuji ulang. Saat retest, jalankan kasus yang gagal beserta kasus tetangganya.

| ID | Status di branch | Perbaikan dan test |
|---|---|---|
| G1 | Diperbaiki, gate operasional masih terbuka | Host dibaca dari `EXPO_PUBLIC_API_URL` per build. Fallback ke produksi hanya ada di profil `production`. Build preview tanpa host tidak bisa login atau mengirim request. Host aktif tampil di login dan di Profil untuk semua build non-produksi, termasuk jika host salah menunjuk ke produksi. **Masih diperlukan:** set URL staging untuk environment EAS `preview`. Sebelum itu, mutasi Android/iOS tetap `Blocked`. `session.test.ts` |
| RSK-01 | Diperbaiki | Import `StorageUrl` ditambahkan. `AttendanceListPhotoColumnsTest` |
| RSK-02 | Diperbaiki | Upload multipart dan base64 divalidasi untuk tipe gambar dan batas ukuran sebelum token diterbitkan. `AttendancePhotoTest` |
| RSK-03/04 | Diperbaiki | Check-in dan check-out membaca ulang row terkunci di dalam transaksi. Cuti yang masih approved menolak check-in, termasuk cuti yang di-approve saat check-in berjalan. Row cuti yatim (cutinya dihapus atau tanggalnya diubah) tidak lagi memblokir check-in. `AttendanceCheckRaceAndLeaveTest` |
| RSK-05 | Diperbaiki untuk data baru | Jam pulang yang lebih awal dari jam masuk digeser ke D+1. **Data lama:** koreksi malam yang sudah approved sebelum deploy tetap tercatat 0 jam, karena tidak ada migrasi perbaikan data. Perlu keputusan apakah data lama diperbaiki. Retest wajib memakai data baru. `AttendanceCorrectionInputTest` |
| RSK-06 | Diperbaiki | Format jam divalidasi (422, bukan 500). Koreksi wajib berisi minimal satu jam. Approval koreksi lama yang tanpa jam ditolak dengan pesan, tanpa membuat atau mengubah row absensi. `AttendanceCorrectionInputTest` |
| RSK-07 | Diperbaiki | Keterlambatan disimpan saat check-in (`check_in_late_minutes`) dan dipakai saat approval final. Snapshot hanya dibuang jika menit check-in benar-benar berubah, sehingga menyimpan form admin Filament tidak lagi menghapusnya. `AttendanceLateSnapshotEditTest`, `DailyPerformanceTest` |
| RSK-08 | Diperbaiki | GPS dibaca ulang saat submit. Fix cache hanya diterima jika berumur ≤10 detik. Jika gagal membaca, submit gagal, bukan mengirim koordinat lama. `attendanceCheck.test.ts` |
| RSK-09 | Diperbaiki | Respons `/me` dari sesi lama diabaikan (guard epoch). Respons 401 yang terlambat dari token lama tidak lagi me-logout akun baru. `session.test.ts` |
| RSK-10 | Diperbaiki | Check saat offline langsung gagal dengan pesan, tidak di-pause, dan tidak dikirim ulang diam-diam. `attendanceCheck.test.ts` |
| RSK-11 | Diperbaiki | Absen dihitung per pekerja per hari. Day-off di jadwal shift tidak dihitung absen. Pekerja yang kontraknya sudah berakhir tetap dihitung absen sampai tanggal akhir kontrak. Karyawan yang direkrut ulang tidak terpotong oleh resign lama. `ReportsAbsenceCountTest` |
| RSK-12 | Diperbaiki | Cuti yang melingkupi seluruh rentang ikut terambil, dan hari cuti dipotong ke rentang laporan. `ReportsAbsenceCountTest` |
| RSK-13 | Diperbaiki | Link ekspor bertanda tangan ditolak jika tenant sudah nonaktif. `TeamRecapTest` |
| RSK-14 | Diperbaiki | Approver bernama wajib berada di tenant yang sama dengan request, baik saat process maupun saat penentuan pemegang tahap. Jika approver pindah tenant, notifikasi dan reminder jatuh ke Admin tenant. `ApprovalTenantScopeTest` |

Di branch yang sama juga dibersihkan kode debug sisa uji perangkat: `QA-TRACE-TEMP`, yang mengirim fetch ke `localhost:8000`, dan judul layar `Absen Masuk QA7`.

### 8b. Eksekusi lokal: audit kontrak, sweep alur bisnis, dan perangkat (17 November 2026)

**Yang dijalankan (lokal, bukan staging):**
- Audit kontrak API mobile ↔ backend untuk 8 modul API mobile.
- Sweep katalog P0/P1 (AUT, OPS, ROL, ATT, MOD) sebagai test feature yang dapat dieksekusi, di `tests/Feature/QaProbe/{AUT,OPS,ROLA,ROLB,ROLC,ATTA,ATTB,ATTC}ProbeTest.php`.
- Uji perangkat ke backend demo terisolasi: SQLite, tanpa WA/SMTP/S3, sesuai resep di memori proyek.

Hasilnya 68 temuan terkonfirmasi setelah verifikasi adversarial: 5 High, 32 Medium, 31 Low. Dua temuan terbantah: ROL-001, karena 403 dengan pesan Indonesia memang dimaksudkan, dan ATT-059, karena filter tidak valid memang diabaikan. Probe untuk keduanya disesuaikan.

**Perbaikan:** 66 temuan diperbaiki, masing-masing dengan test regresi. Lokasi test:
- `tests/Feature/Qa*Test.php`
- `AttendanceCorrectionInputTest`
- `mobile/src/lib/__tests__/*.test.ts`

Ringkasan per area:

| Area | Inti perbaikan |
|---|---|
| Approval API | Approver yang ditunjuk langsung kini bisa membuka detail pengajuan; sebelumnya mendapat 403 sehingga pengajuan macet. `current_step` dan `rejection_reason` dikirim ke mobile. Pesan 403/404 berbahasa Indonesia tanpa nama class. Lampiran tidak lagi tertinggal saat gagal, diganti, atau dibatalkan. Hari cuti tidak menawarkan Absen Masuk. Urutan list stabil. |
| Cuti & kuota | Kuota dihitung per tahun kalender. Approval final diserialisasi per pekerja. Cuti tahunan yang tumpang tindih ditolak. Cuti di hari yang sudah ada check-in **ditolak saat approval final** (keputusan default). Split shift ditulis konsisten. Notifikasi dikirim setelah commit. |
| Mesin presensi | Check-out pada presensi yang ditolak dijawab 409, sehingga presensi itu tidak bisa "hidup lagi". Tanggal bisnis shift malam = tanggal shift dimulai. Split shift yang melewati tengah malam diperlakukan sebagai overnight. |
| Koreksi & override | Koreksi segmen 1 menutup segmen yang masih terbuka. Koreksi duplikat ditolak saat pengajuan (API) dan saat approval. Race saat approval tidak lagi menghasilkan 500. Override admin tidak boleh membuat pulang sebelum masuk, memakai tanggal lain, membuat status yang bertentangan, atau menyelesaikan presensi yang masih pending. Override menandai `is_corrected`. |
| Laporan & rekap | Satu aturan hari kerja (`App\Services\ExpectedWorkDays`): jadwal shift, hari libur Holiday, hari Libur di jadwal, dan masa kerja. Presensi yang ditolak dihitung absen, bukan hadir sekaligus absen. Segmen digabung. Hari ini tidak dihitung mangkir. Status di tabel web sesuai statusnya. |
| Tim | Jadwal untuk kontrak yang sudah berakhir ditolak. Cuti tampil sebagai cuti di roster. Daftar freelancer dipaginasi penuh. Pekerja pinjaman hanya bisa dilihat. Pesan 403 berbahasa Indonesia. |
| Siklus akun | Mengakhiri kontrak magang/freelance lewat form menonaktifkan akun dan mencabut token. Job resign malam tidak menonaktifkan karyawan yang direkrut ulang, dan karyawan itu bisa mengajukan resign baru. Lembur setelah akhir kontrak ditolak. Reminder tidak dikirim ke Klien nonaktif. Upload selfie web menolak akun atau Klien nonaktif dan menolak penulisan yang gagal. |
| Lainnya | Import jadwal melaporkan kolom tanggal yang tidak terbaca. Divisi yang masih memiliki sub-divisi atau akses tidak bisa dihapus. Scope master data diperbaiki. Validasi koordinat di web. OTP tidak membocorkan email terdaftar saat provider gagal. Pesan login berbahasa Indonesia. ID OneSignal produksi hanya dipakai di build/env produksi. Form mobile menampilkan error 422 yang kuncinya tidak punya field di form. |

**Temuan perangkat:**

| Platform | Hasil |
|---|---|
| iOS Simulator (iPhone 17 Pro) | Lulus: G1 (host tampil di login dan Profil), M0 masuk/pulang, MOB-023, ATT-021 reject/approval, MOB-006 logout mencabut token, approval lewat endpoint mobile. |
| Android fisik (Realme RMX3624, Android 13) | **Bug High ditemukan dan diperbaiki:** semua unggahan file (foto absen, wajah master, liveness, lampiran) gagal dengan pesan palsu "Tidak ada koneksi internet". Penyebabnya, Expo SDK 58 memasang `expo/fetch` sebagai `fetch` global di semua build native, dan `expo/fetch` menolak deskriptor FormData `{uri}` ("Unsupported FormDataPart implementation"). Perbaikan ada di `appendFile`, plus test regresi `uploadFormData.test.ts`. Setelah perbaikan, siklus M3 lulus: GPS asli 210 km di luar area masuk approval, foto JPEG tersimpan, ATT-055 menghasilkan satu putaran, lalu approve menjadi Tepat Waktu. Crash OneSignal di Expo Go Android juga diperbaiki. |

**Dampak produksi:** build produksi kemungkinan besar ikut terdampak bug unggahan, karena runtime `expo/fetch` terpasang di semua build native. Sentry tidak mencatatnya karena error ditangkap aplikasi dan ditampilkan sebagai pesan offline. Verifikasi dengan data produksi: cari presensi mobile berfoto sejak rilis.

**Status test terakhir (lokal):**
- Backend: semua test di luar probe sesi QA lain lulus (1235/1235 sebelum sesi itu mengubah kode).
- Mobile: jest 95/95, typecheck dan lint bersih.

Sesi QA lain ("QA Jadwal Freelance dan Absensi", dokumen `QA-FREELANCE-PIC.md`) menambahkan probe `QaProbe/Probe_*`, lalu menerapkan jendela check-in 60 menit dan pencatatan "Pulang cepat" atas persetujuan pengguna. Perubahan itu membuat 32 test lama gagal; daftarnya sudah dikirim ke sesi tersebut untuk diselesaikan.

**Belum selesai atau perlu keputusan:**
- Field "Tahap approval" di Kebijakan Absensi tersimpan tetapi tidak dipakai. Perlu diputuskan: dihapus atau diimplementasikan.
- Endpoint `/devices` dan `devices.ts` tidak dipakai di mana pun.
- Koreksi malam lama yang sudah di-approve (RSK-05) masih tercatat 0 jam karena belum ada migrasi data.
- URL staging untuk EAS `preview` belum diisi.
- Uji wajah/liveness (butuh CompreFace), uji offline sungguhan, beban, dan seluruh run workbook di staging masih `Not Run`.

## 9. Bukti, triage, dan format tiket

**E0 wajib pada setiap run:** Run ID, Case ID, penguji, waktu WIB beserta offset, SHA/build, host environment, model/OS/browser, actor user ID + worker type + tenant + scope, mode dan policy snapshot, jadwal/lokasi, langkah dan nilai input aktual, expected beserta sumber, hasil aktual, request method/path/status yang disanitasi, record ID dan state sebelum/sesudah, screenshot/video/link bukti.

Tambahan concurrency: waktu mulai/selesai kedua request, jumlah record/approval step dan file pemenang. GPS: koordinat fixture, accuracy, usia fix, lokasi fisik, keputusan server. Foto: format, ukuran, object key tersanitasi, status provider; jangan unggah wajah pribadi ke tiket umum. Report: fixture hitungan independen, filter/periode, worker-day, row IDs, file export. Queue: job ID/status/penerima tanpa nomor pribadi.

```text
Judul: [Modul][Platform] tindakan + hasil salah + kondisi pemicu
Case/Run ID:
Environment + SHA/build + perangkat:
Actor/worker/tenant/scope:
Prasyarat + fixture IDs + policy/jadwal snapshot:
Langkah minimal bernomor:
Expected + sumber oracle:
Actual + dampak bisnis:
Repro: x/y percobaan pada data baru
Bukti: video, request/response tersanitasi, record before/after, correlation ID
Severity + alasan; prioritas perbaikan terpisah:
Akar masalah: belum diketahui / dugaan / terkonfirmasi
Jalur kode + log/state yang mendukung; alternatif yang sudah dieliminasi:
Duplicate/related bug ID:
Pemilik + fix SHA + regression test + retest Run ID:
```

Severity: S1 kebocoran lintas tenant/ambil alih akun/korupsi data meluas atau jalur utama mati tanpa workaround; S2 kegagalan alur inti, duplikasi transaksi, hitungan penting salah; S3 kegagalan terbatas dengan workaround; S4 kosmetik. P0/P1/P2 di katalog adalah **urutan menjalankan pengujian**, bukan severity bug otomatis. Bug hanya valid bila actual melanggar oracle yang disepakati dan build/fixture/evidence diketahui.

Hindari false positive: GPS outside pending memang fitur; annual leave Intern/Freelancer memang dilarang; izin ditolak tidak otomatis bug; HTTP 409 saat duplikat dapat benar; provider down bukan face mismatch; nama preview bukan staging; response timeout bukan rollback; fitur mobile yang memang web-only bukan bug. Catat blocker environment dan requirement gap terpisah.

## 10. Kriteria selesai dan regression

- Seluruh P0 Pass pada run yang berlaku; tidak ada P0 Not Run/Blocked atau skip tanpa keputusan terdokumentasi.
- Seluruh P1 berlaku dieksekusi; setiap Fail/Blocked mempunyai disposition pemilik produk. P2 yang ditunda memiliki alasan dan pemilik.
- Tidak ada S1/S2 terbuka. S3/S4 hanya diterima dengan dampak dan workaround yang disetujui pemilik rilis.
- Matriks 72 masuk–keluar selesai; isolasi tenant/ownership/role, approval, koreksi dan laporan mempunyai bukti.
- Ringkasan/detail/export direkonsiliasi pada fixture kontrol; tidak ada record ganda, state setengah tersimpan atau kehilangan bukti wajib.
- Setiap fix mempunyai regression test relevan dan retest lintas kanal yang terdampak. Root cause tidak wajib ditebak untuk membuka tiket, tetapi jangan menyatakan sudah terkonfirmasi tanpa bukti.
- Sign-off dicatat oleh QA lead, PIC/HR pemilik aturan bisnis, pemilik backend/mobile dan pemilik rilis. Angka Pass pada workbook sendiri bukan izin rilis.

Jangkar regression yang sudah ada, pilih sesuai perubahan (daftar ini bukan hasil test):

| Area | Test yang relevan |
|---|---|
| Mode/policy/GPS | `AttendanceModesTest`, `AttendancePolicyEnforcementTest`, `MultiLocationGeofenceTest`, `AttendanceGeofenceApprovalTest` |
| Waktu dan tipe pekerja | `AttendanceDailyFixesTest`, `SplitShiftSchedulingTest`, `AttendanceInternFreelancerTest`, `Daily/WorkersDailyJourneyTest` |
| Upload/wajah | `AndroidUploadContractTest`, `AttendancePhotoTest`, `SelfieUploadTokenTest`, `FaceVerifierTest`, `LivenessServiceTest` |
| Scope dan akun | `DataAccessTest`, `AccountDataAccessDelegationTest`, `ClientAdminApiTest`, `AccountSecurityFixesTest`, `ApiSessionTest` |
| Approval | `ApprovalConcurrencyTest`, `ApprovalIntegrityTest`, `ApprovalNotificationRecipientsTest`, `CoordinatorApprovalTest` |
| Laporan/lifecycle | `ReportExportTest`, `TeamRecapTest`, `WorkerLifecycleTest`, `ResignationAccessTest`, `AnnualLeaveEligibilityTest` |

Contoh perintah terarah setelah perbaikan, pada environment testing sesuai konfigurasi project:

```sh
php artisan test --compact tests/Feature/AttendanceModesTest.php
php artisan test --compact tests/Feature/ApprovalConcurrencyTest.php
```

Jalankan test singular untuk file yang diubah terlebih dahulu. Test lokal memakai SQLite memory/queue sync; itu tidak menggantikan concurrency dan queue real staging. Setelah test terkait lulus, tawarkan full suite `php artisan test --compact` sesuai pedoman project. Perubahan PHP wajib Pint. Untuk perubahan mobile jalankan lint, typecheck, test terkait, lalu uji perangkat nyata.

## 11. Katalog kasus eksekusi

Katalog berikut dihasilkan dari analisis source. Semua kasus belum dieksekusi di staging. Referensi `path:line` merujuk baseline commit di atas; baris dapat bergeser pada build berikutnya. Nilai boundary adalah variasi dalam satu kasus dan harus dicatat satu per satu.

<!-- GENERATED CASE CATALOG -->

### MOD-001 — M0: Tanpa GPS wajib + none

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M0.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=false; selfie_mode=none; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M0; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Biarkan izin lokasi ditolak; jangan mengirim koordinat.
4. Tanpa foto; biarkan kamera ditolak.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tidak menjadi syarat sukses. Kamera tidak menjadi syarat sukses. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-002 — M1: GPS wajib + none

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M1.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=true; selfie_mode=none; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M1; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Ambil GPS segar di dalam lokasi yang ditugaskan.
4. Tanpa foto; biarkan kamera ditolak.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tersimpan dan sesuai lokasi. Kamera tidak menjadi syarat sukses. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-003 — M2: Tanpa GPS wajib + photo

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M2.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=false; selfie_mode=photo; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M2; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Biarkan izin lokasi ditolak; jangan mengirim koordinat.
4. Ambil selfie valid baru.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tidak menjadi syarat sukses. Foto tersimpan; tidak menuntut master/face score. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-004 — M3: GPS wajib + photo

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M3.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=true; selfie_mode=photo; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M3; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Ambil GPS segar di dalam lokasi yang ditugaskan.
4. Ambil selfie valid baru.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tersimpan dan sesuai lokasi. Foto tersimpan; tidak menuntut master/face score. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-005 — M4: Tanpa GPS wajib + face

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M4.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=false; selfie_mode=face; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M4; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Biarkan izin lokasi ditolak; jangan mengirim koordinat.
4. Ambil selfie pekerja yang cocok foto master.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tidak menjadi syarat sukses. Foto/master dibandingkan; tidak meminta challenge liveness. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-006 — M5: GPS wajib + face

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M5.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=true; selfie_mode=face; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M5; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Ambil GPS segar di dalam lokasi yang ditugaskan.
4. Ambil selfie pekerja yang cocok foto master.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tersimpan dan sesuai lokasi. Foto/master dibandingkan; tidak meminta challenge liveness. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-007 — M6: Tanpa GPS wajib + liveness

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M6.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=false; selfie_mode=liveness; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M6; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Biarkan izin lokasi ditolak; jangan mengirim koordinat.
4. Ambil challenge check_in baru; selesaikan 3 pose sesuai urutan.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tidak menjadi syarat sukses. Challenge terikat user dan purpose; frame depan dipakai; retry membutuhkan challenge baru. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### MOD-008 — M7: GPS wajib + liveness

**P0 · Matriks mode absensi · Web + Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: M7.
- Prasyarat: Gate staging lengkap; gunakan akun dan tanggal bersih per Run ID; jadwal reguler 08:00–17:00; akun/profil/tenant aktif; foto master terdaftar untuk face/liveness; CompreFace sehat untuk liveness.
- Data uji: require_gps=true; selfie_mode=liveness; lokasi di pusat radius 100 m; toleransi 15 menit; check-in 08:00 WIB; check-out 17:00 WIB.

1. PIC simpan kebijakan M7; login pekerja sesuai Run ID dan muat ulang kebijakan.
2. Buka presensi; pastikan kebutuhan GPS/foto sesuai mode.
3. Ambil GPS segar di dalam lokasi yang ditugaskan.
4. Ambil challenge check_in baru; selesaikan 3 pose sesuai urutan.
5. Kirim masuk sekali; refresh riwayat dan cek dari web.
6. Pukul 17:00 ulangi persyaratan untuk keluar (challenge check_out baru untuk liveness).
7. Cocokkan detail API, riwayat, laporan dan data absensi.

**Hasil yang diharapkan:** Tepat satu attendance milik pekerja, tanggal dan shift benar; jam masuk/keluar tercatat; tidak late atau early pada data ini; status final sesuai kehadiran valid. GPS tersimpan dan sesuai lokasi. Challenge terikat user dan purpose; frame depan dipakai; retry membutuhkan challenge baru. Tidak ada pending approval tanpa alasan.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Bandingkan normalisasi policy, UI requirement, multipart payload, AttendanceCheckService, penyimpanan, dan agregasi laporan. Kegagalan satu role/platform tidak boleh tertutup keberhasilan role lain.

**Sumber:** `app/Services/CompanyPolicyService.php:179`; `tests/Feature/AttendanceModesTest.php:335`; `tests/Feature/Daily/WorkersDailyJourneyTest.php:288`

### ENV-001 — Build web dan mobile menuju environment yang sama

**P0 · Kesiapan staging · Web + Android + iOS**

- Aktor: QA + PIC. Mode: Semua.
- Prasyarat: Build kandidat tersedia; pemilik environment menyediakan identitas staging.
- Data uji: Build hash web, Android versionCode, iOS build number, fingerprint konfigurasi tanpa secret.

1. Catat identitas build dan host aktual request login/config.
2. Cocokkan dengan environment yang ditetapkan tim.
3. Gunakan record penanda khusus staging dan cek web/mobile membacanya.
4. Bila host tidak cocok, hentikan mutasi dan tandai Blocked.

**Hasil yang diharapkan:** Web/mobile/API, DB dan storage menunjuk staging yang sama. Host hard-coded bukan bukti environment; tanpa verifikasi target, run mutasi Blocked.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** API_BASE_URL konstan dan legacy override dihapus saat hydrate; build lama atau cache service worker dapat memakai host lain.

**Sumber:** `mobile/src/lib/session.ts:16`

### ENV-002 — Jam, timezone, scheduler, queue dan storage siap

**P0 · Kesiapan staging · Web + API**

- Aktor: QA + Superadmin. Mode: Semua.
- Prasyarat: Akses observability staging dan data QA terisolasi.
- Data uji: T0=hari kerja WIB; timezone server terkonfirmasi; job ID dan object path uji.

1. Catat jam server, perangkat dan timezone aplikasi.
2. Buat satu ekspor kecil dan job notifikasi ke sink uji.
3. Upload foto valid lalu lihat dari akun berizin.
4. Periksa scheduler/queue/log dengan Superadmin.

**Hasil yang diharapkan:** Waktu dan offset terdokumentasi; job selesai tanpa failed job baru; objek tersimpan dan dapat dibaca sesuai akses. Jadwal 00:15/00:20 dinilai menurut timezone scheduler aktual.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Perbedaan clock/timezone, worker mati, cache config lama, bucket URL atau kredensial storage; jangan diagnosis sebagai logika absensi sebelum gate ini lulus.

**Sumber:** `routes/console.php:10`; `config/queue.php:16`; `config/filesystems.php`

### ENV-003 — Fixture tidak mengganggu run paralel

**P1 · Data uji · Web + API**

- Aktor: QA + Admin. Mode: M0–M7.
- Prasyarat: Tenant QA dan QB tersedia.
- Data uji: Satu pekerja terpisah per mode/platform/hari; prefix record QA-<run>.

1. Reservasi akun dan tanggal tiap run.
2. Catat policy/shift/location/approval snapshot.
3. Jalankan dua run berbeda dan bandingkan record.
4. Bersihkan hanya objek berprefix run dan pulihkan policy.

**Hasil yang diharapkan:** Tidak ada overwrite policy bersama, bentrok unique attendance, atau hasil false failure karena data run lain. Seeder tidak dijalankan ulang di tengah suite.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Seeder updateOrCreate/upsert dapat menimpa fixture; attendance sehari dan per segmen mempunyai aturan uniqueness.

**Sumber:** `database/seeders/QaDailyOpsSeeder.php:188`; `tests/Feature/AttendanceModesTest.php:335`

### AUT-001 — Login sah untuk setiap persona dan akses awal

**P0 · Login dan OTP · Web + API**

- Aktor: Semua persona. Mode: N/A.
- Prasyarat: Akun aktif dan data scope dari fixture tersedia.
- Data uji: SA, PIC-A, ADM-A, KOOR-A, APR-A, BOD-A, EMP-A, INT-A, FRE-A.

1. Login tiap persona memakai kredensial yang dititipkan aman.
2. Ambil user/me dan buka landing/menu sesuai peran.
3. Logout lalu ulangi dengan password salah.

**Hasil yang diharapkan:** Login sah memberi identitas, tenant, scope dan menu yang tepat; login salah tidak memberi session/token. Akun manajemen tanpa profil pekerja tidak mendapat presensi milik orang lain.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Pemetaan role vs profil worker, guard/session dan normalisasi response user.

**Sumber:** `tests/Feature/RoleAccessAndApiTest.php`; `tests/Feature/ApiSessionTest.php`

### AUT-002 — OTP email dan WhatsApp mengikat tujuan yang benar

**P1 · Login dan OTP · Web + API**

- Aktor: Employee. Mode: N/A.
- Prasyarat: Email/WA sink uji aktif; nomor uji milik QA.
- Data uji: Dua akun A/B; variasi kapital email dan format nomor yang didukung.

1. Minta OTP A, periksa tujuan pengiriman.
2. Verifikasi challenge A dengan kode B dan sebaliknya.
3. Masukkan kode A pada challenge A.
4. Ulangi kanal lainnya.

**Hasil yang diharapkan:** Kode akun lain tidak membuka session; normalisasi tujuan konsisten; sukses hanya untuk pemilik challenge dan kode yang sesuai.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Canonical phone/email, cache challenge, purpose dan principal binding.

**Sumber:** `app/Services/ApiOtpLoginService.php:103`; `tests/Feature/ApiOtpLoginTest.php`

### AUT-003 — OTP salah, kedaluwarsa, resend dan replay

**P1 · Login dan OTP · Web + API**

- Aktor: Employee. Mode: N/A.
- Prasyarat: Sink uji; TTL dan retry_after dicatat dari respons, tidak ditebak.
- Data uji: Kode benar, salah, kode sebelum resend, challenge lewat expires_in.

1. Coba kode salah.
2. Resend setelah retry_after lalu coba kode sebelumnya.
3. Coba challenge setelah expiry.
4. Gunakan kode valid lalu replay kode/challenge.

**Hasil yang diharapkan:** Kode salah/expired/replay tidak menghasilkan token. Rotasi kode lama mengikuti kontrak kanal yang dicatat. Hanya satu konsumsi sukses; pesan dan cooldown dapat dipahami.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** OTP atomic consume/rotation, TTL cache, rate limiter; pisahkan clock perangkat dari waktu server.

**Sumber:** `app/Services/ApiOtpLoginService.php:103`; `tests/Feature/ApiOtpLoginTest.php`; `tests/Feature/EmailOtpLoginTest.php`

### AUT-004 — Respons akun tidak dikenal tidak membocorkan keberadaan akun

**P1 · Login dan OTP · Web + API**

- Aktor: Employee. Mode: N/A.
- Prasyarat: Email/nomor terdaftar dan tidak terdaftar yang sama-sama valid format.
- Data uji: Dua tujuan test; tidak memakai data pelanggan.

1. Minta OTP untuk kedua tujuan.
2. Bandingkan bentuk status publik, kode HTTP dan langkah UI.
3. Coba verifikasi akun tidak dikenal.

**Hasil yang diharapkan:** Tahap request tidak mengungkap mana akun terdaftar; tidak ada token untuk akun tak dikenal. Jangan menuntut latensi identik, ukur bila perbedaannya konsisten dan mengungkap data.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Cabang lookup user dan response/request timing; status unknown_account hanya dinilai sesuai tahap verifikasi.

**Sumber:** `app/Services/ApiOtpLoginService.php:162`; `tests/Feature/ApiOtpLoginTest.php`

### AUT-005 — Rate limit email pada batas pengiriman

**P1 · Login dan OTP · API**

- Aktor: Employee. Mode: N/A.
- Prasyarat: Akun/sink khusus; cache rate limit bersih untuk identitas ini; bukan trafik massal.
- Data uji: 3 request per 300 detik, 10 per 3600 detik, 20 per IP per 60 detik sesuai service.

1. Uji batas identitas 3 request lalu request ke-4 dalam 5 menit.
2. Dalam fixture terpisah uji batas jam dan IP tanpa mengenai limit sebelumnya.
3. Tunggu retry_after, coba lagi.

**Hasil yang diharapkan:** Request di atas limit ditolak dengan rate_limited/retry_after dan tidak mengirim pesan tambahan; setelah jendela berakhir dapat pulih. Hindari simpulan limit jam jika limit 5 menit yang sebenarnya aktif.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Rate limiter identity/IP keys, TTL dan berbagi cache antarnode.

**Sumber:** `app/Services/ApiOtpLoginService.php:162`

### AUT-006 — Provider OTP gagal tidak membuat login palsu

**P1 · Login dan OTP · Web + API**

- Aktor: Employee. Mode: N/A.
- Prasyarat: Provider diarahkan ke sink yang dapat mensimulasikan timeout/gagal.
- Data uji: Timeout, respons 500, konfigurasi kanal tidak tersedia.

1. Trigger OTP pada kondisi gagal.
2. Cek pesan, log tersanitasi dan jumlah pesan terkirim.
3. Pulihkan provider lalu ulangi request setelah cooldown.

**Hasil yang diharapkan:** Tidak ada token atau klaim terkirim yang bertentangan dengan status yang diketahui; kegagalan jelas dan recovery berhasil; log tidak berisi OTP atau secret.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Provider delivery exception, queue state dan ApiOtpLoginService delivery_failed mapping.

**Sumber:** `app/Services/ApiOtpLoginService.php:186`; `tests/Feature/ApiOtpLoginTest.php`

### AUT-007 — Reset password dan pencabutan hak memutus sesi lama

**P0 · Login dan OTP · Web + API**

- Aktor: PIC + Employee. Mode: N/A.
- Prasyarat: Akun worker login di dua perangkat dan browser.
- Data uji: Token sebelum reset; sesi sebelum perubahan role/scope.

1. PIC reset password lewat fitur resmi.
2. Panggil endpoint terlindungi dengan kedua token lama.
3. Uji sesi web lalu login dengan password baru.
4. Sempitkan scope akun admin saat session aktif, ulangi akses data lama.

**Hasil yang diharapkan:** Token mobile lama ditolak setelah reset sesuai kontrak; password lama gagal. Request setelah perubahan scope tidak lagi mengakses data di luar scope baru. Perilaku sesi web saat reset dicatat terpisah dan dicocokkan kontraknya.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Token revocation, middleware aktif, permission cache dan data scope dihitung ulang.

**Sumber:** `AKSES.md:55`; `tests/Feature/AccountSecurityFixesTest.php`; `tests/Feature/ApiSessionTest.php`

### AUT-008 — Tenant atau akun dinonaktifkan saat sesi aktif

**P0 · Login dan OTP · Web + API**

- Aktor: PIC + Employee. Mode: N/A.
- Prasyarat: Akun aktif mempunyai token/sesi, ada attendance terbuka.
- Data uji: is_active user dan tenant diuji terpisah, dipulihkan sesudah run.

1. Nonaktifkan user; panggil me, check-out dan endpoint sensitif.
2. Aktifkan user, nonaktifkan tenant; ulangi.
3. Coba login baru melalui password dan OTP.

**Hasil yang diharapkan:** Tidak ada mutasi atau akses baru dengan identitas nonaktif; UI keluar/menjelaskan alasan; status attendance lama tidak rusak.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** canSignIn, middleware route, cache user dan pengecualian route.

**Sumber:** `tests/Feature/Daily/WorkersDailyJourneyTest.php:833`; `tests/Feature/ApiSessionTest.php`

### OPS-001 — Approval terlambat terkirim dan retry tidak menggandakan hasil

**P1 · Queue dan notifikasi · Web + API**

- Aktor: Employee + Approver. Mode: Semua.
- Prasyarat: Queue uji dapat dipause oleh operator; pengajuan baru.
- Data uji: Satu request, job ID, notification ID.

1. Pause worker queue khusus staging.
2. Buat pengajuan, pastikan record pending muncul.
3. Resume worker dan catat penerima notifikasi.
4. Retry job gagal satu kali secara terkendali.

**Hasil yang diharapkan:** Pending tetap tersimpan; hanya penerima tahap aktif dalam scope yang mendapat notifikasi; tidak menggandakan approval step atau efek attendance. Duplikasi pesan transport dipisahkan dari duplikasi data bisnis.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Dispatch transaksi/after commit, recipient scoping, retry job dan idempotensi efek.

**Sumber:** `tests/Feature/ApprovalNotificationRecipientsTest.php`; `config/queue.php:16`

### OPS-002 — Reminder hanya penerima dan waktu yang layak

**P1 · Queue dan notifikasi · Web + API**

- Aktor: Admin + Employee + Approver. Mode: Semua.
- Prasyarat: Jam staging terkontrol melalui fixture/test, bukan mengubah jam semua user.
- Data uji: Pekerja sudah absen, belum absen, libur, expired, nonaktif; approval stale.

1. Jalankan siklus scheduler pada fixture.
2. Cocokkan penerima reminder dengan roster/status.
3. Jalankan siklus lagi pada window yang sama.
4. Cek tenant QB tidak menerima data QA.

**Hasil yang diharapkan:** Reminder sesuai policy dan jadwal; tidak lintas tenant dan tidak berulang di luar kontrak deduplikasi. Job tidak mengubah attendance menjadi hadir.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** AttendanceReminderService, overlap lock, recipient filter dan scheduler timezone.

**Sumber:** `routes/console.php:15`; `tests/Feature/AttendanceReminderTest.php`; `tests/Feature/ApprovalNotificationRecipientsTest.php`

### OPS-003 — Storage gagal saat upload tidak meninggalkan attendance setengah jadi

**P1 · Penyimpanan · Web + API**

- Aktor: Employee. Mode: M2/M3/M4/M5/M6/M7.
- Prasyarat: Storage fault injection terbatas pada fixture staging.
- Data uji: Foto valid, timeout/tolak tulis object.

1. Buat storage gagal menulis foto.
2. Kirim presensi.
3. Cek response, record attendance, object dan log.
4. Pulihkan storage lalu ulangi dengan input baru.

**Hasil yang diharapkan:** Tidak ada success palsu atau attendance dengan foto wajib yang hilang; recovery tidak menghasilkan duplikat. Objek sementara yang tersisa dicatat sebagai risiko cleanup terpisah.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** UploadStorageException, urutan storage/DB commit, cleanup error path.

**Sumber:** `app/Services/UploadStorageException.php`; `tests/Feature/AndroidUploadContractTest.php`; `tests/Feature/AttendancePhotoTest.php`

### OPS-004 — Lonjakan presensi pada skala operasional

**P1 · Performa · API**

- Aktor: QA + Employee. Mode: M0/M3.
- Prasyarat: Gunakan tenant/dataset QA besar; operator menyepakati batas load; monitoring aktif.
- Data uji: Usulan 10→25→50 request paralel, 1 akun unik per request; durasi 5 menit per tahap.

1. Rekam baseline request ringan.
2. Naikkan concurrency bertahap dengan ID user unik.
3. Ukur p50/p95, 5xx, DB lock, CPU, queue dan storage.
4. Rekonsiliasi jumlah sukses dengan record; hentikan bila error melonjak.

**Hasil yang diharapkan:** Usulan target untuk disetujui tim: 0 mutasi hilang/ganda, error tak terduga <1%, p95 klik/GPS ≤3 detik, foto ≤8 detik pada jaringan baseline. SLA belum disepakati tidak otomatis menjadi bug; kegagalan integritas tetap valid.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** N+1, write contention, DB pool, resize image dan object storage; pisahkan waktu jaringan, face provider, server.

**Sumber:** `database/seeders/QaDailyOpsSeeder.php:31`; `tests/Feature/DailyPerformanceTest.php`

### OPS-005 — Laporan dan roster pada dataset besar

**P2 · Performa · Web + API**

- Aktor: PIC + Koordinator. Mode: N/A.
- Prasyarat: Dataset besar QA/QB dan pagination aktif.
- Data uji: Periode 1 hari/1 bulan, filter company/division, halaman pertama/tengah/terakhir.

1. Buka roster, pending approval dan laporan.
2. Ganti filter, sort dan halaman.
3. Catat durasi dan query count melalui observability.
4. Cocokkan total terhadap query kontrol read-only.

**Hasil yang diharapkan:** Tidak timeout, data hilang atau lintas tenant; total/pagination konsisten. Usulan p95 daftar ≤3 detik dan ekspor selesai ≤60 detik harus disepakati untuk volume tercatat.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Eager loading, filters applied before pagination, job chunk size dan query indexes.

**Sumber:** `tests/Feature/DailyPerformanceTest.php`; `tests/Feature/ApprovalConcurrencyTest.php:319`; `tests/Feature/ReportExportTest.php`

### OPS-006 — Impor jadwal parsial, duplikat dan lintas tenant

**P1 · Impor dan ekspor · Web**

- Aktor: Admin. Mode: N/A.
- Prasyarat: File template dari build aktual; restore snapshot tersedia.
- Data uji: Baris valid A; worker QB; shift salah company; tanggal invalid; duplikat worker+tanggal.

1. Unduh template aktual.
2. Isi campuran baris data.
3. Import sekali, baca error per baris dan hasil record.
4. Ulangi file untuk melihat aturan duplicate/upsert.

**Hasil yang diharapkan:** Baris invalid/lintas tenant tidak mengubah data; semantik atomic/partial yang ditampilkan sesuai hasil aktual. Tidak ada sukses total saat baris gagal tanpa laporan; retry tidak menggandakan roster.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Spreadsheet parser, lookup worker scoped, row validation dan transaction boundary.

**Sumber:** `tests/Feature/ShiftScheduleSpreadsheetTest.php`; `tests/Feature/SpreadsheetImportExportTest.php`

### OPS-007 — Form gagal tetap dapat diperbaiki dan pesan terbaca

**P2 · Web dan aksesibilitas · Web**

- Aktor: Employee + PIC. Mode: Semua.
- Prasyarat: Browser Chrome dan Safari versi yang didukung tim, viewport 360/768/1440.
- Data uji: Keyboard-only, zoom 200%, form validation, halaman panjang.

1. Navigasi login/presensi/approval pakai keyboard.
2. Submit invalid lalu perbaiki field.
3. Uji viewport kecil dan zoom.
4. Cek fokus modal, tombol dan pesan error/sukses.

**Hasil yang diharapkan:** Field salah dan pesan terhubung jelas, fokus dapat kembali, tombol submit/error tidak terpotong, hasil tidak hanya dibedakan warna. Tidak kehilangan data valid saat validasi gagal.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Focus handling, overlay modal, responsive overflow dan validation error mapping.

**Sumber:** `resources/views`; `app/Filament/Concerns/HasPresensiActions.php`

### OPS-008 — Foto, token dan data pribadi tidak bocor dalam bukti teknis

**P1 · Privasi bukti · Web + Android + iOS**

- Aktor: Employee + Admin. Mode: Semua.
- Prasyarat: Perangkat QA dan akses log uji; gunakan identitas sintetis.
- Data uji: Satu error upload/login; satu crash terkontrol jika fasilitas tersedia.

1. Reproduksi error memakai data QA.
2. Periksa browser console, log server dan event Sentry terkait request.
3. Periksa payload yang masuk monitoring dan screenshot lampiran.

**Hasil yang diharapkan:** Bearer token, password, OTP, frame wajah mentah dan data pelanggan tidak tercatat tanpa kebutuhan yang disetujui. Bukti bug disanitasi namun correlation ID tetap dapat ditelusuri.

**Bukti:** E0 + respons tersanitasi + state sebelum/sesudah + ID record; tambah bukti khusus yang disebut langkah.

**Penelusuran akar masalah:** Logging interceptor, exception context, Sentry beforeSend/redaction dan upload error serialization.

**Sumber:** `mobile/src/lib/sentry.ts`; `config/logging.php`

### ROL-001 — Isolasi daftar dan detail

**P0 · Akses tenant · Web + API**

- Aktor: PIC A; PIC B. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Tenant A dengan A1/A2; Tenant B dengan B1; masing-masing berisi Employee, Intern, Freelancer.
- Data uji: ID pekerja dan pengajuan A/B dicatat.

1. Login PIC A dan buka daftar pekerja, user, absensi, pengajuan, master, laporan.
2. Cari nama unik tenant B.
3. Ganti ID detail/filter menjadi ID B melalui request yang sama.
4. Ulangi sebagai PIC B.

**Hasil yang diharapkan:** Tidak ada record/PII lintas tenant; detail tidak boleh 2xx. Client API mengembalikan 404 untuk objek di luar jangkauan; daftar kosong/terbatas.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** DataAccessService scope dan guard detail tidak konsisten.

**Sumber:** `app/Services/DataAccessService.php:150`

### ROL-002 — Arti Semua berbeda untuk platform dan klien

**P0 · Akses tenant · Web**

- Aktor: Superadmin; PIC A. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: PIC A data_scope=all; tenant B aktif.
- Data uji: Badan usaha A3 baru ditambahkan setelah PIC login.

1. PIC A lihat A1/A2.
2. Tambahkan A3 melalui flow PIC.
3. Refresh tanpa ubah akses.
4. Bandingkan Superadmin.

**Hasil yang diharapkan:** PIC A melihat A3 otomatis tetapi tidak B1; Superadmin melihat semua tenant tanpa bergantung pilihan scope form.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Cache scope tidak di-flush atau All ditafsir tanpa batas tenant.

**Sumber:** `app/Services/DataAccessService.php:165`

### ROL-003 — Akun tanpa tenant fail closed

**P0 · Akses tenant · Web + API**

- Aktor: Admin legacy tanpa tenant. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Akun legacy tenant_id=null dan perusahaan legacy tanpa tenant; A/B tetap tersedia.
- Data uji: Scope all; ID A1 dan B1.

1. Buka daftar/filters memakai akun legacy.
2. Request detail A1/B1.
3. Coba tautkan company_id A1 saat menyimpan.

**Hasil yang diharapkan:** Hanya data tanpa tenant dapat diakses sesuai role; null tidak menjadi akses platform.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Pemetaan null tenant atau intersection pilihan company bocor.

**Sumber:** `app/Services/DataAccessService.php:95`

### ROL-004 — Descendant dan sibling

**P1 · Akses divisi · Web + API**

- Aktor: Admin divisi Sales. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: A1 punya Sales > Brand > Outlet; Finance sibling.
- Data uji: Subdivisi baru Outlet2.

1. Admin lihat Brand/Outlet.
2. PIC tambah Outlet2 bawah Brand.
3. Refresh pilihan/filter.
4. Coba ID Finance.

**Hasil yang diharapkan:** Seluruh turunan ikut; Finance tetap di luar jangkauan; penambahan turunan tidak butuh ubah scope manual.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Traversal descendant atau invalidasi cache scope.

**Sumber:** `app/Services/DataAccessService.php:128`

### ROL-005 — Delegasi tidak lebih luas

**P0 · Akses divisi · Web + API**

- Aktor: Admin divisi Sales. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Admin hanya Sales; akun Employee target di Sales.
- Data uji: data_scope=all, companies=A1, divisions=Finance, divisions=Sales/Outlet.

1. Beri masing-masing payload scope ke akun target.
2. Ulangi melalui UI dan request langsung.
3. Baca ulang role/pivots target.

**Hasil yang diharapkan:** All, whole A1, Finance ditolak; Sales dan descendant yang dimiliki diterima. Tidak ada perubahan parsial pada kegagalan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Grant rules atau relationship sync melewati canGrantAccess.

**Sumber:** `app/Services/DataAccessService.php:296`

### ROL-006 — Allow-list pemberian role

**P0 · Akses akun · Web + API**

- Aktor: PIC A; Admin A1. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Akun target pekerja A1.
- Data uji: Roles Superadmin/PIC/Admin/Koordinator/Approver/custom.

1. PIC coba memberi tiap role.
2. Admin non-PIC ulangi.
3. Tamper array roles dan duplicate/campuran role legal+ilegal.

**Hasil yang diharapkan:** PIC boleh Admin/Koordinator/role pekerja; tidak boleh PIC/Superadmin/custom. Admin tidak boleh Admin/PIC/Superadmin/custom; payload campuran gagal tanpa eskalasi parsial.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Allow-list UI tidak diterapkan pada backend.

**Sumber:** `app/Filament/Concerns/ScopesToManagedCompanies.php:211`

### ROL-007 — Target punya role lebih tinggi

**P0 · Akses akun · Web + API**

- Aktor: Admin A1. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Akun target A1 memegang Employee+Admin atau custom role.
- Data uji: Target worker berada dalam A1.

1. Coba edit nama, is_active, roles.
2. Coba reset password dan link akun ke pekerja lain.
3. Bandingkan akun Employee biasa.

**Hasil yang diharapkan:** Akun dengan salah satu role di luar hak pemberian tidak dapat diubah/reset/diambil alih meski profil pekerjanya di scope.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** canManageAccount mengabaikan salah satu role target.

**Sumber:** `app/Filament/Concerns/ScopesToManagedCompanies.php:273`

### ROL-008 — Akun tanpa profil pekerja

**P0 · Akses akun · Web + API**

- Aktor: Admin company/divisi; Admin all; PIC. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: BOD tanpa employee/intern/freelancer di tenant A.
- Data uji: Admin A1; Admin Sales; Admin all.

1. Cari dan buka BOD tanpa profil.
2. Coba edit/reset sebagai masing-masing admin.
3. Ulangi sebagai PIC.

**Hasil yang diharapkan:** Admin company/divisi tidak mengelola akun tanpa profil; Admin all/PIC bisa selama role target dapat diberikan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Unlinked account scope menjadi jalan pintas akses.

**Sumber:** `app/Filament/Concerns/ScopesToManagedCompanies.php:262`

### ROL-009 — Self edit dan self takeover

**P0 · Akses akun · Web + API**

- Aktor: PIC; Admin; Koordinator. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Akun actor aktif dan memiliki record sendiri.
- Data uji: Actor ID sendiri.

1. Request update akun sendiri.
2. Reset password melalui endpoint manajemen.
3. Coba delete/ambil alih melalui action panel.

**Hasil yang diharapkan:** Aksi manajemen akun sendiri ditolak; tidak mengubah role/scope/status sendiri lewat jalur ini.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Pengecualian own record diterapkan keliru pada manajemen akun.

**Sumber:** `app/Services/ClientAdminGate.php:91`

### ROL-010 — Role tidak cukup tanpa scope

**P1 · Akses akun · Web + API**

- Aktor: Admin own; Employee; Intern; Freelancer. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Admin data_scope=own; pekerja ber-scope own.
- Data uji: Route langsung /client/overview, /client/accounts, master workers.

1. Cek menu.
2. Akses URL/API langsung.
3. Pekerja coba menaikkan scope di payload profile/request.

**Hasil yang diharapkan:** Admin own tidak punya fitur kelola; pekerja tidak menjadi manajer hanya karena payload/filter diubah.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Gate hanya berdasarkan role atau menu.

**Sumber:** `app/Services/DataAccessService.php:514`

### ROL-011 — Klien baru sampai operasional

**P1 · Onboarding · Web**

- Aktor: Superadmin; PIC baru. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Tenant QA baru belum punya perusahaan; gunakan email/nomor uji.
- Data uji: Nama klien QA, PIC unik, company code QA-A.

1. Superadmin buat Klien beserta PIC.
2. Login PIC.
3. Buat perusahaan pertama, divisi, lokasi, kebijakan, pekerja.
4. Buat akun pekerja dan login.

**Hasil yang diharapkan:** PIC mendapat Admin+PIC, scope sesuai klien, tetap dapat membuka pengaturan sebelum perusahaan pertama; pekerja/login berada pada tenant perusahaan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Provisioning tidak atomik atau PIC kosong perusahaan kehilangan akses.

**Sumber:** `app/Services/DataAccessService.php:568`

### ROL-012 — Hak membuat badan usaha per kanal

**P0 · Onboarding · Web + API**

- Aktor: PIC A; Admin A1; Superadmin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: PIC aktif; Admin non-PIC aktif.
- Data uji: Kode sama di A/B dan kode duplikat di A.

1. PIC tambah badan usaha lewat web/mobile API.
2. Admin non-PIC coba.
3. Superadmin coba POST /client/companies.
4. Uji kode sama lintas/dalam tenant.

**Hasil yang diharapkan:** PIC dapat membuat di tenant sendiri; Admin non-PIC ditolak; Superadmin membuat dari web, endpoint app ditolak; kode unik per tenant.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** ClientAdminGate/platform exception atau uniqueness global keliru.

**Sumber:** `app/Services/ClientAdminGate.php:37`

### ROL-013 — Email canonical dan credential delivery

**P1 · Onboarding · Web + API**

- Aktor: PIC; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Nomor QA gateway sink; email baru.
- Data uji: qa.user@example.test lalu spasi/case variant.

1. Buat akun melalui UI/API.
2. Ulangi email beda case/spasi.
3. Matikan gateway uji lalu buat akun baru.
4. Periksa record dan status pemberitahuan.

**Hasil yang diharapkan:** Satu akun per email canonical; kegagalan kirim terlihat tanpa klaim terkirim; retry tidak membuat akun duplikat. Password tidak masuk bukti/log QA.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Validasi unique sebelum normalisasi atau side effect setelah commit.

**Sumber:** `app/Services/ClientAccountService.php:149`

### ROL-014 — Tenant dinonaktifkan

**P0 · Lifecycle akses · Web + API**

- Aktor: PIC A; semua pengguna A; pengguna B. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Web session dan token mobile A/B sudah aktif.
- Data uji: Tenant A is_active=false.

1. Superadmin nonaktifkan A.
2. A request /auth/me, attendance, admin; coba login password/OTP.
3. B tetap akses.

**Hasil yang diharapkan:** Semua akses A ditolak termasuk token lama; B tetap berfungsi; session/token tidak membuka data A.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** canSignIn/active tenant middleware tidak konsisten.

**Sumber:** `app/Models/User.php:318`

### ROL-015 — Reset password mencabut token

**P0 · Lifecycle akses · Web + API**

- Aktor: PIC; pekerja dua perangkat. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Pekerja login dua device dan web; token disimpan secara aman.
- Data uji: Reset via web lalu ulangi API setelah relogin.

1. PIC reset password.
2. Gunakan kedua token lama untuk /auth/me dan mutation.
3. Login password lama/baru.
4. Ubah nama saja di kontrol pembanding.

**Hasil yang diharapkan:** Semua token lama ditolak; password lama gagal, baru berhasil; perubahan nama tidak mencabut token. Validasi session web sesuai mekanisme auth terpasang.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Model password update hook atau token revocation tidak terpanggil.

**Sumber:** `app/Models/User.php:138`

### ROL-016 — Akhir kontrak dan reaktivasi

**P1 · Lifecycle akses · Web + API**

- Aktor: Freelancer; Intern; Koordinator. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Freelancer/Intern aktif punya attendance historis dan jadwal esok.
- Data uji: end_date hari ini, kemarin; tanggal terjadwal sesudah akhir.

1. Akhiri kontrak.
2. Cek akun/token/jadwal mendatang dan histori.
3. Reactivate.
4. Login lagi dengan akun yang sama.

**Hasil yang diharapkan:** Akun nonaktif dan token dicabut; jadwal setelah hari akhir dibuang; histori tetap; reactivate memakai user ID sama dan menghapus end_date, tidak menghidupkan token lama.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** WorkerAccountService transaction atau scheduler lifecycle.

**Sumber:** `app/Services/WorkerAccountService.php:83`

### ROL-017 — Batas tanggal kontrak sebelum cron

**P1 · Lifecycle akses · Web + API**

- Aktor: Freelancer; Intern; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Kontrak end_date hari ini dan job penutupan belum jalan.
- Data uji: D=akhir kontrak; D+1.

1. Uji status/izin bekerja pada D.
2. Pada D+1 sebelum cron, coba presensi.
3. Jalankan jadwal penutupan pada fixture staging terisolasi melalui operator.
4. Cek hasil konsisten.

**Hasil yang diharapkan:** Sesuai kode, lewat end_date tidak boleh bekerja meski cron belum jalan; tepat hari akhir masih aktif kecuali status ended.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Guard runtime hanya bergantung job malam.

**Sumber:** `app/Services/WorkerAccountService.php:119`

### ROL-018 — Pinjam pekerja lintas brand

**P1 · Lifecycle akses · Web + API**

- Aktor: Koordinator. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: A1 punya brand X/Y, koordinator terpisah; pekerja X.
- Data uji: Jadwal pekerja X dipinjam ke outlet Y.

1. Koordinator X buat jadwal lintas outlet dalam perusahaan.
2. Koordinator Y buka roster/jadwal.
3. Y coba ubah jadwal dan profil/kontrak pekerja pinjaman.

**Hasil yang diharapkan:** Penerima melihat penempatan yang diizinkan, tetapi tidak mendapat hak ubah jadwal/profil hanya dari roster pinjaman; brand asal tetap pemilik operasi.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Scope roster dicampur dengan permission mutasi.

**Sumber:** `tests/Feature/CoordinatorAccessTest.php:175`

### ROL-019 — Shared master read/write

**P0 · Master data · Web**

- Aktor: Admin; PIC; Koordinator; Superadmin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Daftar tingkat jabatan, posisi, roles tersedia.
- Data uji: Record shared dipakai A/B.

1. Admin/PIC baca shared master.
2. Coba create/edit/delete via action langsung.
3. Koordinator buka menu langsung.
4. Superadmin lakukan pada fixture uji.

**Hasil yang diharapkan:** Shared master hanya diubah Superadmin; HR yang mengelola pekerja boleh membaca level/posisi; Koordinator tidak memperoleh pengaturan perusahaan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Permission Shield dan override resource tidak sejalan.

**Sumber:** `app/Filament/Resources/JobLevelResource.php:36`

### ROL-020 — Hierarki divisi dan delete guards

**P1 · Master data · Web + API**

- Aktor: PIC; Admin perusahaan. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Sales > Brand > Outlet; lokasi/worker/jadwal mereferensikan.
- Data uji: parent_id=self/child/foreign-company; referenced ID.

1. Coba jadikan diri/turunan sebagai parent.
2. Coba parent perusahaan lain.
3. Hapus master yang masih dipakai.
4. Periksa worker/jadwal lama.

**Hasil yang diharapkan:** Siklus dan lintas perusahaan ditolak; delete master yang dipakai ditangani jelas tanpa orphan atau data hilang tak sengaja.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Validasi parent atau relational deletion guard.

**Sumber:** `tests/Feature/ClientAdminApiTest.php:429`

### ROL-021 — Baseline flow baru

**P1 · Approval · Web + API**

- Aktor: PIC; Approver; BOD. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Perusahaan baru; flow bawaan lalu flow eksplisit Approver→BOD.
- Data uji: Pengajuan izin pekerja A.

1. Ajukan pada default.
2. Cek jumlah/role tahap.
3. Atur dua tahap untuk pengajuan berikutnya.
4. Approve tahap 1 lalu 2.

**Hasil yang diharapkan:** Default menggunakan Approver; flow dua tahap mengikuti konfigurasi; tidak wajib Superadmin. Efek bisnis terjadi setelah tahap terakhir.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Skenario UAT usang atau provisioning default flows.

**Sumber:** `app/Services/ApprovalFlowService.php:306`

### ROL-022 — Role tahap dan scope bersamaan

**P0 · Approval · Web + API**

- Aktor: Approver A1; BOD A1; Approver B1. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Flow Approver→BOD; pengajuan A1/Sales.
- Data uji: Actor scope all tenant A vs actor B.

1. BOD coba proses tahap Approver.
2. Approver luar scope coba.
3. Approver valid proses; BOD valid lanjut.

**Hasil yang diharapkan:** Role saja atau scope saja tidak cukup; hanya role tahap + worker scope dipenuhi. Actor B tidak melihat/proses A.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** isUserAuthorizedToApprove role/scope mismatch.

**Sumber:** `app/Services/ApprovalFlowService.php:580`

### ROL-023 — Override per tahap

**P1 · Approval · Web + API**

- Aktor: Admin; PIC; Superadmin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Flow tiga tahap; actor bukan requester.
- Data uji: Admin in-scope; Admin out-of-scope; Superadmin.

1. Admin in-scope proses tahap awal.
2. Periksa current_step dan efek absensi/leave.
3. Coba out-of-scope.
4. Superadmin bantu proses tahap.

**Hasil yang diharapkan:** Admin/PIC/Superadmin dapat override sesuai jangkauan, tetapi tetap per tahap; out-of-scope Admin ditolak; Superadmin tidak menjadi penerima notifikasi rutin.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Override role terlalu luas atau efek final terlalu dini.

**Sumber:** `app/Services/ApprovalFlowService.php:564`

### ROL-024 — Koordinator menyelesaikan semua tahap

**P1 · Approval · Web + API**

- Aktor: Koordinator; Koordinator+Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Flow tiga tahap; crew dalam brand koordinator.
- Data uji: Aktor Koordinator-only dan akun kombinasi Admin.

1. Koordinator-only approve tahap 1.
2. Periksa semua step/actioned_by dan status akhir.
3. Ulangi dengan Koordinator+Admin.

**Hasil yang diharapkan:** Kontrak kode: Koordinator-only menutup seluruh tahap tersisa; Koordinator+Admin tetap satu tahap. Efek bisnis/applied records tepat sekali.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** closesAllStages kombinasi role atau UI tidak menjelaskan efek.

**Sumber:** `app/Services/ApprovalFlowService.php:760`

### ROL-025 — Self approval dan tahap antara

**P0 · Approval · Web + API**

- Aktor: Admin; Koordinator; BOD; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Masing-masing actor punya profil pekerja dan pengajuan sendiri.
- Data uji: Flow dua tahap serta flow single stage.

1. Admin/Koordinator coba proses pengajuan sendiri.
2. BOD/Approver coba tahap matching non-final.
3. Coba tahap final sendiri.
4. Cari notifikasi fallback.

**Hasil yang diharapkan:** Admin/Koordinator tidak proses sendiri; BOD/Approver boleh matching intermediate sesuai kode, tetapi final sendiri ditolak dan pihak lain dapat menuntaskan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Pengecualian own request/fallback penerima.

**Sumber:** `app/Services/ApprovalFlowService.php:550`

### ROL-026 — Race approve/reject satu request

**P0 · Approval · Web + API**

- Aktor: Dua Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Kedua actor membuka current_step yang sama.
- Data uji: Dua request paralel approve+approve lalu approve+reject.

1. Sinkronkan submit pada fixture yang sama.
2. Simpan response dan waktu.
3. Periksa step, status, applied effects, notification.

**Hasil yang diharapkan:** Satu proses menang pada snapshot tahap; request stale ditolak jelas; tidak melompati tahap atau menerapkan cuti/koreksi dua kali.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** lockForProcessing/current_step optimistic snapshot.

**Sumber:** `app/Services/ApprovalFlowService.php:703`

### ROL-027 — Edit/cancel setelah proses dimulai

**P1 · Approval · Web + API**

- Aktor: Requester; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Pengajuan pending untouched vs sudah tahap pertama.
- Data uji: Reason/tanggal baru dan DELETE.

1. Edit/cancel untouched berhasil sesuai flow.
2. Buat ulang, approve tahap pertama.
3. Pemohon edit/cancel melalui UI/request lama.

**Hasil yang diharapkan:** Setelah approver menyentuh request, requester tidak dapat edit/cancel; stale submit ditolak tanpa mengubah snapshot/history.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Untouched-by-approvers guard atau race update/process.

**Sumber:** `app/Services/ApprovalFlowService.php:748`

### ROL-028 — PROBE penunjukan lama setelah mutasi tenant

**P0 · Approval · Web + API**

- Aktor: Approver ditunjuk langsung. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Flow menunjuk user A; request masih pending. Gunakan fixture sah yang pemilik/profilnya dapat dipindahkan operator.
- Data uji: Pindah worker atau approver ke tenant B setelah step tercipta.

1. Catat scope/tenant awal dan named step.
2. Lakukan mutasi yang memang didukung aplikasi.
3. Actor lama buka inbox/detail dan coba proses.
4. Bandingkan role-based step.

**Hasil yang diharapkan:** Oracle keamanan: mutasi tidak memberi akses lintas klien; jika tahap named masih memperbolehkan aktor tenant berbeda, rekam sebagai kandidat kebocoran setelah reproduksi. Jika mutasi tak didukung, tandai Blocked/N/A, bukan bug.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: direct-user branch hanya membandingkan ID tanpa revalidasi tenant aktif/current worker.

**Sumber:** `app/Services/ApprovalFlowService.php:575`

### ROL-029 — Flow tidak punya penyetuju aktif

**P1 · Approval · Web + API**

- Aktor: PIC; Approver nonaktif; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Named approver dinonaktifkan atau role scope dicabut.
- Data uji: Request pending + request baru.

1. Nonaktifkan satu-satunya approver.
2. Ajukan dan cek indikator unstaffed.
3. Jalankan reminder sesuai jadwal.
4. Admin in-scope bantu; periksa penerima.

**Hasil yang diharapkan:** Tidak ada notifikasi ke tenant lain/Superadmin rutin; status macet terlihat, fallback Admin sesuai flow; data tidak otomatis dianggap approved.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Holder resolution, disabled-user filtering, reminder routing.

**Sumber:** `app/Services/ApprovalFlowService.php:160`

### ROL-030 — Perubahan flow saat pengajuan pending

**P1 · Approval · Web + API**

- Aktor: PIC; requester; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Request R1 punya snapshot steps; PIC mengubah flow.
- Data uji: Flow lama dua tahap; baru satu tahap/berbeda role.

1. Buat R1.
2. Edit flow.
3. Buat R2.
4. Proses R1/R2.

**Hasil yang diharapkan:** R1 memakai snapshot existing steps tanpa kehilangan audit; R2 memakai konfigurasi baru; rollback perubahan tidak memproses ulang request.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Step generation vs configuration live lookup.

**Sumber:** `app/Services/ApprovalFlowService.php:485`

### ROL-031 — Eligibility dan normalisasi tipe

**P1 · Cuti · Web + API**

- Aktor: Employee; Intern; Freelancer. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Employee leave disabled/enabled; Intern/Freelancer aktif.
- Data uji: leave_type annual/annual_leave/permitted/permission sesuai nilai didukung.

1. Buka opsi UI tiap profil.
2. Kirim annual lewat API walau UI sembunyi.
3. Employee entitled submit batas kuota dan +1.

**Hasil yang diharapkan:** Intern/Freelancer serta Employee non-entitled tidak dapat annual; Employee entitled mengikuti kuota sendiri lalu perusahaan; tipe legacy dinormalisasi.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** UI-only eligibility atau mapping enum berbeda antar kanal.

**Sumber:** `app/Services/AnnualLeaveService.php:79`

### ROL-032 — Eligibility/kuota berubah sebelum final

**P1 · Cuti · Web + API**

- Aktor: Employee; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Employee enabled, sisa 3; request 3 pending dua tahap.
- Data uji: Toggle entitlement off atau kuota turun 2.

1. Approve awal.
2. HR ubah entitlement/kuota.
3. Final approve.
4. Cek status dan absence records.

**Hasil yang diharapkan:** Final approve menilai ulang; gagal jelas tanpa set approved/apply leave ketika entitlement/kuota tidak cukup.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Validasi hanya di create atau transaksi final tidak rollback.

**Sumber:** `app/Services/AnnualLeaveService.php:124`

### ROL-033 — PROBE overspend quota dua pengajuan

**P1 · Cuti · Web + API**

- Aktor: Employee; dua Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Sisa 3 hari; dua annual request masing-masing 2 hari pada tanggal berbeda, keduanya tahap final.
- Data uji: Final approval paralel pada request berbeda.

1. Catat kuota dan usedDays.
2. Approve paralel.
3. Hitung ulang sum approved days; ulangi beberapa kali.

**Hasil yang diharapkan:** Oracle integritas: maksimal satu request bisa final bila total 4 melebihi 3; kegagalan tidak menerapkan attendance. Belum dibuktikan staging.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: lock hanya per request, tidak mengunci employee/quota lintas request.

**Sumber:** `app/Services/ApprovalFlowService.php:605`

### ROL-034 — PROBE tahun pengajuan vs tahun kuota

**P1 · Cuti · Web + API**

- Aktor: Employee; HR. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Kuota tahun berjalan habis; request tahun depan; juga 31 Des→2 Jan.
- Data uji: 3 hari kalender lintas tahun.

1. Catat kuota/current year.
2. Ajukan tahun depan.
3. Uji lintas tahun lalu lihat summary per tahun.
4. Cocokkan aturan bisnis tertulis.

**Hasil yang diharapkan:** Catat perilaku aktual dan minta oracle bisnis alokasi tahun sebelum Fail; kode saat ini mengurangi used berdasarkan tahun start_date dan validasi memakai now()->year. Jangan asumsikan pembagian lintas tahun otomatis.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: AnnualLeaveService validation tidak menerima tahun request.

**Sumber:** `app/Services/AnnualLeaveService.php:44`

### ROL-035 — PROBE cuti menimpa presensi existing

**P1 · Cuti · Web + API**

- Aktor: Employee; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Hari D sudah check-in/out; alternatif split-shift dua attendance.
- Data uji: Approve leave pada D setelah attendance ada.

1. Simpan snapshot attendance tiap segmen.
2. Final approve leave.
3. Bandingkan status/timestamps/jam kerja/laporan.

**Hasil yang diharapkan:** Konflik harus mengikuti keputusan bisnis eksplisit tanpa status campuran diam-diam atau histori hilang; rekam jika leave menimpa status namun jam tetap hadir dan summary inkonsisten.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: applyLeave updateOrCreate memakai worker+date tanpa segment, hanya update status/notes.

**Sumber:** `app/Models/LeaveRequest.php:121`

### ROL-036 — Rekonsiliasi detail dan rekap

**P1 · Laporan · Web + API + ekspor**

- Aktor: Employee; Admin; PIC. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Fixture 3 worker, tanggal D1-D3: hadir, late, leave, off, absent, split shift.
- Data uji: Ground truth worker-day dan jam dihitung manual.

1. Filter tanggal/perusahaan sama di web, /laporan-absensi, team recap/export.
2. Bandingkan row IDs, hari dan jam.
3. Bedakan jumlah row vs worker-day.

**Hasil yang diharapkan:** Count dan definisi metrik konsisten; split shift satu hari hadir dengan jam semua segmen; tidak membandingkan frekuensi terlambat dengan menit.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Agregasi per-row vs per-worker-day atau berbeda datasource.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:201`

### ROL-037 — PROBE satu hadir menutupi absent pekerja lain

**P1 · Laporan · API + Web**

- Aktor: Admin A1. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: D adalah hari kerja lampau; dua pekerja dijadwalkan; A hadir, B tanpa record.
- Data uji: Report range tepat D; scope dua pekerja.

1. Ambil laporan A saja/B saja bila UI mendukung.
2. Ambil scope keduanya.
3. Bandingkan worker-day expected 1 hadir+1 absent.

**Hasil yang diharapkan:** Oracle jika summary tim: hadir 1 dan absent 1; tanggal A tidak boleh menghapus absence B. Pastikan endpoint memang dimaksud summary tim sebelum klasifikasi.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: existingDates distinct date lintas semua workers lalu satu counter weekday.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:149`

### ROL-038 — PROBE cuti melingkupi seluruh rentang

**P1 · Laporan · API + Web**

- Aktor: Employee; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Approved leave D1-D7; report D3-D5.
- Data uji: Tidak ada presensi di D3-D5.

1. Request /laporan-absensi rentang D3-D5.
2. Bandingkan kalender dan approved leave record.

**Hasil yang diharapkan:** Leave overlap seluruh rentang terhitung 3 hari dalam batas; tidak dianggap absent. Bila tidak sesuai, tangkap query-boundary evidence.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: filter hanya start/end between tanpa kasus leave membungkus report.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:122`

### ROL-039 — PROBE cuti bocor keluar batas report

**P1 · Laporan · API + Web**

- Aktor: Employee; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Approved leave D1-D7; report D5-D9.
- Data uji: Hari cuti dalam report =D5-D7 saja.

1. Request rentang D5-D9.
2. Hitung total_leave vs irisan tanggal.
3. Ulangi dua pekerja cuti pada hari sama.

**Hasil yang diharapkan:** Oracle hari cuti dibatasi irisan range; untuk summary tim unit worker-day harus ditegaskan, bukan unique tanggal lintas pekerja.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: loop full start/end tidak clip dan key hanya date.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:131`

### ROL-040 — PROBE off/holiday/custom week dianggap absent

**P1 · Laporan · API + Web**

- Aktor: Freelancer; Intern; Employee. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Jadwal Minggu kerja; Senin off; Selasa holiday; belum ada presensi.
- Data uji: Rentang tiga hari lampau.

1. Pastikan jadwal/holiday tersimpan.
2. Lihat absence pada /laporan-absensi, team recap, web.
3. Cocokkan kebijakan pekerja.

**Hasil yang diharapkan:** Oracle absensi memakai hari wajib kerja sesuai kebijakan; off/holiday tidak dianggap mangkir tanpa aturan eksplisit; Minggu kerja tidak hilang.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: unrecorded absence memakai isWeekday tanpa schedule/holiday.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:161`

### ROL-041 — PROBE approved leave menjadi absent di rekap

**P1 · Laporan · Web + mobile recap**

- Aktor: Koordinator; PIC. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Hari lampau memiliki shift non-off dan approved leave tanpa check-in.
- Data uji: Worker-day D.

1. Final approve izin.
2. Buka team recap dan export bulan itu.
3. Bandingkan leave status dan absent_days.

**Hasil yang diharapkan:** Oracle approved leave tidak menjadi mangkir; jika definisi absent mencakup leave, UI harus membedakan dan kontrak disepakati.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: TeamRecapService membaca check_in tanpa status leave dan menambah absent pada jadwal.

**Sumber:** `app/Services/TeamRecapService.php:159`

### ROL-042 — PROBE segmen pertama kosong segmen kedua hadir

**P1 · Laporan · Web + mobile recap**

- Aktor: Koordinator; PIC. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Split-shift D; attendance pertama null check_in, segmen kedua check-in/out.
- Data uji: Dua attendance dengan urutan ID terkontrol.

1. Buka recap dan export.
2. Bandingkan hadir/jam dengan detail.
3. Ulangi urutan record dibalik bila fixture memungkinkan.

**Hasil yang diharapkan:** Hari tetap hadir karena ada satu segmen hadir; jam segmen valid terjumlah; urutan ID tidak menentukan presence.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: attendance checked_in diinisialisasi hanya dari segmen pertama.

**Sumber:** `app/Services/TeamRecapService.php:107`

### ROL-043 — Ekspor mengikuti scope dan filter

**P0 · Laporan · Web + API + file**

- Aktor: PIC A; PIC B; pekerja. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: A/B punya data bernama unik; A1 punya 31+ records untuk pagination.
- Data uji: Filter A1/divisi/date/status.

1. Ekspor dari view terfilter.
2. Bandingkan semua baris, bukan halaman pertama.
3. Ubah ID/filter pada request export.
4. Buka file sebagai akun lain bila ada URL.

**Hasil yang diharapkan:** File tidak berisi tenant/divisi di luar jangkauan; semua records cocok filter dan total; path/export notification tidak bocor lintas akun.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Queued export context atau query scope hilang.

**Sumber:** `tests/Feature/ReportExportTest.php:65`

### ROL-044 — PROBE signed export sesudah tenant disable

**P0 · Laporan · Mobile browser download**

- Aktor: PIC A; Superadmin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: PIC minta /team/recap/link; link belum kedaluwarsa.
- Data uji: Tenant A dinonaktifkan setelah link terbit.

1. Ambil signed link tanpa menyalinnya ke tiket publik.
2. Superadmin nonaktifkan tenant.
3. Buka link lama di browser tanpa session.
4. Bandingkan /auth/me ditolak.

**Hasil yang diharapkan:** Oracle keamanan: tenant nonaktif tidak bisa download data baru lewat link lama; jika file tetap terunduh validasi sebagai temuan setelah reproduksi.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: download controller cek user.is_active dan canManageWorkers, bukan user.canSignIn/tenant active.

**Sumber:** `app/Http/Controllers/TeamRecapDownloadController.php:19`

### ROL-045 — Signed export expiry/tampering

**P1 · Laporan · Mobile browser download**

- Aktor: PIC; Admin. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Link recap resmi belum/baru kedaluwarsa.
- Data uji: Ubah month/user/query; scope dicabut setelah link terbit.

1. Buka link asli.
2. Ubah month/user tanpa tanda tangan baru.
3. Buka sesudah expires.
4. Cabut scope dan buka link lama.

**Hasil yang diharapkan:** Asli valid menghasilkan file tepat; tampered/expired ditolak; scope saat download dinilai ulang; tidak bergantung token browser.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Signed middleware atau permission snapshot link.

**Sumber:** `routes/web.php:52`

### ROL-046 — Kalender rekan vs private request

**P0 · Kalender · Web + API**

- Aktor: Employee; Freelancer; Intern; Approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Worker A1/Sales dan A1/Finance; B1; Intern A1.
- Data uji: Cuti pending/approved/rejected; company_id B1.

1. Employee/FL buka calendar.
2. Intern akses menu dan /kalender-cuti langsung.
3. Actor A tamper company B.

**Hasil yang diharapkan:** Employee/FL bisa lihat rekan satu perusahaan sesuai fitur; tidak B1; Intern 403. Lihat kalender bukan hak edit/detail pengajuan rekan.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Scope visibleWithColleaguesTo terlalu luas atau dipakai untuk mutation.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:21`

### ROL-047 — PROBE data sensitif kalender rekan

**P1 · Kalender · Web + API**

- Aktor: Employee; PIC. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Rekan punya alasan sakit berisi data sensitif dan master face; fixture sintetis.
- Data uji: reason dan master_face_photo.

1. Employee biasa buka calendar.
2. Inspect field response dan akses URL foto di session berbeda/anonymous.
3. Cocokkan kebutuhan produk untuk alasan/foto.

**Hasil yang diharapkan:** Penilaian privacy perlu oracle: hanya field yang disetujui bisnis boleh terbuka kepada rekan. Jangan klaim bug hanya karena API sengaja menampilkan field; bukti exposure dan kebutuhan wajib jelas.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. Perlu oracle bisnis/keamanan dan reproduksi sebelum status bug valid.

**Penelusuran akar masalah:** Hipotesis: calendar serializes reason/master_face_photo seluruh rekan perusahaan.

**Sumber:** `app/Http/Controllers/Api/V1/ReportAndCalendarApiController.php:62`

### ROL-048 — Ownership dan effective date resign

**P1 · Resign · Web + API**

- Aktor: Employee; Intern; Freelancer; approver. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Employee aktif dengan jadwal; kontraktor terpisah.
- Data uji: Pengajuan sendiri vs employee_id orang lain; effective future/today.

1. Worker submit resign own.
2. Tamper orang lain; Intern/FL akses endpoint.
3. Final approve future date.
4. Operator jalankan job saat effective date.

**Hasil yang diharapkan:** Hanya profil Employee yang sah; pengajuan orang lain ditolak kecuali manajemen berwenang; future approval tidak menonaktifkan sebelum waktunya; effective action tidak berulang merusak histori.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Ownership controller dan applyResignation/scheduled command.

**Sumber:** `tests/Feature/ResignationAccessTest.php:64`

### ROL-049 — Import scope dan identity tenant

**P0 · Import · Web import/export**

- Aktor: Admin divisi; PIC A. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: A/B memakai company code sama; Sales/Finance.
- Data uji: File 1 row legal, 1 foreign division, 1 unknown, 1 duplicate.

1. Preview/import sebagai Admin Sales dan PIC A.
2. Download row gagal.
3. Periksa tenant dan row sukses.
4. Import ulang file.

**Hasil yang diharapkan:** Kode resolve pada tenant importer; row di luar scope gagal beralasan; tidak ada record B diubah. Retry tidak menduplikasi identitas.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Importer lookup global/code collision atau scope dilewati job.

**Sumber:** `tests/Feature/KlienPicTest.php:379`

### ROL-050 — Monitor platform dan endpoint langsung

**P0 · Observabilitas · Web**

- Aktor: Superadmin; PIC; Admin; Koordinator; pekerja. Mode: Sesuai fixture / lintas kebijakan.
- Prasyarat: Semua role punya session aktif.
- Data uji: Horizon/Log Viewer dan menu roles.

1. Superadmin buka monitor.
2. Role lain buka URL langsung/history/link lama.
3. Bandingkan error/output.

**Hasil yang diharapkan:** Hanya Superadmin mengakses monitor global; role lain tidak melihat log/job/credential/PII tenant lain. Bukti cukup status dan redacted UI.

**Bukti:** Belum dijalankan. Simpan build/commit, actor+scope, fixture IDs, timestamp Asia/Jakarta, request/response tersanitasi, screenshot sebelum/sesudah dan snapshot record terkait. 

**Penelusuran akar masalah:** Global access disamakan dengan data_scope all.

**Sumber:** `app/Services/DataAccessService.php:506`

### ATT-001 — Delapan mode absensi konsisten lintas web dan mobile untuk tiga tipe pekerja

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: E/F/I aktif; masing-masing 8 mode; web check-in lalu mobile checkout, ulang arah sebaliknya dengan data bersih

1. Siapkan dan catat fixture berikut: E/F/I aktif.
2. masing-masing 8 mode; web check-in lalu mobile checkout, ulang arah sebaliknya dengan data bersih.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Satu row per segmen; policy sama di dua channel; waktu server; foto/GPS/face sesuai mode; UI riwayat sama setelah refresh

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:89`; `app/Filament/Concerns/HasPresensiActions.php:140`; `app/Http/Controllers/Api/V1/AttendanceApiController.php:51`

### ATT-002 — Absensi klik manual tanpa GPS atau kamera

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: M0; deny permission kamera+GPS; check-in/out

1. Siapkan dan catat fixture berikut: M0.
2. deny permission kamera+GPS; check-in/out.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Berhasil tanpa meminta bukti yang policy tidak wajibkan

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:486`

### ATT-003 — Bukti wajib tidak dapat dihilangkan dari request

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: M3; request tanpa latitude; ulang longitude null; ulang photo kosong

1. Siapkan dan catat fixture berikut: M3.
2. request tanpa latitude; ulang longitude null; ulang photo kosong.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 field tepat; tidak ada row/approval/file yatim; UI tidak menampilkan sukses

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:486`; `app/Services/AttendanceCheckService.php:524`

### ATT-004 — Checkout sebelum checkin ditolak tanpa record

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Belum checkin; checkout

1. Siapkan dan catat fixture berikut: Belum checkin.
2. checkout.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 belum checkin; tidak membuat record

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:308`

### ATT-005 — Submit berulang tidak mengubah absensi yang telah tersimpan

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Sudah checkin/checkout; ulang masing-masing operasi dan double tap

1. Siapkan dan catat fixture berikut: Sudah checkin/checkout.
2. ulang masing-masing operasi dan double tap.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 409 operasi berulang; original timestamp/photo tetap; tidak membuat row/approval tambahan

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:204`; `app/Services/AttendanceCheckService.php:317`

### ATT-006 — Checkin simultan ketika belum ada record

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Dua device web/mobile mengirim checkin serentak untuk row belum ada

1. Siapkan dan catat fixture berikut: Dua device web/mobile mengirim checkin serentak untuk row belum ada.
2. Dua device web/mobile mengirim checkin serentak untuk row belum ada.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Satu sukses, lainnya 409; satu row; evidence permintaan kalah dihapus

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata. Sertakan dua request ID, start/end timestamps, response keduanya, jumlah row/steps dan daftar path evidence akhir.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:239`; `database/migrations/2026_11_13_100000_add_split_shift_segments_and_talenta_code.php:26`

### ATT-007 — Checkin simultan pada record kosong hasil koreksi

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Row telah dibuat correction dengan check_in null; 2 checkin simultan

1. Siapkan dan catat fixture berikut: Row telah dibuat correction dengan check_in null.
2. 2 checkin simultan.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Hanya satu boleh mengisi; tidak last-write-wins, tidak 2 sukses; foto pemenang tetap valid

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata. Sertakan dua request ID, start/end timestamps, response keduanya, jumlah row/steps dan daftar path evidence akhir.

**Penelusuran akar masalah:** Hipotesis: unique index melindungi INSERT tetapi tidak dua UPDATE pada row existing; cari read-check-write tanpa lock/conditional update.

**Sumber:** `app/Services/AttendanceCheckService.php:269`

### ATT-008 — Checkout simultan dengan bukti berbeda

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Sudah checkin; dua checkout serentak dengan foto/GPS berbeda

1. Siapkan dan catat fixture berikut: Sudah checkin.
2. dua checkout serentak dengan foto/GPS berbeda.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Satu checkout efektif; lainnya conflict; tidak overwriting waktu/evidence atau duplikasi approval

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata. Sertakan dua request ID, start/end timestamps, response keduanya, jumlah row/steps dan daftar path evidence akhir.

**Penelusuran akar masalah:** Hipotesis: guard check_out dibaca sebelum transaksi; UPDATE tidak lockForUpdate/whereNull sehingga request terakhir dapat menimpa.

**Sumber:** `app/Services/AttendanceCheckService.php:308`

### ATT-009 — Retry setelah respons hilang tetapi transaksi telah commit

**P1 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Hilangkan response setelah server commit; user retry

1. Siapkan dan catat fixture berikut: Hilangkan response setelah server commit.
2. user retry.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Refresh mengakui sukses pertama; retry 409 ditampilkan informatif; tidak catatan kedua

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:239`

### ATT-010 — Identitas numerik sama antar tiga tabel pekerja tetap terisolasi

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Tiga worker berbeda dengan numeric id sama di tabel worker masing-masing

1. Siapkan dan catat fixture berikut: Tiga worker berbeda dengan numeric id sama di tabel worker masing-masing.
2. Tiga worker berbeda dengan numeric id sama di tabel worker masing-masing.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Record tidak bercampur; riwayat E/F/I hanya pemilik; unique berlaku terpisah

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Models/Attendance.php:174`; `app/Models/Attendance.php:210`

### ATT-011 — Akun tanpa profil atau pekerja tidak aktif ditolak

**P0 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: Akun tanpa profil; nonaktif; kontrak F/I selesai D−1

1. Siapkan dan catat fixture berikut: Akun tanpa profil.
2. nonaktif; kontrak F/I selesai D−1.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 tanpa profil atau 403 nonaktif; tidak ada evidence/row tertinggal

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:430`

### ATT-012 — Tanggal akhir kontrak dan checkout shift malam setelah kontrak

**P1 · Absensi dasar dan konkurensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0–M7 sesuai langkah.
- Prasyarat: Worker E/F/I aktif tenant A, tenant B pembanding; M0=GPSoff/none,M1=GPSon/none,M2=GPSoff/photo,M3=GPSon/photo,M4=GPSoff/face,M5=GPSon/face,M6=GPSoff/liveness,M7=GPSon/liveness; server Asia/Jakarta.
- Data uji: F/I end_date=D: checkin D, checkout D+1 overnight; ulang reactivated

1. Siapkan dan catat fixture berikut: F/I end_date=D: checkin D, checkout D+1 overnight.
2. ulang reactivated.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** D masih aktif; kasus overnight setelah end_date harus diputuskan business; tidak kehilangan jalur penyelesaian/hanya error tanpa arahan; reactivation tidak menghidupkan token dicabut

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Gap aturan bisnis: isActive menolak setelah end_date sebelum checkout; verifikasi keputusan penyelesaian shift malam pada akhir kontrak.

**Sumber:** `app/Services/WorkerAccountService.php:119`; `app/Services/AttendanceCheckService.php:310`

### ATT-013 — Batas geofence inklusif dengan pembulatan dua desimal

**P0 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Radius R=100; koordinat distance 99.99,100.00,100.01m pada data terpisah

1. Siapkan dan catat fixture berikut: Radius R=100.
2. koordinat distance 99.99,100.00,100.01m pada data terpisah.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** <=100 valid, >100 pending_approval; rounding 2 desimal terdokumentasi

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/GeofenceService.php:13`; `app/Services/GeofenceService.php:168`

### ATT-014 — HQ dan cabang aktif diterima saat tidak ada outlet jadwal

**P0 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Tanpa outlet roster; checkin HQ, A1, A2, inactive A3

1. Siapkan dan catat fixture berikut: Tanpa outlet roster.
2. checkin HQ, A1, A2, inactive A3.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** HQ/active branch valid; A3 bukan accepted location kecuali juga masuk radius lokasi aktif

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/GeofenceService.php:117`

### ATT-015 — Outlet pada jadwal membatasi geofence

**P0 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Roster A1; checkin tepat A2/HQ lalu A1

1. Siapkan dan catat fixture berikut: Roster A1.
2. checkin tepat A2/HQ lalu A1.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** A2/HQ pending walau company sama; alasan menyebut outlet roster; A1 valid

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:503`

### ATT-016 — Perubahan jadwal setelah checkin tidak memindahkan outlet checkout

**P1 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Checkin roster A1; ubah roster ke A2 sebelum checkout; checkout A2/A1

1. Siapkan dan catat fixture berikut: Checkin roster A1.
2. ubah roster ke A2 sebelum checkout; checkout A2/A1.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Checkout memakai location attendance A1; kebijakan lokasi tidak berubah diam-diam

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:321`

### ATT-017 — Outlet dinonaktifkan atau koordinat berubah di tengah shift

**P1 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Setelah checkin A1, nonaktifkan/hapus/ubah koordinat A1

1. Siapkan dan catat fixture berikut: Setelah checkin A1, nonaktifkan/hapus/ubah koordinat A1.
2. Setelah checkin A1, nonaktifkan/hapus/ubah koordinat A1.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Harus ada kebijakan eksplisit untuk checkout existing; uji foreign-key/null fallback, jangan location lain diterima tanpa audit

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Gap aturan bisnis: outlet disimpan sebagai FK, koordinat dibaca mutabel; outlet terjadwal tidak difilter is_active dalam geofence.

**Sumber:** `app/Services/GeofenceService.php:117`; `app/Services/AttendanceCheckService.php:321`

### ATT-018 — Konfigurasi GPS lama tanpa lokasi tidak meloloskan absensi bebas

**P0 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: GPS required tapi konfigurasi lama tanpa lokasi; request valid lat/lng

1. Siapkan dan catat fixture berikut: GPS required tapi konfigurasi lama tanpa lokasi.
2. request valid lat/lng.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Pending dengan alasan lokasi absen belum diatur; tidak pass bebas

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/GeofenceService.php:145`

### ATT-019 — Lokasi terakhir tidak dapat dinonaktifkan saat GPS wajib

**P1 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Coba nonaktif/hapus lokasi terakhir saat require_gps true; ulang dengan HQ/cabang lain

1. Siapkan dan catat fixture berikut: Coba nonaktif/hapus lokasi terakhir saat require_gps true.
2. ulang dengan HQ/cabang lain.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Ditolak jika tidak tersisa lokasi; diterima bila masih ada alternatif

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Http/Requests/Api/V1/ClientLocationRequest.php:56`

### ATT-020 — Validasi domain koordinat dan nilai nol

**P1 · GPS dan geofence · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Lat −90/90 dan lng −180/180; nilai di luar, string abc, null,0

1. Siapkan dan catat fixture berikut: Lat −90/90 dan lng −180/180.
2. nilai di luar, string abc, null,0.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Nilai valid termasuk 0 tetap angka; out-of-range/string ditolak API; bandingkan web method tampered inputs

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Bandingkan FormRequest API dengan typed method Livewire; guard range berbeda antar adapter dapat menyebabkan parity gap.

**Sumber:** `app/Http/Requests/Api/V1/CheckInRequest.php:27`; `app/Filament/Concerns/HasPresensiActions.php:170`

### ATT-021 — Mock location mengikuti kebijakan reject atau approval

**P0 · GPS dan geofence · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: require_gps true; is_mocked=true dengan action reject lalu approval

1. Siapkan dan catat fixture berikut: require_gps true.
2. is_mocked=true dengan action reject lalu approval.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Reject=422 tanpa record; approval=pending+flag+alasan; string "false" tidak dianggap true

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:491`; `app/Http/Requests/Api/V1/CheckInRequest.php:16`

### ATT-022 — GPS tidak wajib tetap mencatat flag mock tanpa menolak

**P1 · GPS dan geofence · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: require_gps false; is_mocked=true

1. Siapkan dan catat fixture berikut: require_gps false.
2. is_mocked=true.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Tidak ditolak lokasi, flag masih tercatat; UI tidak menuntut GPS

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:477`

### ATT-023 — Validasi accuracy dan batas perlindungan kualitas lokasi

**P1 · GPS dan geofence · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Accuracy −1,abc,0,5,5000; koordinat sama; stale GPS capture

1. Siapkan dan catat fixture berikut: Accuracy −1,abc,0,5,5000.
2. koordinat sama; stale GPS capture.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** −1/abc 422 API. Kualitas tinggi/rendah/stale perlu acceptance policy; backend saat ini tidak memakai accuracy/timestamp sehingga jangan klaim anti-spoof kuat

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Implementasi menerima accuracy>=0 tetapi AttendanceCheckService tidak memakainya; spoof flag dikirim client. Business acceptance diperlukan untuk batas akurasi/staleness.

**Sumber:** `app/Http/Requests/Api/V1/CheckInRequest.php:27`; `app/Services/AttendanceCheckService.php:466`

### ATT-024 — Tiga alasan approval digabung tanpa round ganda

**P0 · GPS dan geofence · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib kecuali GPS-10; selfie none atau face untuk GPS-12.
- Prasyarat: Tenant A; HQ, outlet A1 dan A2 aktif berjarak >200m, A3 inactive; radius100m; actor berwenang; worker E/F/I; policy GPS wajib kecuali disebut lain.
- Data uji: Mock approval + outside geofence + face mismatch approval

1. Siapkan dan catat fixture berikut: Mock approval + outside geofence + face mismatch approval.
2. Mock approval + outside geofence + face mismatch approval.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Semua 3 alasan tampil berurutan, satu attendance dan satu approval round; setelah approval status late dihitung benar

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:491`; `app/Services/AttendanceCheckService.php:554`

### ATT-025 — Batas ukuran dan validasi isi foto API dibanding web

**P0 · Foto dan face match · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: API photo valid jpeg/png/webp; 5120KB dan 5121KB; MIME palsu/nonimage/empty/corrupt

1. Siapkan dan catat fixture berikut: API photo valid jpeg/png/webp.
2. 5120KB dan 5121KB; MIME palsu/nonimage/empty/corrupt.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Valid <=limit diterima; invalid 422, tanpa file yatim; ulang web upload karena validasi berbeda

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: FormRequest API mempunyai image/MIME/size rule tetapi controller upload web hanya memeriksa transport file validity.

**Sumber:** `app/Http/Requests/Api/V1/CheckInRequest.php:35`; `app/Http/Controllers/AttendancePhotoController.php:42`

### ATT-026 — Web menolak base64 valid yang bukan gambar

**P0 · Foto dan face match · Web**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Web upload-selfie base64 nonimage yang valid base64; file teks .jpg; oversize inert image

1. Siapkan dan catat fixture berikut: Web upload-selfie base64 nonimage yang valid base64.
2. file teks .jpg; oversize inert image.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Harus ditolak sebelum token/evidence; bukti server rule error bukan sekadar preview gagal

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: base64_decode sukses dianggap bukti gambar; tidak ada image/MIME/size check dan hasil Storage::put tidak diuji false.

**Sumber:** `app/Http/Controllers/AttendancePhotoController.php:50`

### ATT-027 — Token foto salah pemilik, rusak, kedaluwarsa, dan path mentah

**P0 · Foto dan face match · Web**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Token web milik user A dikirim user B; token dimodif; path raw; expired >15min

1. Siapkan dan catat fixture berikut: Token web milik user A dikirim user B.
2. token dimodif; path raw; expired >15min.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 invalid token; file A tidak dihapus/diakses B

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Support/SelfieUploadToken.php:38`

### ATT-028 — Batas usia token dan replay tidak merusak bukti lama

**P1 · Foto dan face match · Web**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Token tepat expiry, expiry+1s; replay sesudah tengah malam/segmen lain

1. Siapkan dan catat fixture berikut: Token tepat expiry, expiry+1s.
2. replay sesudah tengah malam/segmen lain.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Batas konsisten; evidence attendance lama/master tidak terhapus oleh cleanup; freshness/reuse policy perlu dicatat

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Token terikat user dan TTL tetapi tidak sekali pakai; cleanup melindungi file terpakai. Bedakan keutuhan evidence dari requirement foto fresh.

**Sumber:** `app/Support/SelfieUploadToken.php:51`; `app/Services/AttendanceCheckService.php:104`

### ATT-029 — Kebutuhan selfie, master wajah, dan hasil mismatch

**P0 · Foto dan face match · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Face mode belum ada master; selfie kurang; mismatch threshold reject/approval

1. Siapkan dan catat fixture berikut: Face mode belum ada master.
2. selfie kurang; mismatch threshold reject/approval.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Missing selfie lebih dulu; master missing 422; mismatch reject/pending sesuai policy; tidak status verified palsu

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:524`

### ATT-030 — Batas threshold face match dan false rejection

**P1 · Foto dan face match · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Threshold score T−1,T,T+1 dengan provider fixture; real face cahaya redup/kacamata

1. Siapkan dan catat fixture berikut: Threshold score T−1,T,T+1 dengan provider fixture.
2. real face cahaya redup/kacamata.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Batas sesuai driver; false reject dicatat dengan score+provider, tidak hanya “wajah gagal”

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:618`

### ATT-031 — Kegagalan layanan wajah dan penyimpanan foto bersifat atomik

**P0 · Foto dan face match · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Face provider timeout/down/misconfigured; object store gagal pada foto kedua

1. Siapkan dan catat fixture berikut: Face provider timeout/down/misconfigured.
2. object store gagal pada foto kedua.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 503 service/storage error, tidak dianggap mismatch biasa; rollback row dan cleanup evidence; retry aman

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Http/Controllers/Api/V1/AttendanceApiController.php:62`; `app/Services/AttendanceCheckService.php:540`

### ATT-032 — Kolom foto tersembunyi Data Absensi dapat ditampilkan

**P0 · Foto dan face match · Web**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: photo / face; GPS off untuk isolasi.
- Prasyarat: Worker aktif; foto fixture inert JPEG/PNG/WebP; masterface valid; storage staging; provider test sesuai mode. Hindari data wajah pekerja nyata.
- Data uji: Admin Data Absensi buka toggle Foto Masuk/Keluar pada row berfoto

1. Login Admin/PIC yang berwenang; buka Data Absensi berisi record dengan foto masuk dan keluar.
2. Buka pengaturan kolom; aktifkan Foto Masuk lalu Foto Keluar.
3. Muat ulang tabel dan buka detail record.
4. Catat screenshot, status HTTP dan log class-not-found bila terjadi; cocokkan SHA staging dengan sumber.

**Hasil yang diharapkan:** Tabel dan image tampil tanpa 500; cek namespace StorageUrl pada log

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Observasi kode: StorageUrl::for dipanggil tanpa import App\Support\StorageUrl di resource; callback kolom hidden baru berjalan saat ditampilkan. Reproduksi build staging masih diperlukan.

**Sumber:** `app/Filament/Resources/Attendances/AttendanceResource.php:213`

### ATT-033 — Challenge liveness tiga pose dan bukti checkin checkout

**P0 · Liveness · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: liveness; GPS off/on.
- Prasyarat: Worker aktif dengan masterface dan mode liveness; provider pose tersedia; default TTL180s, front yaw<=15°, side abs(yaw)>=20°, pitch abs<=25°; capture fixture disetujui.
- Data uji: M6/M7 provider support pose; jalankan urutan acak 3 frame

1. Siapkan dan catat fixture berikut: M6/M7 provider support pose.
2. jalankan urutan acak 3 frame.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Liveness true; front dipakai selfie; tepat 3 frame tersimpan sesuai purpose; check-in/out keduanya diverifikasi

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/LivenessService.php:51`; `app/Services/AttendanceCheckService.php:575`

### ATT-034 — Challenge salah user, purpose, jumlah, atau replay ditolak

**P0 · Liveness · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: liveness; GPS off/on.
- Prasyarat: Worker aktif dengan masterface dan mode liveness; provider pose tersedia; default TTL180s, front yaw<=15°, side abs(yaw)>=20°, pitch abs<=25°; capture fixture disetujui.
- Data uji: Challenge user/purpose salah, expired, replay, count 2/4

1. Siapkan dan catat fixture berikut: Challenge user/purpose salah, expired, replay, count 2/4.
2. Challenge user/purpose salah, expired, replay, count 2/4.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 tanpa attendance; challenge failed perlu diterbitkan ulang; tidak menerima checkin challenge untuk checkout

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/LivenessService.php:92`; `app/Services/LivenessService.php:162`

### ATT-035 — Validasi pose, satu wajah, dan arah sisi berlawanan

**P0 · Liveness · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: liveness; GPS off/on.
- Prasyarat: Worker aktif dengan masterface dan mode liveness; provider pose tersedia; default TTL180s, front yaw<=15°, side abs(yaw)>=20°, pitch abs<=25°; capture fixture disetujui.
- Data uji: Side arah sama; frame blank; >1 wajah; front miring; pitch ekstrem

1. Siapkan dan catat fixture berikut: Side arah sama.
2. frame blank; >1 wajah; front miring; pitch ekstrem.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 422 liveness; gambar attempt gagal dihapus; pose mirrored benar tidak false reject

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/LivenessService.php:109`; `app/Services/LivenessService.php:171`

### ATT-036 — Batas anti-replay dan identitas frame sisi pada liveness

**P0 · Liveness · Web + API**

- Aktor: Employee / Intern / Freelancer. Mode: liveness; GPS off/on.
- Prasyarat: Worker aktif dengan masterface dan mode liveness; provider pose tersedia; default TTL180s, front yaw<=15°, side abs(yaw)>=20°, pitch abs<=25°; capture fixture disetujui.
- Data uji: Replay 3 foto tersimpan yang memenuhi pose atau front A+side B di staging terisolasi

1. Siapkan dan catat fixture berikut: Replay 3 foto tersimpan yang memenuhi pose atau front A+side B di staging terisolasi.
2. Replay 3 foto tersimpan yang memenuhi pose atau front A+side B di staging terisolasi.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Uji batas klaim anti-spoof: backend sekarang pose-check + front identity, tidak bukti capture freshness atau identity semua frame. Laporkan gap jika requirement mensyaratkan anti-replay

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Gap threat model: liveness mengecek pose setiap frame dan identity front saja; tidak ada bukti capture freshness. Jangan klaim bug tanpa acceptance anti-replay.

**Sumber:** `app/Services/LivenessService.php:109`; `app/Services/LivenessService.php:138`

### ATT-037 — Perhitungan terlambat pada batas toleransi sampai detik

**P0 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Shift P; checkin start−1m,start,start+15m,start+15m1s,start+16m

1. Siapkan dan catat fixture berikut: Shift P.
2. checkin start−1m,start,start+15m,start+15m1s,start+16m.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** on_time sampai 08:15:00; setelahnya late penuh dari start (15/16), bukan hanya lewat toleransi

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/WorkScheduleService.php:104`

### ATT-038 — Prioritas roster, shift default, policy, dan hari libur

**P0 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Default shift A, dated roster B, policy C berbeda; kemudian day off

1. Siapkan dan catat fixture berikut: Default shift A, dated roster B, policy C berbeda.
2. kemudian day off.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Prioritas B>A>C; dayoff tidak late. Konfirmasi kehadiran dayoff diterima menurut business

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/WorkScheduleService.php:29`

### ATT-039 — Shift malam tetap menggunakan attendance tanggal masuk

**P0 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Shift N checkin D22:00; buka Today D+1 00:01; coba checkin baru lalu checkout 06:00

1. Siapkan dan catat fixture berikut: Shift N checkin D22:00.
2. buka Today D+1 00:01; coba checkin baru lalu checkout 06:00.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Today menawarkan checkout D; baru ditolak409; checkout mengubah D dan worked_minutes=480

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:197`; `app/Services/AttendanceCheckService.php:367`

### ATT-040 — Batas penyelesaian attendance kemarin tepat 20 jam

**P1 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Open yesterday checkin tepat now−20h dan now−20h−1s

1. Siapkan dan catat fixture berikut: Open yesterday checkin tepat now−20h dan now−20h−1s.
2. Open yesterday checkin tepat now−20h dan now−20h−1s.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Tepat20h masih eligible; lewat window tidak eligible, UI beri jalur koreksi; tidak menempel row salah

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:397`

### ATT-041 — Lupa checkout shift siang tidak memblokir hari berikutnya

**P1 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Day shift lupa checkout kemarin; sebelum checkin hari ini checkout; reset lalu checkin hari ini

1. Siapkan dan catat fixture berikut: Day shift lupa checkout kemarin.
2. sebelum checkin hari ini checkout; reset lalu checkin hari ini.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Within20h checkout kemarin boleh; checkin hari ini tidak diblokir; setelah today open checkout untuk today

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:369`

### ATT-042 — Segmen kedua dibuka tepat satu jam sebelum mulai

**P0 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Split S; checkin/out seg1, coba seg2 16:44:59 lalu16:45:00

1. Siapkan dan catat fixture berikut: Split S.
2. checkin/out seg1, coba seg2 16:44:59 lalu16:45:00.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Sebelum buka422, tepat buka sukses; seg1 harus closed; satu row segmen2; late dihitung dari17:45

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:204`

### ATT-043 — Segmen kedua tidak mendahului penutupan pertama dan tidak ada segmen ketiga

**P1 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Split seg1 belum checkout; langsung checkin seg2; setelah seg2 closed coba seg3

1. Siapkan dan catat fixture berikut: Split seg1 belum checkout.
2. langsung checkin seg2; setelah seg2 closed coba seg3.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** 409; maksimal2, tidak ada auto-close/row ketiga

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:208`

### ATT-044 — Approval sesudah perubahan roster menjaga makna histori keterlambatan

**P0 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Shift/policy/roster diedit setelah checkin pending sebelum approve

1. Siapkan dan catat fixture berikut: Shift/policy/roster diedit setelah checkin pending sebelum approve.
2. Shift/policy/roster diedit setelah checkin pending sebelum approve.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Rekam oracle bisnis historis; lateness tidak berubah tanpa audit yang sah; kode menghitung dari roster terbaru -> reproduce perubahan

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: applyGeofenceApproval menghitung ulang memakai roster saat approval, bukan snapshot jam saat checkin.

**Sumber:** `app/Models/Attendance.php:134`

### ATT-045 — Checkin shift malam pertama setelah tengah malam

**P1 · Shift dan waktu · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer. Mode: M0 untuk isolasi waktu; ulang mode produksi.
- Prasyarat: D=tanggal fixture; server Asia/Jakarta. P08:00–17:00 toleransi15; N22:00–06:00; S07:45–13:00+17:45–22:00 toleransi15. Gunakan fixture/control clock terisolasi, bukan ubah clock shared.
- Data uji: Shift malam checkin terlambat setelah midnight tanpa checkin D

1. Siapkan dan catat fixture berikut: Shift malam checkin terlambat setelah midnight tanpa checkin D.
2. Shift malam checkin terlambat setelah midnight tanpa checkin D.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Validasi business date yang seharusnya; kode memakai today dan start time pada same calendar date, berpotensi salah day/late

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Gap business date: checkin memakai today, lateMinutes menempel start ke tanggal checkin. Putuskan shift malam yang mulai kemarin saat belum checkin.

**Sumber:** `app/Services/AttendanceCheckService.php:202`; `app/Services/WorkScheduleService.php:110`

### ATT-046 — Koreksi tiga tipe pekerja diterapkan setelah approval final

**P0 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: E/F/I pengajuan correction D lalu Approver/BOD proses sampai final

1. Siapkan dan catat fixture berikut: E/F/I pengajuan correction D lalu Approver/BOD proses sampai final.
2. E/F/I pengajuan correction D lalu Approver/BOD proses sampai final.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Sebelum final attendance tetap; final tepat worker/date/segment, is_corrected=true, late dihitung; satu row

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Models/AttendanceCorrection.php:91`; `app/Services/ApprovalFlowService.php:655`

### ATT-047 — Koreksi kosong, waktu rusak, alasan, dan tanggal masa depan

**P0 · Koreksi dan override manual · Web + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Correction kedua waktu kosong; invalid "abc"/25:61; tanggal future; reason kosong/1001char

1. Siapkan dan catat fixture berikut: Correction kedua waktu kosong.
2. invalid "abc"/25:61; tanggal future; reason kosong/1001char.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Invalid time/empty correction harus validasi422 bukan500/attendance kosong; future sesuai aturan tertulis; reason enforce

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: kedua waktu nullable string, tanpa format atau minimal satu waktu; cast dapat exception atau menghasilkan koreksi tanpa efek.

**Sumber:** `app/Http/Requests/Api/V1/StoreAttendanceCorrectionRequest.php:14`

### ATT-048 — Koreksi shift malam mempertahankan tanggal checkout berikutnya

**P0 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Koreksi shift N D22:00→D+1 06:00; approve; bandingkan worked minutes

1. Siapkan shift malam D22:00 sampai D+1 06:00 untuk worker aktif.
2. Ajukan koreksi tanggal D dengan waktu masuk22:00 dan keluar06:00.
3. Approver berwenang selesaikan semua tahap.
4. Periksa timestamp penuh checkout, worked_minutes dan rekap; bandingkan dengan durasi480menit.
5. Ulang E/F/I serta web/mobile.

**Hasil yang diharapkan:** Harus8jam dan tanggal checkout D+1; model saat ini anchor kedua waktu keD, potensi0 jam

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: saving hook menempel corrected_check_in dan corrected_check_out ke tanggal yang sama; durasi negatif di-clamp menjadi nol.

**Sumber:** `app/Models/AttendanceCorrection.php:32`; `app/Models/Attendance.php:125`

### ATT-049 — Koreksi split shift pada batas titik tengah istirahat

**P1 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Split S midpoint15:22:30: corrected time sebelum/tepat/sesudah; koreksi seg1 checkout terlambat melewati midpoint

1. Siapkan dan catat fixture berikut: Split S midpoint15:22:30: corrected time sebelum/tepat/sesudah.
2. koreksi seg1 checkout terlambat melewati midpoint.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Jangan mengubah segmen salah; dokumentasi implicit inference perlu dibandingkan pilihan UI dan business

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: segmen dipilih implisit dari waktu terhadap midpoint, bukan attendance_id/segment eksplisit; late checkout segmen1 bisa salah target.

**Sumber:** `app/Models/AttendanceCorrection.php:141`

### ATT-050 — Edit atau batal koreksi melawan approval yang bersamaan

**P0 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Correction pending edit/cancel sebelum action; ulang sesudah intermediate approval; request bersamaan approve

1. Siapkan dan catat fixture berikut: Correction pending edit/cancel sebelum action.
2. ulang sesudah intermediate approval; request bersamaan approve.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Edit/cancel hanya untouched; locking cegah stale update; waktu/approval tidak separuh berubah

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Http/Controllers/Api/V1/AttendanceCorrectionApiController.php:108`

### ATT-051 — Dua koreksi berbeda untuk attendance yang sama

**P0 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Dua correction untuk worker/date/segment sama pending; approve bersamaan

1. Siapkan dan catat fixture berikut: Dua correction untuk worker/date/segment sama pending.
2. approve bersamaan.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Hasil deterministik, tidak silently overwrite correction lain; histori jelas; unique violation bukan500

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata. Sertakan dua request ID, start/end timestamps, response keduanya, jumlah row/steps dan daftar path evidence akhir.

**Penelusuran akar masalah:** Hipotesis: request correction berbeda dikunci terpisah tetapi attendance target firstOrNew/save tanpa lock target; last-write-wins atau unique conflict.

**Sumber:** `app/Models/AttendanceCorrection.php:106`

### ATT-052 — Lampiran koreksi tidak tertinggal setelah gagal atau batal

**P1 · Koreksi dan override manual · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Upload attachment lalu no approval flow; ganti attachment berulang; cancel

1. Siapkan dan catat fixture berikut: Upload attachment lalu no approval flow.
2. ganti attachment berulang; cancel.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Request gagal atomik dan storage cleanup; tidak file yatim. Periksa jumlah file sebelum/sesudah

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: file dibuat sebelum transaksi, update/cancel tidak membersihkan attachment lama atau file transaksi gagal.

**Sumber:** `app/Http/Controllers/Api/V1/AttendanceCorrectionApiController.php:71`

### ATT-053 — Override admin menjaga integritas waktu, status, dan histori

**P0 · Koreksi dan override manual · Web**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: Koreksi manual / Override; mode policy tetap dicatat.
- Prasyarat: Worker aktif E/F/I; tanggal D dan attendance diketahui; approval flow bertahap aktif; shift P08:00–17:00 atau N22:00–06:00/S07:45–13:00+17:45–22:00 sesuai kasus.
- Data uji: Admin Override checkout<checkin, date berbeda attendance.date, late/status kontradiktif, pending record

1. Siapkan dan catat fixture berikut: Admin Override checkout<checkin, date berbeda attendance.date, late/status kontradiktif, pending record.
2. Admin Override checkout<checkin, date berbeda attendance.date, late/status kontradiktif, pending record.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Validasi menjaga integritas; alasan/audit/history; tidak bypass approval tanpa audit; report konsisten

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Hipotesis: form Override tidak memvalidasi urutan waktu/date/status equivalence dan tidak otomatis menandai is_corrected; periksa policy audit.

**Sumber:** `app/Filament/Resources/Attendances/AttendanceResource.php:63`

### ATT-054 — Checkout di luar area membuka round approval baru setelah final

**P0 · Approval absensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: GPS atau face gagal dengan action approval.
- Prasyarat: Worker tenant A; geofence policy; Approver dan BOD berurutan, Admin/PIC berwenang; request type geofence; data attendance baru per run.
- Data uji: Outside checkin pending; approve final; later outside checkout

1. Siapkan dan catat fixture berikut: Outside checkin pending.
2. approve final; later outside checkout.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Round2 dibuat, nomor step lanjut, history ronde1 tetap; status kembali pending

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/AttendanceCheckService.php:340`; `app/Services/ApprovalFlowService.php:478`

### ATT-055 — Checkout di luar area memakai round checkin yang masih pending

**P0 · Approval absensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: GPS atau face gagal dengan action approval.
- Prasyarat: Worker tenant A; geofence policy; Approver dan BOD berurutan, Admin/PIC berwenang; request type geofence; data attendance baru per run.
- Data uji: Outside checkin masih pending; outside checkout

1. Siapkan dan catat fixture berikut: Outside checkin masih pending.
2. outside checkout.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Round sama digunakan, tidak menggandakan steps/notif; evidence kedua ada

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Models/Attendance.php:155`

### ATT-056 — Approve dan reject simultan hanya menghasilkan satu keputusan

**P0 · Approval absensi · Web + Android + iOS + API**

- Aktor: Employee / Intern / Freelancer; Approver / BOD / Admin / Koordinator. Mode: GPS atau face gagal dengan action approval.
- Prasyarat: Worker tenant A; geofence policy; Approver dan BOD berurutan, Admin/PIC berwenang; request type geofence; data attendance baru per run.
- Data uji: Dua approver approve vs reject simultan dari page yang sama

1. Siapkan dan catat fixture berikut: Dua approver approve vs reject simultan dari page yang sama.
2. Dua approver approve vs reject simultan dari page yang sama.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Satu keputusan valid; lainnya stale error; actor/time reason konsisten, tidak double notif

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata. Sertakan dua request ID, start/end timestamps, response keduanya, jumlah row/steps dan daftar path evidence akhir.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Services/ApprovalFlowService.php:603`; `app/Services/ApprovalFlowService.php:703`

### ATT-057 — Rekap split shift, malam, pending, rejected, dan corrected

**P0 · Rekap absensi · Web + API**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: Semua mode pada dataset rekap.
- Prasyarat: Fixture E/F/I dengan ID numerik bertabrakan; single, split S07:45–13:00+17:45–22:00, overnight22:00–06:00; known pending/rejected/approved/corrected rows.
- Data uji: Split S lengkap+single+overnight+pending+rejected+corrected

1. Siapkan dan catat fixture berikut: Split S lengkap+single+overnight+pending+rejected+corrected.
2. Split S lengkap+single+overnight+pending+rejected+corrected.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Split satu hari masuk, jam hanya sum dua segmen tanpa break; status pending/rejected bukan payable tanpa acceptance; cocok detail vsrekap vsAPI

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Exports/AttendanceRecapSheet.php:84`; `tests/Feature/SplitShiftSchedulingTest.php:477`

### ATT-058 — Rekap tidak menyatukan tipe pekerja atau menggandakan hari

**P1 · Rekap absensi · Web + API**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: Semua mode pada dataset rekap.
- Prasyarat: Fixture E/F/I dengan ID numerik bertabrakan; single, split S07:45–13:00+17:45–22:00, overnight22:00–06:00; known pending/rejected/approved/corrected rows.
- Data uji: Worker E/F/I numeric ID sama; late cuma seg2; seg2 tidak checkout

1. Siapkan dan catat fixture berikut: Worker E/F/I numeric ID sama.
2. late cuma seg2; seg2 tidak checkout.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Grup worker tidak tabrakan; late day1 bukan2; missing end bukan0 palsu; null jelas

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Exports/AttendanceRecapSheet.php:84`

### ATT-059 — Filter tanggal, status, dan pagination tetap konsisten

**P1 · Rekap absensi · Web + API**

- Aktor: Superadmin / Admin / Koordinator (sesuai lingkup data). Mode: Semua mode pada dataset rekap.
- Prasyarat: Fixture E/F/I dengan ID numerik bertabrakan; single, split S07:45–13:00+17:45–22:00, overnight22:00–06:00; known pending/rejected/approved/corrected rows.
- Data uji: Pagination >20; tanggal boundary akhirbulan/year/leapday; filter one date dan reversedrange

1. Siapkan dan catat fixture berikut: Pagination >20.
2. tanggal boundary akhirbulan/year/leapday; filter one date dan reversedrange.
3. Jalankan setiap variasi menggunakan worker/tanggal bersih; catat request dan respons serta ID record.
4. Muat ulang riwayat/detail dan cocokkan record, evidence, approval steps, serta rekap yang relevan dengan oracle.

**Hasil yang diharapkan:** Row tidak hilang/duplikasi; validasi invalidfilter; total/filter consistent semuachannel

**Bukti:** Screenshot/video; build/SHA dan policy+roster snapshot; timestamp WIB/UTC; request-response tersanitasi; attendance/correction ID; worker+date+segment; state sebelum/sesudah; log backend terkait. Jangan sertakan token atau foto personal nyata.

**Penelusuran akar masalah:** Jika gagal: isolasi adapter UI/API → validasi → AttendanceCheckService/WorkScheduleService → transaksi/database → provider/storage; bandingkan source dan snapshot policy/roster. Ini petunjuk investigasi, bukan bug terkonfirmasi.

**Sumber:** `app/Http/Controllers/Api/V1/AttendanceApiController.php:126`

### MOB-001 — Verifikasi build benar-benar staging

**P0 · Build dan isolasi · Android + iOS**

- Aktor: Semua peran. Mode: Semua.
- Prasyarat: APK/IPA internal tersedia; host staging disetujui pemilik lingkungan.
- Data uji: Build number, commit backend/mobile, host staging aktual.

1. Instal build internal bersih.
2. Tangkap host request login sebelum mengirim kredensial.
3. Cocokkan dengan host staging yang ditetapkan.
4. Periksa build preview dan production sebagai konfigurasi terpisah tanpa membuat data.

**Hasil yang diharapkan:** Gate: jangan lanjut mutasi bila host belum terverifikasi sebagai staging. Kode saat ini memakai API_BASE_URL konstan; nama profil preview bukan bukti target staging.

**Bukti:** Build metadata dan host request tanpa token/kata sandi.

**Penelusuran akar masalah:** API_BASE_URL hard-coded; eas.json preview tidak memberi override API.

**Sumber:** `mobile/src/lib/session.ts:16`; `mobile/eas.json:16`

### MOB-002 — Cold start dengan sesi valid

**P1 · Sesi · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Login password.
- Prasyarat: Akun aktif sudah login.
- Data uji: Sesi A valid.

1. Login A.
2. Force-close.
3. Buka ulang dengan jaringan normal.
4. Buka profil dan presensi.

**Hasil yang diharapkan:** Sesi dipulihkan; /auth/me menyegarkan profil; tidak meminta login ulang atau menampilkan akun lain.

**Bukti:** Video cold start; /auth/me; user_id tersamarkan.

**Penelusuran akar masalah:** Hydrate SecureStore memerlukan token dan user; refresh profil di AppTabsLayout.

**Sumber:** `mobile/src/lib/session.ts:61`; `mobile/src/app/(app)/_layout.tsx:29`

### MOB-003 — Isolasi cache setelah logout

**P0 · Sesi · Android + iOS**

- Aktor: Akun pekerja A lalu PIC B. Mode: Ganti akun.
- Prasyarat: A dan B punya klien berbeda; A punya riwayat/foto/pengajuan.
- Data uji: Dua akun QA lintas klien.

1. Login A dan buka semua daftar/detail.
2. Logout lalu langsung login B.
3. Kembali ke riwayat, approval, profil, dan detail melalui recent navigation.

**Hasil yang diharapkan:** Tidak ada data/foto A tampil pada B, termasuk frame singkat; data backend B tetap mengikuti scope.

**Bukti:** Rekaman transisi; response user_id; request detail yang ditolak.

**Penelusuran akar masalah:** Query keys tidak mengandung user_id; isolasi bergantung queryClient.clear saat signedOut.

**Sumber:** `mobile/src/lib/queryClient.ts:23`; `mobile/src/app/_layout.tsx:57`

### MOB-004 — Respons lama datang setelah logout

**P0 · Sesi · Android + iOS**

- Aktor: Akun A lalu B. Mode: Request terlambat.
- Prasyarat: Proxy staging bisa menunda respons /auth/me A; dua akun QA tersedia.
- Data uji: Delay /auth/me A 10–20 detik.

1. Tahan response /auth/me A.
2. Logout A dan login B.
3. Lepaskan response A.
4. Periksa profil, role, dan sesi lalu restart.

**Hasil yang diharapkan:** Respons milik A tidak boleh mengganti user/profil B. Jika berubah, buktikan pasangan token–user dan request berikutnya; jangan simpan token mentah.

**Bukti:** Timeline request/response, video profil B sebelum/sesudah.

**Penelusuran akar masalah:** useRefreshUser.onSuccess memanggil setUser tanpa memeriksa identitas sesi yang memulai request.

**Sumber:** `mobile/src/hooks/useRefreshUser.ts:9`; `mobile/src/lib/session.ts:84`

### MOB-005 — Token dicabut di server

**P1 · Sesi · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: 401 serentak.
- Prasyarat: Login valid; dapat cabut token QA melalui admin.
- Data uji: Token QA dicabut; minimal dua GET aktif.

1. Cabut token.
2. Buka layar yang memuat beberapa GET.
3. Amati 401 serentak.
4. Login ulang.

**Hasil yang diharapkan:** Satu perpindahan konsisten ke login; tidak loop logout, tidak crash, cache dibersihkan; login baru bekerja.

**Bukti:** Video dan status 401 tanpa Authorization.

**Penelusuran akar masalah:** onUnauthenticated global; signOut idempotent berdasarkan status.

**Sumber:** `mobile/src/lib/api/client.ts:210`; `mobile/src/lib/session.ts:89`

### MOB-006 — Logout lokal dan token server

**P1 · Sesi · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Logout offline.
- Prasyarat: Login valid sebelum airplane mode.
- Data uji: Akun A dengan push aktif.

1. Matikan internet.
2. Logout.
3. Force-close dan buka ulang.
4. Hidupkan internet dan uji status token lama memakai alat QA aman.

**Hasil yang diharapkan:** Logout lokal segera dan tetap signedOut setelah restart. Catat terpisah bahwa kode hanya mencoba revoke di background; validitas token lama harus dinilai terhadap kebijakan keamanan yang disepakati, bukan diasumsikan tercabut.

**Bukti:** Video restart, waktu logout, hasil validasi token tanpa nilai token.

**Penelusuran akar masalah:** Revocation 10 detik best-effort ditelan saat gagal; tidak ada antrean revoke persisten di session.ts.

**Sumber:** `mobile/src/lib/session.ts:96`; `mobile/src/lib/api/auth.ts:57`

### MOB-007 — Matriks menu berdasarkan profil dan permission

**P1 · Akses · Android + iOS**

- Aktor: Superadmin/PIC/Admin dengan dan tanpa profil; Employee/Intern/Freelancer. Mode: Role dan profil.
- Prasyarat: Akun fixtures per role tersedia, termasuk PIC tanpa worker profile.
- Data uji: /auth/me roles, permissions, access, employee/intern/freelancer.

1. Login setiap fixture.
2. Cocokkan Beranda, Tim, Riwayat, Pengajuan, Approval, Kelola Klien.
3. Uji fitur melalui deep link langsung juga.

**Hasil yang diharapkan:** Menu sesuai deriveAccess: presensi membutuhkan profil; PIC berdasarkan access.client_admin; Tim mengikuti server; Intern/Freelancer tidak punya annual leave; izin backend tetap membatasi deep link.

**Bukti:** Screenshot menu tiap akun; /auth/me tersamarkan; HTTP denied.

**Penelusuran akar masalah:** Perbedaan role, profile, dan capability server; semua route root hanya guarded signedIn.

**Sumber:** `mobile/src/lib/access.ts:62`; `mobile/src/app/_layout.tsx:112`

### MOB-008 — Cabut akses saat aplikasi aktif

**P1 · Akses · Android + iOS**

- Aktor: PIC/Admin/Approver dengan worker profile. Mode: Permission berubah.
- Prasyarat: Akun sedang di layar approval/client management.
- Data uji: Cabut permission atau ganti scope di web.

1. Buka form di mobile.
2. Cabut hak dari web.
3. Submit form lama.
4. Refresh profil dan buka ulang menu.

**Hasil yang diharapkan:** Server menolak akses yang dicabut; tidak ada mutasi. Setelah refresh menu selaras. Ketidakhadiran auto-refresh pada resume dicatat sebagai usability sesuai acceptance yang disepakati.

**Bukti:** 403/404, state sebelum/sesudah, /auth/me baru.

**Penelusuran akar masalah:** Access tersimpan lokal; refresh mount dan manual, bukan listener global setiap AppState active.

**Sumber:** `mobile/src/app/(app)/_layout.tsx:29`; `mobile/src/hooks/useRefreshUser.ts:6`

### MOB-009 — Izin kamera ditolak sekali

**P1 · Kamera · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo dan face.
- Prasyarat: Instal bersih; policy photo atau face dengan master valid.
- Data uji: Dialog izin kamera sistem.

1. Buka absen.
2. Tolak izin sekali.
3. Pilih Izinkan kamera bila tersedia.
4. Izinkan dan ambil foto.

**Hasil yang diharapkan:** Tidak crash; pesan Indonesia dan tombol pemulihan sesuai canAskAgain; submit belum bisa tanpa foto.

**Bukti:** Video dialog, status izin, screenshot tombol.

**Penelusuran akar masalah:** useCameraPermissions dan canAskAgain mengatur cabang pemulihan.

**Sumber:** `mobile/src/app/attendance/check.tsx:162`; `mobile/src/app/attendance/check.tsx:367`

### MOB-010 — Izin diblokir permanen lalu dipulihkan

**P1 · Kamera · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo dan liveness.
- Prasyarat: Izin kamera permanently denied melalui Settings.
- Data uji: Deny camera, kemudian allow dari Settings.

1. Buka absen.
2. Buka pengaturan dari aplikasi.
3. Izinkan kamera.
4. Kembali ke form tanpa restart.
5. Ambil foto.

**Hasil yang diharapkan:** Usulan acceptance: preview pulih tanpa restart dan tanpa spinner tak berujung; tidak otomatis mengirim presensi.

**Bukti:** Video resume dan state preview.

**Penelusuran akar masalah:** Tidak ada listener AppState khusus kamera; pemulihan bergantung hook permission native.

**Sumber:** `mobile/src/app/attendance/check.tsx:374`; `mobile/src/app/attendance/check.tsx:384`

### MOB-011 — Interupsi kamera native

**P1 · Kamera · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo.
- Prasyarat: Policy photo aktif.
- Data uji: Telepon masuk/lock screen/background 30–60 detik.

1. Buka preview.
2. Background atau lock.
3. Resume.
4. Capture, retake, lalu submit.

**Hasil yang diharapkan:** Preview dan shutter pulih; tidak hitam permanen; hanya foto terakhir dikirim, satu record tercatat.

**Bukti:** Video, foto hasil pada detail, jumlah request.

**Penelusuran akar masalah:** CameraView.active mengikuti previewUri, bukan AppState; cameraReady tidak di-reset pada resume.

**Sumber:** `mobile/src/app/attendance/check.tsx:274`; `mobile/src/app/attendance/check.tsx:384`

### MOB-012 — Retake dan hasil orientasi

**P1 · Kamera · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo.
- Prasyarat: Kamera depan aktif; wajah/objek uji konsisten.
- Data uji: Potret, wajah di kiri/kanan, perangkat diputar saat capture.

1. Ambil foto.
2. Retake dua kali.
3. Submit foto terakhir.
4. Buka detail di web dan mobile.

**Hasil yang diharapkan:** Foto terakhir benar, orientasi dan crop dapat dikenali; preview mirror tidak menyebabkan salah penilaian kiri/kanan liveness.

**Bukti:** Preview terakhir, asset tersimpan, metadata ukuran tanpa wajah publik.

**Penelusuran akar masalah:** CameraView mirror + gambar JPEG hasil manipulasi; file disiapkan saat submit.

**Sumber:** `mobile/src/app/attendance/check.tsx:284`; `mobile/src/lib/image.ts:16`

### MOB-013 — Gambar besar dan storage hampir penuh

**P1 · Kamera · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo dan liveness.
- Prasyarat: Perangkat fisik; data uji saja; ruang bebas cukup untuk pemulihan. Perangkat: Android memori rendah; iOS perangkat lama.
- Data uji: Foto kamera resolusi tinggi, HEIC galeri untuk master, ruang rendah.

1. Ambil foto/master besar.
2. Kompres dan submit.
3. Ulang saat storage rendah.
4. Periksa crash dan pesan.

**Hasil yang diharapkan:** Output JPEG terbaca, sisi maksimal 1280 sesuai kode; bila gagal ada pesan dan kesempatan ulang; tidak mencatat sukses palsu.

**Bukti:** Ukuran output, crash log, request upload, UI error.

**Penelusuran akar masalah:** prepareUpload men-downsample dan save JPEG; kegagalan file dapat terjadi sebelum HTTP.

**Sumber:** `mobile/src/lib/image.ts:5`; `mobile/src/lib/image.ts:16`

### MOB-014 — Master belum ada, daftar lalu kembali

**P1 · Master wajah · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Face dan liveness.
- Prasyarat: Akun belum memiliki master; policy face/liveness.
- Data uji: Foto QA satu wajah valid.

1. Buka absen.
2. Pilih Daftarkan Foto Master Wajah.
3. Upload dari kamera.
4. Kembali ke absen dan submit.

**Hasil yang diharapkan:** Absen awal diblokir; master tersimpan dan /auth/me diperbarui; absen dapat dilanjutkan tanpa login ulang.

**Bukti:** Video navigasi, /auth/me master fields, request hasil.

**Penelusuran akar masalah:** Screen replace ke master-face; upload success refreshUser async.

**Sumber:** `mobile/src/app/attendance/check.tsx:94`; `mobile/src/app/attendance/master-face.tsx:45`

### MOB-015 — Galeri terbatas dan cancel picker

**P1 · Master wajah · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Master dari galeri.
- Prasyarat: Izin galeri limited/selected photos bila OS mendukung.
- Data uji: Satu foto terpilih; cancel; file cloud-only.

1. Buka galeri.
2. Cancel dan pastikan form stabil.
3. Pilih foto terbatas/cloud-only.
4. Simpan lalu cek hasil.

**Hasil yang diharapkan:** Cancel tidak mengganti master; foto terpilih diproses atau gagal dengan pesan jelas; master lama tidak rusak akibat upload gagal.

**Bukti:** Video picker, response upload, master sebelum/sesudah.

**Penelusuran akar masalah:** pick menggunakan permission.granted dan result.canceled; upload menggunakan URI picker.

**Sumber:** `mobile/src/app/attendance/master-face.tsx:29`

### MOB-016 — Hapus saat absen terbuka di perangkat lain

**P1 · Master wajah · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Hapus master.
- Prasyarat: Satu akun di dua perangkat QA; master valid.
- Data uji: Perangkat A form face; B hapus master.

1. A buka form dan ambil selfie.
2. B hapus master.
3. A submit.
4. A muat ulang profil.

**Hasil yang diharapkan:** Server mengikuti master terkini; tidak memverifikasi dengan master yang sudah dicabut. UI A menjelaskan kebutuhan daftar ulang setelah refresh.

**Bukti:** Timeline dua perangkat, status API, master fields.

**Penelusuran akar masalah:** Policy/master disimpan di session dan refresh saat buka; race antarperangkat harus ditangani server.

**Sumber:** `mobile/src/app/attendance/master-face.tsx:60`; `mobile/src/app/attendance/check.tsx:223`

### MOB-017 — Lokasi ditolak dan recovery

**P1 · GPS permission · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: Izin lokasi reset.
- Data uji: Deny sekali, deny permanen, allow dari Settings.

1. Tolak izin lokasi saat absen.
2. Uji tombol Izinkan/Pengaturan.
3. Beri izin.
4. Resume form.

**Hasil yang diharapkan:** Submit diblokir sampai lokasi diperoleh; pesan sesuai izin; resume setelah error mencoba lokasi ulang.

**Bukti:** Video permission, UI error dan request lokasi setelah resume.

**Penelusuran akar masalah:** LocationDeniedError membawa canAskAgain; AppState refetch hanya saat locationFailed.

**Sumber:** `mobile/src/lib/location.ts:132`; `mobile/src/app/attendance/check.tsx:126`

### MOB-018 — Approximate-only Android

**P1 · GPS permission · Android**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: OS mendukung pilihan approximate; aplikasi belum punya precise.
- Data uji: Grant approximate saja.

1. Buka absen dan berikan approximate.
2. Ikuti instruksi aktifkan precise.
3. Resume dan refresh lokasi.

**Hasil yang diharapkan:** Approximate ditolak dengan pesan lokasi akurat; sesudah precise lokasi bisa dibaca. Tidak mengirim presensi saat hanya coarse.

**Bukti:** Permission state, pesan, request body sesudah precise.

**Penelusuran akar masalah:** permission.android.accuracy coarse dilempar ApproximateLocationError.

**Sumber:** `mobile/src/lib/location.ts:137`

### MOB-019 — Precise Location dimatikan

**P1 · GPS permission · iOS**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: iOS fisik; permission when-in-use diberikan.
- Data uji: Precise OFF kemudian ON.

1. Matikan Precise Location.
2. Baca lokasi dan submit di fixture geofence.
3. Aktifkan Precise dan ulang.

**Hasil yang diharapkan:** Catat accuracy aktual dan kebijakan server; usulan acceptance tidak boleh memberi klaim akurat bila fix kasar. Jangan menyamakan perilaku iOS dengan blok coarse Android.

**Bukti:** accuracy, posisi, pesan, respons policy server.

**Penelusuran akar masalah:** Pemeriksaan coarse eksplisit hanya Android; iOS bergantung coords accuracy dan server.

**Sumber:** `mobile/src/lib/location.ts:137`; `mobile/src/lib/location.ts:168`

### MOB-020 — GPS perangkat mati

**P1 · GPS permission · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: Location services OFF, permission app sudah ada.
- Data uji: Matikan GPS; Android tolak improve accuracy dialog.

1. Buka absen.
2. Ikuti tombol aktifkan lokasi.
3. Tolak dialog sekali lalu aktifkan dari settings.
4. Resume.

**Hasil yang diharapkan:** Pesan GPS mati, tidak crash; pulih setelah aktif; tidak memaksa dialog improve accuracy berulang tanpa jalan keluar.

**Bukti:** Video dialog, durasi baca posisi, pesan.

**Penelusuran akar masalah:** enableNetworkProviderAsync fallback settings; second attempt mayShowUserSettingsDialog:false.

**Sumber:** `mobile/src/lib/location.ts:112`; `mobile/src/lib/location.ts:154`

### MOB-021 — Fix GPS tidak tersedia dan fallback

**P1 · GPS lifecycle · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: Fixture indoor atau provider uji dapat menghambat fresh fix.
- Data uji: Fresh timeout 15s+10s; cache umur 59/61 detik dan accuracy 199/201 m.

1. Coba fresh fix gagal.
2. Amati timeout dan fallback.
3. Ulang dengan empat cache boundary.
4. Uji tombol Ulangi.

**Hasil yang diharapkan:** Tidak menunggu selamanya; cached fix hanya layak bila <=60 detik dan <=200 m sesuai request SDK; tidak ada fix menampilkan pesan lokasi belum terbaca.

**Bukti:** Timeline, provider timestamps/accuracy, hasil UI.

**Penelusuran akar masalah:** Dua withTimeout kemudian getLastKnownPositionAsync maxAge/requiredAccuracy; periksa timestamp provider.

**Sumber:** `mobile/src/lib/location.ts:51`; `mobile/src/lib/location.ts:154`

### MOB-022 — Pindah lokasi setelah form siap

**P1 · GPS lifecycle · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: GPS wajib.
- Prasyarat: Awalnya di dalam geofence; form sukses mendapat fix.
- Data uji: Pindah keluar area setelah 2–5 menit tanpa menutup form.

1. Buka form di dalam area.
2. Tunggu dan pindah keluar area.
3. Background/resume.
4. Kirim tanpa menekan Ulangi.
5. Bandingkan koordinat payload dengan posisi saat kirim.

**Hasil yang diharapkan:** Usulan acceptance: presensi memakai lokasi segar atau jelas mewajibkan refresh; laporkan jika lokasi lama diterima sebagai posisi saat kirim dengan bukti timestamp/posisi.

**Bukti:** Video berpindah, waktu fix, koordinat payload, record server.

**Penelusuran akar masalah:** Submit memakai location.data; DevicePosition tidak menyimpan timestamp; resume refetch hanya saat error.

**Sumber:** `mobile/src/app/attendance/check.tsx:116`; `mobile/src/app/attendance/check.tsx:183`; `mobile/src/lib/location.ts:42`

### MOB-023 — Policy berubah ketika form terbuka

**P1 · Policy mobile · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Manual → Photo/GPS/Face.
- Prasyarat: Form dibuka pada policy manual.
- Data uji: Admin ubah menjadi photo+GPS atau face sebelum submit.

1. Buka form manual.
2. Admin perketat policy.
3. Kirim form lama.
4. Amati validasi dan refresh.
5. Lengkapi bukti dan kirim kembali.

**Hasil yang diharapkan:** Server menolak payload tidak lengkap; tidak ada bypass policy. UI refresh ke requirement baru, recover tanpa login ulang.

**Bukti:** Policy before/after, 422 fields, /auth/me dan screenshot.

**Penelusuran akar masalah:** reloadPolicy async saat mount; refresh lagi saat 422; canSubmit tidak menunggu refreshUser selesai.

**Sumber:** `mobile/src/app/attendance/check.tsx:83`; `mobile/src/app/attendance/check.tsx:223`

### MOB-024 — Policy dipermudah saat permission diblokir

**P2 · Policy mobile · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo/GPS → Manual.
- Prasyarat: Form photo/GPS gagal permission; policy diubah ke manual.
- Data uji: Semua require flag OFF.

1. Admin matikan requirement.
2. Buka ulang form/refresh profil.
3. Submit manual.

**Hasil yang diharapkan:** Form tidak meminta izin kamera/GPS yang sudah tidak wajib; satu klik mencatat sesuai policy server.

**Bukti:** Screenshot chips, izin yang muncul, payload tanpa foto/GPS.

**Penelusuran akar masalah:** selfieModeOf fallback dan needsGps membaca access.company.policy.

**Sumber:** `mobile/src/lib/api/attendance.ts:24`; `mobile/src/app/attendance/check.tsx:89`

### MOB-025 — Challenge expired ketika ambil foto

**P1 · Liveness · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Liveness.
- Prasyarat: Master valid; challenge aktif.
- Data uji: Tunggu expires_in dari response, jangan pakai TTL tebakan.

1. Ambil sebagian frame.
2. Tunggu lewat expires_in.
3. Amati challenge baru.
4. Selesaikan dan kirim.

**Hasil yang diharapkan:** Notice waktu habis; frame lama dibuang; harus mengikuti langkah baru; submit tidak memakai challenge kedaluwarsa.

**Bukti:** Challenge ID tersamarkan, receivedAt, urutan frame, response.

**Penelusuran akar masalah:** Timer tiap detik restartChallenge(false); expiry relatif receivedAt.

**Sumber:** `mobile/src/app/attendance/check.tsx:249`

### MOB-026 — Background melewati expiry

**P1 · Liveness · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Liveness.
- Prasyarat: Challenge sebagian selesai; izin kamera valid.
- Data uji: Background lebih lama dari expires_in; ubah jam perangkat terpisah.

1. Background hingga TTL lewat.
2. Resume.
3. Coba kirim cepat sebelum timer tick.
4. Ulang dengan device clock maju/mundur.

**Hasil yang diharapkan:** Server tetap otoritas expiry; tidak menerima challenge kedaluwarsa; UI pulih dengan challenge baru. Manipulasi jam lokal tidak mengubah timestamp absensi server.

**Bukti:** Server/client time, challenge/response, video resume.

**Penelusuran akar masalah:** Countdown menggunakan Date.now; canSubmit tidak mengecek remainingSeconds secara langsung.

**Sumber:** `mobile/src/app/attendance/check.tsx:250`; `mobile/src/app/attendance/check.tsx:316`

### MOB-027 — Retry network dengan urutan challenge baru

**P1 · Liveness · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Liveness.
- Prasyarat: Proxy memutus koneksi saat submit challenge pertama.
- Data uji: Challenge berikutnya berurutan berbeda bila server mengacak.

1. Selesaikan frame.
2. Putus respons submit.
3. Tunggu challenge baru.
4. Coba retry dan inspeksi mapping frame ke step.

**Hasil yang diharapkan:** Tidak mengirim frame yang keliru terhadap instruksi challenge baru; bila reuse tidak sah pengguna diminta retake. Tidak ada dua attendance record.

**Bukti:** Dua challenge step order, frame key/name, status dan jumlah record.

**Penelusuran akar masalah:** Non-422 error restartChallenge(true) mempertahankan frames berdasarkan step.key, lalu upload mengikuti order challenge baru.

**Sumber:** `mobile/src/app/attendance/check.tsx:193`; `mobile/src/app/attendance/check.tsx:227`

### MOB-028 — Retake satu frame dan all steps

**P1 · Liveness · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Liveness.
- Prasyarat: Challenge valid; dua/semua frame sudah diambil.
- Data uji: Retake salah satu key; lalu ulang semua.

1. Retake thumbnail tengah.
2. Pastikan current step tepat.
3. Selesaikan.
4. Ulang semua langkah sebelum submit.

**Hasil yang diharapkan:** Retake hanya menghapus frame target; ulang semua membuang seluruh frame dan mengambil challenge baru; submit hanya setelah langkah lengkap.

**Bukti:** Video, challenge request count, frame order.

**Penelusuran akar masalah:** frames keyed by step; livenessComplete dihitung find missing step.

**Sumber:** `mobile/src/app/attendance/check.tsx:267`; `mobile/src/app/attendance/check.tsx:304`

### MOB-029 — Submit saat offline sebelum request

**P1 · Jaringan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Manual/Photo/Liveness.
- Prasyarat: Form sudah siap; internet dimatikan hingga onlineManager mendeteksi offline.
- Data uji: Airplane mode >1 detik.

1. Tekan kirim ketika offline.
2. Tunggu >90 detik.
3. Coba tutup/back.
4. Pulihkan jaringan.
5. Periksa kapan dan apa yang dikirim.

**Hasil yang diharapkan:** Usulan acceptance: pengguna diberi status jelas dan dapat membatalkan/keluar tanpa hang; tidak ada sukses palsu. Jika auto-resume, waktu/lokasi/challenge harus tervalidasi ulang sesuai kontrak yang disepakati.

**Bukti:** Video spinner, no-request interval, resume request, waktu record.

**Penelusuran akar masalah:** onlineManager pause mutations; timeout dimulai hanya saat mutationFn request berjalan; modal menutup OfflineBanner dan close disabled saat isPending.

**Sumber:** `mobile/src/components/OfflineBanner.tsx:20`; `mobile/src/app/attendance/check.tsx:427`; `mobile/src/lib/api/attendance.ts:86`

### MOB-030 — Respons hilang setelah server commit

**P1 · Jaringan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Photo/Liveness.
- Prasyarat: Proxy staging membuang respons sukses; dapat memeriksa record lewat web.
- Data uji: Drop response sesudah server menyimpan.

1. Submit.
2. Biarkan server commit tetapi response tidak sampai.
3. Tunggu timeout.
4. Periksa web lalu tekan retry mobile.

**Hasil yang diharapkan:** Tepat satu record; retry conflict ditangani dan data direfresh. Pesan awal tidak boleh dianggap bukti server belum menyimpan.

**Bukti:** Request timestamp, attendance ID dan count, timeout, 409/response retry.

**Penelusuran akar masalah:** 90s timeout tidak memberi idempotency key; UI guard hanya mencegah double tap satu instance.

**Sumber:** `mobile/src/lib/api/attendance.ts:86`; `mobile/src/app/attendance/check.tsx:177`; `mobile/src/app/attendance/check.tsx:220`

### MOB-031 — Proxy HTML dan limit error

**P1 · Jaringan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Semua upload.
- Prasyarat: Proxy uji dapat merespons status terkendali pada request QA.
- Data uji: 413 HTML, 429, 500 JSON default, 502 HTML, 503, 504.

1. Kirim operasi upload untuk tiap respons.
2. Baca error.
3. Pulihkan endpoint dan coba kembali.

**Hasil yang diharapkan:** Pesan Indonesia sesuai status; tidak raw HTML/technical exception; tidak sukses palsu; retry tidak membuat record ganda.

**Bukti:** Status/content-type, screenshot, record count.

**Penelusuran akar masalah:** failureMessage memetakan proxy/framework error; errorMessage mengambil validation pertama.

**Sumber:** `mobile/src/lib/api/client.ts:116`; `mobile/src/lib/api/client.ts:135`

### MOB-032 — Double tap dan dua perangkat

**P1 · Jaringan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Check-in/check-out.
- Prasyarat: Dua sesi akun yang sama pada perangkat berbeda.
- Data uji: Tap cepat <100ms; kirim serentak dari A/B.

1. Double tap satu perangkat.
2. Ulang submit serentak dua perangkat pada fixture fresh.
3. Periksa request dan data.

**Hasil yang diharapkan:** Satu perangkat mengirim satu request berkat ref guard; backend tetap menjaga satu transisi untuk dua perangkat; conflict dipulihkan.

**Bukti:** Jumlah POST, response, record before/after.

**Penelusuran akar masalah:** submitInFlight ref hanya lokal; concurrent devices membutuhkan uniqueness/transaction backend.

**Sumber:** `mobile/src/app/attendance/check.tsx:177`; `mobile/src/app/attendance/check.tsx:240`

### MOB-033 — Ganti Wi-Fi/data dan kill saat upload

**P1 · Jaringan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Upload.
- Prasyarat: Foto besar/liveness aktif; internet dapat dikontrol.
- Data uji: Wi-Fi→seluler; force-close di tengah upload.

1. Mulai upload.
2. Ganti jaringan atau force-close.
3. Buka ulang.
4. Baca today sebelum mencoba lagi.

**Hasil yang diharapkan:** Tidak crash; UI setelah restart merekonsiliasi state server; tidak menyatakan offline batch tersimpan bila tidak ada persistensi. Record tidak ganda.

**Bukti:** Video, request outcome, today, count.

**Penelusuran akar masalah:** Mutations tidak dipersist; request dapat diterima server walau aplikasi kehilangan response.

**Sumber:** `mobile/src/lib/api/client.ts:155`; `mobile/src/lib/queryClient.ts:5`

### MOB-034 — OTP salah/expired/resend

**P1 · Login alternatif · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: WhatsApp OTP.
- Prasyarat: Nomor QA terisolasi dan provider staging.
- Data uji: Kode 5/6/7 digit; salah; expired; resend.

1. Minta OTP.
2. Uji input non-digit dan panjang.
3. Verifikasi kode salah/expired.
4. Resend lalu uji kode lama dan kode terbaru.

**Hasil yang diharapkan:** Input UI 6 digit; error server jelas; kode lama mengikuti invalidasi server; tidak membuat sesi dari challenge salah.

**Bukti:** Challenge IDs tersamarkan, status, layar error; jangan simpan OTP.

**Penelusuran akar masalah:** UI regex 6 digits dan challenge replacement setelah resend; rate limit server terpisah.

**Sumber:** `mobile/src/app/login.tsx:143`; `mobile/src/app/login.tsx:167`

### MOB-035 — Same-device dan cross-device link

**P1 · Login alternatif · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Email magic link.
- Prasyarat: Email QA; aplikasi signedOut; challenge diminta pada A.
- Data uji: Link A dibuka di A, B, setelah expired, dan dua kali.

1. Minta link pada A.
2. Buka di B terlebih dahulu.
3. Buka di A cold-start.
4. Replay sesudah berhasil pada sesi signedOut baru.

**Hasil yang diharapkan:** B tanpa challenge ditolak; A valid berhasil; expired/replay tidak memberi sesi. URL token tidak dicatat utuh pada laporan.

**Bukti:** Video deep link, challenge existence, response status tanpa token.

**Penelusuran akar masalah:** Token memerlukan challenge SecureStore lokal; readPendingMagicLink mengecek Date.now.

**Sumber:** `mobile/src/lib/magicLink.ts:5`; `mobile/src/app/email-login.tsx:25`

### MOB-036 — Link baru saat error lama masih terbuka

**P1 · Login alternatif · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Email magic link.
- Prasyarat: Layar email-login menampilkan error untuk token pertama.
- Data uji: Link pertama invalid; request kedua valid.

1. Buka link invalid sampai error.
2. Tanpa force-close, buka link valid berikutnya.
3. Amati apakah verifikasi dijalankan untuk token baru.

**Hasil yang diharapkan:** Usulan acceptance: link terbaru yang valid diproses atau pengguna diberi jalan jelas ke login; tidak terjebak error lama.

**Bukti:** Video, perubahan route param tersamarkan, jumlah POST verify.

**Penelusuran akar masalah:** started useRef mencegah efek verify berjalan kedua kali selama instance screen tetap sama meski token berubah.

**Sumber:** `mobile/src/app/email-login.tsx:23`; `mobile/src/app/email-login.tsx:40`

### MOB-037 — Push foreground/background/cold start

**P1 · Push dan deep link · Android + iOS**

- Aktor: Approver/PIC dan pekerja. Mode: Push.
- Prasyarat: Push provider staging terisolasi; payload sesuai jenis.
- Data uji: leave/overtime/correction/geofence/resignation/schedule.

1. Kirim event test ketika app foreground.
2. Ulang background lalu tap.
3. Ulang cold start.
4. Bandingkan target dan badge.

**Hasil yang diharapkan:** Daftar relevan direfresh, navigasi setelah signedIn dan navigator siap; target sesuai izin. Tidak menduplikasi mutasi dari tap berulang.

**Bukti:** Payload tanpa data pribadi, video target, invalidation requests.

**Penelusuran akar masalah:** pendingData menunggu signedIn; foreground invalidates team/approvals/requests/attendance.

**Sumber:** `mobile/src/hooks/usePushNotifications.ts:62`; `mobile/src/lib/push.ts:31`

### MOB-038 — Push lama dan account switch

**P0 · Push dan deep link · Android + iOS**

- Aktor: Akun A dan B lintas klien. Mode: Push setelah ganti akun.
- Prasyarat: A punya push pending menuju record privat; B berbeda klien.
- Data uji: Tap notif A saat signedOut lalu login B.

1. Logout A.
2. Tap notif lama A.
3. Login B.
4. Amati target/data.
5. Kirim event A sesudah logout.

**Hasil yang diharapkan:** Tidak membocorkan record A atau mengirim notifikasi baru A ke B; target lama ditolak/dibuang. Jika navigasi tetap terjadi, backend wajib menolak akses dan UI bersih.

**Bukti:** Video, user IDs, HTTP denied, subscription mapping tersamarkan.

**Penelusuran akar masalah:** pendingData tidak diikat user penerima; forgetPushUser berjalan saat signOut.

**Sumber:** `mobile/src/hooks/usePushNotifications.ts:32`; `mobile/src/lib/session.ts:98`

### MOB-039 — Deep link ke form privileged dan ID asing

**P1 · Push dan deep link · Android + iOS**

- Aktor: Worker tanpa approval; PIC terbatas. Mode: Deep link akses.
- Prasyarat: Akun fixture least privilege.
- Data uji: /approval/geofence/{id}; /client/account/{id}; /attendance/{id}; ID asing/NaN.

1. Buka tiap route langsung via alat QA/deep link.
2. Gunakan own record, foreign record, dan invalid id.
3. Coba tombol mutasi bila tampil.

**Hasil yang diharapkan:** Tidak ada data atau mutasi di luar scope; invalid ID menampilkan error yang dapat dipulihkan tanpa loading abadi.

**Bukti:** Route, screenshots, status dan state record.

**Penelusuran akar masalah:** Root Stack hanya signedIn; ApprovalDetail enabled Number.isFinite tetapi invalid id bisa tetap query.isPending.

**Sumber:** `mobile/src/app/_layout.tsx:112`; `mobile/src/app/approval/[type]/[id].tsx:35`

### MOB-040 — Input jam koreksi dan validasi server

**P1 · Pengajuan dan parity · Android + iOS**

- Aktor: Employee, Intern, Freelancer. Mode: Koreksi manual.
- Prasyarat: Akun punya izin koreksi; tanggal target fixture.
- Data uji: Masuk saja; pulang saja; keduanya kosong; jam lintas tengah malam; alasan spasi.

1. Buka koreksi.
2. Isi setiap variasi via date/time picker.
3. Submit.
4. Bandingkan error dan request payload dengan web.

**Hasil yang diharapkan:** Minimal satu jam + alasan diwajibkan UI; waktu HH:MM dikirim konsisten; aturan shift/overnight mengikuti backend; field errors terlihat di field terkait.

**Bukti:** Payload, picker values, API errors, hasil web.

**Penelusuran akar masalah:** Client validation hanya presence; DateTimeField memakai jam lokal tanpa tanggal untuk time.

**Sumber:** `mobile/src/app/request/correction/new.tsx:40`; `mobile/src/components/DateTimeField.tsx:20`

### MOB-041 — Jenis cuti dan perubahan hak aktif

**P1 · Pengajuan dan parity · Android + iOS**

- Aktor: Employee dengan cuti; Intern; Freelancer. Mode: Cuti/izin.
- Prasyarat: Fixture employee annual true/false; intern; freelancer.
- Data uji: Freelancer permission/sick; annual leave dicabut ketika form terbuka.

1. Buka jenis pengajuan tiap role.
2. Cocokkan pilihan dengan profil.
3. Cabut annual leave di web saat pilihan annual sudah aktif.
4. Submit dan refresh.

**Hasil yang diharapkan:** Freelancer hanya izin/sakit; annual hanya employee berhak. Server menolak hak yang dicabut. Tidak tersimpan pengajuan terlarang dari pilihan stale.

**Bukti:** /auth/me, pilihan UI, response, record count.

**Penelusuran akar masalah:** leaveTypes mengikuti profileKind, state leaveType diinisialisasi sekali dari access.

**Sumber:** `mobile/src/lib/access.ts:80`; `mobile/src/app/request/leave/new.tsx:17`

### MOB-042 — Approve di web, detail mobile lama

**P1 · Rekonsiliasi · Android + iOS**

- Aktor: Approver dan pekerja. Mode: Approval lintas kanal.
- Prasyarat: Mobile membuka detail pending; web memproses record sama.
- Data uji: approve/reject/cancel dua kanal.

1. Buka detail mobile.
2. Proses dari web.
3. Coba keputusan lama dari mobile.
4. Refresh list, detail, recap.

**Hasil yang diharapkan:** Satu keputusan efektif; stale step/status ditolak; semua view akhirnya konsisten. Catat laporan yang tetap stale sampai manual refresh.

**Bukti:** current_step/status before/after, response, screenshot recap.

**Penelusuran akar masalah:** process mengirim current_step; invalidates requests/attendance/approvals tetapi tidak report/team.

**Sumber:** `mobile/src/app/approval/[type]/[id].tsx:48`

### MOB-043 — Timezone perangkat beda

**P1 · Waktu dan tampilan · Android + iOS**

- Aktor: Employee / Intern / Freelancer. Mode: Riwayat dan pengajuan.
- Prasyarat: Backend timezone terkonfirmasi; record dekat pergantian hari.
- Data uji: Perangkat Asia/Jakarta lalu UTC dan Asia/Makassar; record ISO datetime dan plain date.

1. Buka riwayat/today.
2. Ubah timezone perangkat.
3. Buka ulang dan input koreksi tanggal sama.
4. Bandingkan web.

**Hasil yang diharapkan:** Plain date tetap hari kalender yang sama sesuai kode. Waktu ISO dirender locale device; acceptance zona waktu bisnis harus ditetapkan dan diuji agar pengguna tidak mengoreksi jam salah.

**Bukti:** Timezone, ISO raw, output mobile/web.

**Penelusuran akar masalah:** formatTime memakai toLocaleTimeString tanpa timeZone; toDateString memakai local getters.

**Sumber:** `mobile/src/lib/format.ts:18`; `mobile/src/lib/format.ts:51`

### MOB-044 — Layar kecil, font besar, keyboard, dark mode

**P2 · Usability native · Android + iOS**

- Aktor: Semua peran. Mode: Semua form.
- Prasyarat: Perangkat terkecil yang didukung dan satu perangkat notch; accessibility font 200%.
- Data uji: Form login, approval, koreksi, kamera, policy PIC.

1. Aktifkan font besar dan screen reader.
2. Buka tiap form dengan keyboard.
3. Ubah dark mode.
4. Coba Android back/iOS sheet cancel.

**Hasil yang diharapkan:** Label, pesan validasi, tombol utama dan status tetap terbaca/terjangkau; tidak ada submit tak sengaja saat cancel/back; fokus bisa mengikuti error.

**Bukti:** Screenshots dan video screen reader/keyboard.

**Penelusuran akar masalah:** UI shared components + native date picker/sheet + fullScreenModal camera.

**Sumber:** `mobile/src/components/DateTimeField.tsx:71`; `mobile/src/app/attendance/check.tsx:424`
