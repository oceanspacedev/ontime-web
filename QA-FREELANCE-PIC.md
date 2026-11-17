# QA PIC → Freelance → Absensi → Lembur → Laporan Gaji

Dijalankan 17 November 2026, 01:43–02:15 WIB. HP Android **Realme RMX3624** (Expo Go, Metro lokal) terhubung ke backend lokal terisolasi: SQLite baru, tanpa WA, email, atau S3, `adb reverse` device:8000 → host:8010. Kode yang diuji adalah working tree `fix/qa-staging-findings` pada jam tersebut. Data dibuat dari nol oleh PIC. Superadmin hanya membuat Klien dan akun PIC.

## A. Hasil uji perangkat nyata

**Persiapan oleh PIC.** Badan Usaha `QAFL` dan lokasi "Outlet QA Cirebon" (radius 100 m) dibuat di web. Divisi dibuat. Master Shift dibuat di web: QA Pagi 09:00–18:00, QA Dini 01:30–10:00, QA Malam 22:00–06:00, dan QA Split 01:00–02:30 & 03:30–06:00 (toleransi 15, Split 10). Lima freelancer dibuat di web dan akunnya otomatis terbentuk; password tampil di notifikasi panel karena HP kosong. Jadwal diatur lewat "Atur Jadwal". Kebijakan absensi diubah dari aplikasi, Kelola Klien → Wajib lokasi GPS. Semua langkah ini **lulus**.

| ID | Aktor | Skenario | Diharapkan | Aktual di HP | Hasil |
|---|---|---|---|---|---|
| D-01 | Andi | Shift Dini 01:30, masuk 01:52 | Telat 22 mnt | "Telat 22 mnt", DB late 22 | ✅ |
| D-02 | Budi | Shift Pagi 09:00–18:00, masuk **01:57**, pulang **01:58** | Ditolak, atau ditandai datang terlalu awal + pulang cepat. Bukan hadir penuh | **"Tepat Waktu"**, hari ditutup (tidak bisa absen lagi jam 09:00). Laporan: "Hadir", Rekap: Hari Masuk 1, Telat 0 | ❌ **bug yang dilaporkan** |
| D-03 | Citra | Shift Malam 22:00 tanggal 16, masuk 17 Nov 01:59 | Telat ±239, dicatat tanggal 16 | Telat 239 mnt, tanggal 16 | ✅ (kartu masih menulis "Selasa, 17 November"; Laporan web menulis 16 Nov + jam 01:59 tanpa tanggal sehingga terbaca sebelum shift) |
| D-04 | Dewi | Split, segmen 1 masuk 02:00 | Telat 60 | Telat 60 | ✅ |
| D-05 | Dewi | Segmen 2 (03:30) dicoba jam 02:02 | Ditolak sampai 02:30 | "Segmen kedua dimulai pukul 03:30. Check in dibuka pukul 02:30." | ✅ (jendela ini **hanya** ada untuk segmen 2, segmen 1 tidak punya: lihat D-02) |
| D-06 | Eko | Tanpa jadwal & tanpa shift default, masuk 02:03 | Freelancer tanpa jadwal tidak dinilai "tepat waktu" begitu saja | Dinilai pakai jam kantor 08:00 → **"Tepat Waktu"**/"Hadir" | ❌ |
| D-07 | Andi | Pulang 02:08 (shift sampai 10:00) | Ditandai pulang cepat | Tidak ada penanda; status tetap "Terlambat (22m)" | ❌ |
| D-08 | Andi→PIC | Ajukan lembur 10:00–12:00 di HP, PIC setujui di HP | Lembur 2 jam masuk rekap gaji | Disetujui (120 mnt), tetapi **tidak muncul** di Rekap Tim maupun Excel "Unduh Excel" | ❌ |
| D-09 | PIC | Tim → Hari | Hitungan & status benar | "2 dari 4 orang sudah absen" padahal Andi, Budi, Dewi (dan Citra di shift semalam, Eko tanpa jadwal) sudah absen. Dewi "Belum mulai" padahal segmen 1 selesai. Citra yang sedang bekerja tampil "Belum mulai". Eko tidak tampil | ❌ |
| D-10 | PIC | Rekap → Unduh Excel (diunduh di HP) | Kolom untuk gaji lengkap | Kolom: Hari Terjadwal/Masuk/Tidak Hadir/Libur/Telat/Jam Terjadwal/Jam Kerja. **Tidak ada Lembur, Pulang Cepat, Belum Checkout.** Jam Terjadwal benar (Andi 59,5; Budi 63; Citra 64; Dewi 28) | ❌ sebagian |

Bukti tambahan dari data demo sebelumnya di HP yang sama: Nadia, shift Pagi 09 09:00–18:00, masuk 01:35, pulang 01:36, tercatat **"Tepat Waktu"**, dengan `is_out_of_bounds=1`.

### Akar masalah "Tepat Waktu" (D-02, D-06)
`WorkScheduleService::lateMinutes()` hanya memeriksa apakah absen lewat dari jam mulai + toleransi. Datang jam berapa pun sebelum jam mulai selalu dihitung 0 menit telat, lalu diberi status `OnTime`. `AttendanceCheckService::performCheckIn()` hanya membatasi jam buka absen untuk **segmen 2** (`SECOND_SEGMENT_OPENS_MINUTES_BEFORE`), tidak untuk segmen 1. Check-out juga tidak membandingkan dengan jam pulang. Akibatnya absen 7 jam lebih awal lalu langsung pulang tercatat sebagai satu hari hadir tepat waktu, padahal pekerja hanya hadir 1 menit.

Perlu keputusan produk: (1) berapa menit sebelum shift absen masuk dibuka (contoh: 60 menit, sama dengan segmen 2); (2) apakah pulang sebelum jam selesai ditandai "Pulang cepat" + menit, dan ikut ke rekap; (3) freelancer tanpa jadwal: ditolak, atau tetap memakai jam kantor.

## A2. Perbaikan & uji ulang (17 Nov 2026, 02:28–02:40 WIB)

Keputusan user: absen masuk dibuka **60 menit** sebelum jam mulai (shift, atau jam kerja badan usaha bila tanpa jadwal), dan pulang sebelum jam selesai dicatat sebagai **"Pulang cepat"**.

| ID | Uji ulang di HP | Hasil |
|---|---|---|
| R-01 | Fajar, shift Pagi 09:00, absen masuk 02:29 | Ditolak: "Shift dimulai pukul 09:00. Absen masuk dibuka pukul 08:00." Tidak ada baris tersimpan ✅ |
| R-02 | Eko (jam kerja BU 08:00–17:00) absen pulang 02:30 | Kartu: "Tepat Waktu" + **"Pulang cepat 869 mnt"**; DB `early_leave_minutes=869` ✅ |
| R-03 | PIC → Tim → Rekap | Eko: "1× pulang cepat" ✅ |

Excel Rekap Tim punya kolom **Pulang Cepat (kali)** dan **Total Pulang Cepat (menit)**. Export detail Laporan Absensi punya kolom **Menit Pulang Cepat**. Status di Laporan Absensi menjadi misalnya "Hadir · Pulang cepat (45m)". Libur tidak terkena jendela. Shift malam yang absen lewat tengah malam tetap masuk ke tanggal kemarin. Split shift dinilai per segmen.

## A3. Lembur di laporan gaji (17 Nov 2026)

Lembur yang **disetujui** sekarang dihitung pada tanggal lemburnya. Durasinya memakai durasi aktual bila sudah diisi, kalau belum memakai durasi pengajuan. Lembur yang masih pending atau ditolak tidak dihitung.

| ID | Uji ulang di HP | Hasil |
|---|---|---|
| R-04 | PIC → Tim → Rekap | Andi: "Masuk 1/7 · 1× telat · 0,25 jam · **lembur 2 jam**" ✅ |
| R-05 | PIC → Unduh Excel (diunduh di HP) | Andi: Lembur (kali) 1, Total Lembur (jam) 2. Baris Total ikut ✅ |

Excel Rekap Tim punya kolom tambahan di akhir: **Lembur (kali)** dan **Total Lembur (jam)**. Export Rekap di Laporan Absensi (web) punya kolom total: Hari Masuk, Terlambat, Telat (menit), **Pulang Cepat (menit)**, **Lembur (jam)**, Jumlah Jam. Kolom tanggal bergeser 2 kolom ke kanan. Batasan: di export web, pekerja yang hanya punya lembur tanpa absensi di rentang itu tidak tampil, karena lembar itu disusun dari data absensi.

## A4. Absen menunggu persetujuan & ditolak di laporan gaji (17 Nov 2026)

Absen di luar area outlet menunggu persetujuan dengan `late_minutes = 0`. Akibatnya, sebelum disetujui (atau kalau tidak pernah disetujui), absen yang telat terbaca tepat waktu di laporan. Sekarang laporan memakai telat yang dinilai saat check-in, dan mencatat berapa hari masih menunggu persetujuan supaya PIC tahu data belum final sebelum menggaji. Hari yang menunggu persetujuan tetap dihitung hadir.

| ID | Uji ulang | Hasil |
|---|---|---|
| R-06 | Gilang, shift Dini 01:30, outlet jadwal Jakarta (200 km), absen 03:02 dari HP | "Absen masuk tercatat, menunggu persetujuan"; DB pending, `check_in_late_minutes=92` ✅ |
| R-07 | PIC → Tim → Rekap di HP | "Masuk 1/1 · 1 menunggu persetujuan · 1× telat" ✅ |
| R-08 | Laporan Absensi web | "Menunggu Persetujuan (telat 92m)"; Eko "Hadir · Pulang cepat (869m)" ✅ |

Excel Rekap Tim punya kolom **Menunggu Persetujuan (hari)**. Ringkasan laporan di aplikasi menghitung hari pending yang telat sebagai telat. Excel rekap web memakai telat yang sama. Absen Hari Ini dan widget presensi menampilkan **"Ditolak"** (merah) untuk absen yang ditolak, tidak lagi "Tepat Waktu".

## A5. Pematangan: tukar shift, backup darurat, uji chaos (17 Nov 2026)

**Fitur baru**
- **Masuk di luar jadwal / backup darurat**: pekerja yang libur (atau freelancer tanpa jadwal) tetap bisa absen. Ia **wajib memilih shift** yang dikerjakan dan boleh memilih rekan yang digantikan. Absennya lalu menunggu persetujuan, dengan telat dinilai dari shift itu. Setelah disetujui, jadwalnya terbentuk otomatis, dan rekan yang digantikan menjadi libur dengan catatan "Digantikan oleh …", jadi tidak alpha dan tidak dibayar. Rekan yang shift-nya berbeda dari shift yang dipilih ditolak.
- **Tukar Shift**: A mengajak tukar atau menyerahkan shift, B menerima atau menolak, lalu Koordinator/PIC menyetujui. A dan B tidak bisa menyetujui sendiri. Jadwal tertukar secara atomik. Pengajuan ditolak bila jadwal sudah berubah, salah satu sudah absen, shift sudah mulai, ada cuti/izin, atau tanggalnya sudah lewat.
- **Hitung ulang otomatis**: perubahan jadwal setelah absen dan edit admin menghitung ulang telat dan pulang cepat. Keputusan manual admin tidak ditimpa.

**Uji di HP (Realme RMX3624, backend lokal terisolasi)**

| ID | Skenario | Hasil |
|---|---|---|
| M-01 | Budi libur, absen 08:03 memilih shift Pagi tapi "menggantikan Citra" (Siang) | Ditolak: "Shift yang dipilih bukan shift rekan yang digantikan", tidak ada data tersimpan ✅ |
| M-02 | Budi absen 08:03, shift Pagi, menggantikan Andi | Menunggu persetujuan; kartu persetujuan PIC: "Masuk di luar jadwal · menggantikan Freelance Andi · shift Pagi" ✅ |
| M-03 | PIC menyetujui di HP | Budi tepat waktu dengan jadwal Pagi; Andi libur "Digantikan oleh Freelance Budi" ✅ |
| M-04 | Citra (Siang) ajak tukar Dewi (Closing), Dewi terima, PIC setujui | Citra Closing, Dewi Siang, dengan catatan; jadwal besok tidak berubah ✅ |
| M-05 | Citra ajak tukar Budi yang sudah absen | Ditolak: "… sudah presensi …, jadwalnya tidak bisa ditukar lagi." ✅ |
| M-06 | Tim → Hari, Rekap PIC | "1 dari 3 orang sudah absen", Andi di Libur; Rekap tanpa alpha untuk Andi ✅ |

**Uji chaos**: 6 sudut (waktu/tengah malam, bersamaan/retry, perubahan di tengah jalan, kombinasi F&B, penyalahgunaan input/otorisasi, klien HP). 40 kegagalan terbukti lewat test gagal lalu diperbaiki. 78 skenario lain sudah ditangani benar dan kini jadi test regresi (`tests/Feature/Chaos/`, `mobile/src/lib/__tests__/chaos_*.test.ts`). Contoh yang diperbaiki: kontrak/resign ditutup jam 00:15 saat shift malam terakhir masih berjalan; shift mulai 00:00; segmen 2 split setelah tengah malam; cuti disetujui menimpa check-in yang masuk bersamaan; koreksi mengubah hari cuti jadi hari kerja (dibayar dua kali); dua backup menggantikan orang yang sama; lembur di tanggal yang diserahkan; jam tampil sesuai zona HP, bukan WIB; respons HTML portal Wi-Fi dibaca sukses; formulir offline terkirim ulang diam-diam; ID/array aneh di API menghasilkan 500.

**Status test**: PHP 1.684 test, semua non-probe lulus. Mobile jest 189/189, typecheck dan lint bersih. Yang masih gagal hanya probe sesi lain (ATTA/ATTB/ROLA) yang menguji perilaku lama yang sengaja diubah: freelancer tanpa jadwal kini lewat persetujuan, dan lupa absen pulang shift siang lewat koreksi.

## A6. QA kesiapan di HP Realme (17 Nov 2026, 10:10–11:05 WIB)

APK debug di-build dari kode terbaru dan dipasang di Realme RMX3624. Backend lokal memakai database SQLite yang terisolasi, dengan pengaman yang menolak perintah ke database lain. Mode absensi: **GPS + Foto selfie** (kamera asli).

| ID | Skenario | Hasil |
|---|---|---|
| R-01 | PIC (web): badan usaha + 2 outlet, divisi, 3 shift, Karyawan, Magang, 5 Freelancer, jadwal | ✅ Akun freelancer dibuat otomatis; karyawan/magang lewat tombol "Buat Akun" |
| R-02 | Magang dengan tanggal selesai sebelum tanggal mulai | ✅ Ditolak |
| R-03 | Freelancer dengan HP 6281… padahal 0812… sudah dipakai | ✅ Ditolak |
| R-04 | PIC (HP) mengubah kebijakan ke GPS + Foto | ✅ |
| R-05 | Karyawan Eka (Pagi 10:00) absen 10:26, HP 1,8 km dari outlet | ✅ Menunggu persetujuan, telat 26 tercatat, selfie tersimpan |
| R-06 | Magang Fani (Siang 11:00) absen 10:27 di outlet | ✅ Tepat Waktu |
| R-07 | Freelancer Gilang (Sore 13:00) absen 10:2x | ✅ Ditolak: "Absen masuk dibuka pukul 12:00" |
| R-08 | Hana dijadwalkan di Outlet Bandung (106 km) | ✅ Menunggu persetujuan, telat 30 |
| R-09 | Indra tanpa jadwal menjadi backup Joko (Siang) | ✅ Menunggu persetujuan dengan label "menggantikan Freelance Joko · shift Siang" |
| R-10 | Lembur Eka 18–20, lalu lembur bentrok 19–21 | ✅ Lembur pertama terkirim, yang bentrok ditolak |
| R-11 | Koreksi untuk absen yang masih menunggu persetujuan | ✅ Ditolak dengan alasan |
| R-12 | Izin Fani besok; Fani pulang 10:41 | ✅ Izin terkirim; "Pulang cepat 498 mnt" |
| R-13 | Tukar shift dengan Fani (punya izin), lalu dengan Hana | ✅ Dengan Fani ditolak; dengan Hana diterima |
| R-14 | **BUG**: inbox Persetujuan PIC error "lazy load [shift]" | ❌→✅ diperbaiki (`Attendance::offScheduleLabel`, `WorkScheduleService::earlyLeaveMinutes`) + test |
| R-15 | PIC menyetujui 5 pengajuan dan menolak 1 | ✅ Eka telat 26; Hana ditolak; Indra terjadwal Siang dan Joko libur "Digantikan"; jadwal besok Gilang⇄Hana tertukar |
| R-16 | **BUG**: Tim menampilkan "Hadir" untuk absen yang ditolak; pekerja dengan shift default disebut "Tanpa jadwal" | ❌→✅ diperbaiki: status "Ditolak" (tidak dihitung hadir), catatan "Shift default", kelompok "Di luar roster" + test |
| R-17 | Rekap + Excel (unduh di HP), Laporan Absensi web | ✅ Telat, pulang cepat, lembur, ditolak, dan backup sesuai |

Test akhir: PHP 1.686/1.686 lulus, mobile 189/189, typecheck dan lint bersih.

## B. Katalog skenario lengkap & bug dari audit kode (probe PHPUnit di `tests/Feature/QaProbe/`)

# Katalog Skenario QA End-to-End: PIC → Freelancer → Absensi → Lembur → Koreksi → Laporan Penggajian

**Hasil probe pada working tree saat ini.** Probe dijalankan ulang pada 2026-11-17 di branch `fix/qa-staging-findings` yang masih punya perubahan belum di-commit. Perintahnya `php artisan test --compact tests/Feature/QaProbe`, memakai DB test SQLite. Hasil: 250 test, 202 lulus, 47 gagal, 1 error. Log ada di `/private/tmp/claude-501/-Users-apriansyahrs-Documents-Code-ontime-web/65dc6ecc-c56e-42b4-b53f-382ab093b944/scratchpad/probe.txt`.

Working tree berubah selama QA berlangsung, jadi beberapa bug yang terkonfirmasi di awal sekarang probenya sudah lulus. Bug seperti itu ditandai **[FIXED-WT]**: sudah diperbaiki di working tree tetapi belum di-commit atau di-deploy, sehingga kemungkinan besar masih ada di `main`/staging. Bug yang probenya masih gagal ditandai **[BUG]**. Perilaku yang memang disengaja dan butuh keputusan produk ditandai **[PRODUK]**.

**Konvensi untuk semua skenario:**
- Zona waktu Asia/Jakarta (config/app.php:68).
- Policy Badan Usaha (BU): 08:00–17:00, toleransi 15. GPS dan selfie off, kecuali skenario menyebut lain.
- Keterlambatan dihitung dari jam mulai shift, bukan dari batas toleransi. Batas toleransi inklusif (WorkScheduleService.php:112–116).
- Platform: **A** = Android (app Expo, backend lokal), **W** = panel web /admin, **B** = keduanya.

---

## 0. Persiapan lingkungan (wajib sebelum mulai)

| ID | Langkah | Hasil diharapkan |
|---|---|---|
| ENV-01 | Set `EXPO_PUBLIC_API_URL` app ke IP LAN backend lokal (bukan `localhost`). Jalankan backend dan scheduler (`php artisan schedule:work`). Pastikan jam HP dan jam server sama (WIB). | Login di app berhasil. `GET /api/v1/attendance/today` mengembalikan tanggal hari ini. |
| ENV-02 | Pakai akun dan data uji khusus QA. Skenario tengah malam (ABS-06, ABS-07) dijalankan sungguhan di atas jam 00:00, atau dengan mengubah jam server dan HP secara bersamaan. | Data tidak tercampur dengan data nyata. |

## 1. PIC: login, Badan Usaha, lokasi, policy

| ID | Aktor | Prasyarat | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|---|---|
| PIC-01 | PIC | Klien aktif | Login /admin dan login app. | Dashboard terbuka. Akun nonaktif mendapat 403 "Akun Anda tidak aktif..." (AuthController.php:33-36). | B |
| PIC-02 | PIC | – | Buat BU dengan kode `PFQ`, Divisi/Brand "Outlet", lokasi outlet (radius 100 m), policy 08:00–17:00 toleransi 15. | Data tersimpan. Outlet tanpa brand akan ditolak saat tambah freelancer dari app (PIC-05). | B |
| PIC-03 | PIC Klien A | Ada Klien B | Buka select BU, picker Akun, dan URL edit freelancer milik Klien B. | Hanya data Klien A yang tampil. Edit record Klien B → 404. API `/team/freelancers/{B}` → 404 "Pekerja tidak ditemukan." | B |

## 2. Tambah freelancer dan akun

| ID | Aktor | Prasyarat | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|---|---|
| PIC-04 | PIC | PIC-02, gateway WA off | Data Freelance → Buat: "Budi", HP 081234567890, BU PFQ, Divisi Outlet, Shift Default kosong, toggle Buat akun ON. | Nomor FL-PFQ-0001. Notifikasi menampilkan email `fl-pfq-0001@freelance.local`, password 10 karakter, dan tombol "Kirim via WhatsApp". User: role Freelancer, is_active=1 (WorkerAccountService.php:37-76). | W |
| PIC-05 | PIC | Outlet ber-brand | App: Tim → Freelancer baru "Sari", 081299990000, outlet. | HTTP 201, kredensial tampil sekali. shift_id NULL, end_date NULL (TeamApiController.php:267-274). Outlet tanpa brand → 422 "Outlet ini belum punya brand...". | A |
| PIC-06 | PIC | Ada user `budi@contoh.com` | Buat freelancer dengan email "Budi@Contoh.com ". | Freelancer tetap dibuat, akun tidak. Notifikasi "Email login sudah terpakai. Isi email lain." | W |
| PIC-07 **[BUG PFO-B4]** | PIC | PIC-05 | Tambah lagi "Sari" dengan nomor 6281299990000. | Seharusnya 422 karena nomor duplikat. Aktual: 201. Akibatnya login OTP WhatsApp untuk nomor itu menjawab unknown_account. | A |
| PIC-08 **[BUG PFO-B8]** | PIC | Ada karyawan yang sudah punya akun | Buat freelancer dengan Buat akun OFF, lalu pilih akun karyawan itu di "Akun User". | Seharusnya akun itu tidak muncul di picker. Aktual: muncul dan bisa disimpan. Check-in user tersebut tercatat ke employee_id, bukan freelancer_id. | W |
| PIC-09 **[BUG PFO-B6]** | PIC | – | Edit: Mulai 2026-11-20, Selesai 2026-11-05. | Seharusnya ada error validasi. Aktual: tersimpan tanpa error (FreelanceResource.php:176-182). | W |
| PIC-10 **[BUG PFO-B1]** | PIC + FL | Mulai kontrak = hari ini + 14 hari | Freelancer check-in hari ini pukul 07:50. | Seharusnya ditolak karena kontrak belum mulai. Aktual: 200 "Check in berhasil" dengan status on_time (WorkerAccountService.php:119-130 tidak memeriksa start_date). | A |
| PIC-11 | PIC + FL | end_date = kemarin | Check-in sebelum job 00:15. Lalu jalankan `php artisan workers:close-expired-contracts` dan login ulang. | Check-in → 403 "Status kerja Anda tidak aktif...". Setelah job: status completed, user is_active=0, token dicabut, login → 403. Jika end_date = hari ini, check-in masih boleh. | A |
| PIC-12 **[FIXED-WT PFO-B2/B5]** | PIC + FL | Kontrak sudah ditutup | Edit status menjadi Diperpanjang dengan end 2026-12-31. Ulangi dengan status Berhenti pada freelancer aktif. | Diperpanjang → user is_active=1 dan bisa login. Berhenti → akun mati, token dicabut, jadwal yang akan datang dihapus. Di HEAD akun tetap mati (Diperpanjang) atau tetap hidup (Berhenti). Verifikasi ulang setelah deploy. | B |
| PIC-13 **[BUG PFO-B3]** | PIC (Admin+PIC) | Freelancer punya absensi dan lembur bulan ini | Delete freelancer, lalu buka Rekap Tim. | Seharusnya delete diblokir, atau data cukup diakhiri kontraknya. Aktual: absensi, lembur, dan cuti ikut terhapus karena FK cascade, sehingga hilang dari laporan gaji. | W |

## 3. Master Shift

| ID | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|
| SHF-01 | Buat shift **Pagi** 08:00–16:00 toleransi 15. | Tersimpan. | W |
| SHF-02 | Buat shift **Split** 07:45–13:00 & 17:45–22:00 toleransi 15. Coba simpan segmen 2 yang mulai sebelum 13:00. | Simpan valid → OK. Segmen 2 < 13:00 → error (ShiftResource.php:137-143). | W |
| SHF-03 | Buat shift **Malam** 22:00–06:00 toleransi 15. | Tersimpan sebagai crossesMidnight (Shift.php:92-95). | W |
| SHF-04 | Buat shift **Nol** 08:00–17:00 toleransi 0. | Tersimpan (minValue 0). | W |

## 4. Jadwal (harian, mingguan, libur)

| ID | Aktor | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|---|
| JDW-01 | PIC | Jadwal → Atur Jadwal, 16–22 Nov, Senin–Jumat, Pagi, overwrite off. Sebelumnya Rabu 18 sudah diset Libur. | Notifikasi "Baru: 4, diperbarui: 0, dilewati: 1". Rabu tetap Libur, Sabtu/Minggu kosong (ManageShiftSchedules.php:350-376). | W |
| JDW-02 | PIC | Per hari via app (team/assign): set Siang untuk besok. | Grid mingguan menampilkan Siang. Jadwal bertanggal mengalahkan shift default (WorkScheduleService.php:45-55). | A |
| JDW-03 **[BUG SCH-5, khusus SQLite]** | PIC | Salin minggu 16 Nov → 23 Nov (ada jadwal Senin 16 dan Minggu 22). | Muncul "2 jadwal disalin" dengan baris 23 dan 29 Nov. MySQL lokal seharusnya OK. Di SQLite probe hanya menyalin 1 (ScheduleCopyService.php:37). | B |
| JDW-04 | PIC | Ganti jadwal hari ini dari Pagi ke Siang setelah freelancer check-in 08:10. | Baris absensi tetap shift Pagi, on_time, 0 (snapshot saat check-in). Catat isi Rekap Tim di hari itu. | B |

## 5. Absensi harian (Android)

| ID | Prasyarat | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|---|
| ABS-01 | Pagi 08:00 tol 15 | Check-in **08:15:00**. | on_time, late 0, badge "Tepat Waktu". Laporan "Hadir". | A |
| ABS-02 | Pagi 08:00 tol 15 | Check-in **08:16**, pulang 16:00. | late, **16** menit. App "Telat 16 mnt". Laporan "Terlambat (16m)". Rekap: late_days 1, late_minutes 16, Jam Kerja 7,73. | A |
| ABS-03 **[BUG BUG-06, P3]** | Nol tol 0 | Check-in **08:00:45**. | Seharusnya late 1, atau ada aturan yang konsisten. Aktual: on_time, 0, karena detik dibuang dengan cast (int) (WorkScheduleService.php:112-116). Pembanding: tol 15 jam 08:15:30 → late 15. | A |
| ABS-04 | Split, GPS off | Masuk 07:50, pulang 13:05, coba masuk 16:30, lalu masuk 18:30, pulang 22:00. | Seg1 on_time 0. 16:30 → 422 "Segmen kedua dimulai pukul 17:45. Check in dibuka pukul 16:45." Seg2 late **45**. Rekap: Hari Masuk 1, late_days 1, late 45. | A |
| ABS-05 | Malam 22:00 tol 15, dijadwalkan D | Masuk D 22:20, pulang D+1 06:05. | Baris bertanggal D, late **20**. Check-out menutup baris D (check_out D+1 06:05). Jam 7,75. Setelah 00:00 app tetap menawarkan "Absen Pulang". | A |
| ABS-06 **[FIXED-WT BUG-01 / SCH-1 / CO BUG-02, P0]** | Malam dijadwalkan D dan D+1, belum check-in di D | Check-in D+1 **00:30**, pulang 06:00. Malamnya 21:55 check-in lagi. | Baris bertanggal **D**, late **150**, "Telat 150 mnt". Check-in 21:55 → 200 untuk shift D+1. Kode di working tree: AttendanceCheckService.php:204 `overnightCheckInDate`, :251 `lateMinutes(..., $businessDate)`, WorkScheduleService.php:113 `setDateFrom($businessDate)`. **Di HEAD/staging:** baris D+1, on_time, 0, lalu 21:55 → 409. Inilah penyebab utama keluhan "Tepat Waktu". | A |
| ABS-07 | Malam hanya di D, tanpa shift default | Check-in D+1 01:45. | Working tree: baris D, late **225**. HEAD: baris D+1, on_time, shift_id null. | A |
| ABS-08 **[BUG CO BUG-03]** | Malam D-1 dan D. Shift D-1 ditutup pukul 06:00 | Pukul 06:05 tekan "Absen Masuk" yang langsung ditawarkan app. Malamnya 22:00 check-in. | Seharusnya ditolak karena shift baru mulai 22:00. Aktual: diterima pukul 06:05 dengan on_time; check-in 22:00 → 409. Segmen 1 tidak punya jendela buka (hanya segmen 2 yang punya, :234). | A |
| ABS-09 **[BUG CO BUG-04]** | Sore 15:00–23:00 D dan D+1. Masuk D 15:00, tidak pulang | D+1 01:00 terima push "belum absen pulang", lalu buka app. | Seharusnya app menawarkan Absen Pulang untuk D. Aktual: today mengembalikan has_checked_in=false, tombol yang tampil "Absen Masuk". Kalau ditekan, terbentuk baris D+1 01:00 on_time 0, dan baris D tetap terbuka. | A |
| ABS-10 **[PRODUK SCH-2/BUG-05/CO BUG-07]** | Hari ini Libur, shift default Pagi | Check-in 11:00. | Aktual: 200, on_time, 0, shift_id null. TodayCard menampilkan "Hari ini dijadwalkan libur" tetapi tombol Absen Masuk tetap ada. Keputusan produk: tolak, minta approval, atau beri status "Masuk Hari Libur". | A |
| ABS-11 **[PRODUK PFO-B7/SCH-6]** | Freelancer dari app (tanpa shift default), tanpa jadwal | Check-in 07:30. Freelancer lain check-in 08:16. | 07:30 → on_time (dinilai dengan jam BU 08:00). 08:16 → late 16. Tidak ada peringatan "jadwal belum diatur". **Ini sumber kedua "Tepat Waktu" palsu**, yang terjadi bila PIC lupa membuat jadwal. | A |
| ABS-12 | Policy GPS on, butuh approval di luar radius. Pagi 08:00 | Check-in 08:40 dari luar geofence. Cek app dan Rekap, lalu Approve. | Sebelum approve: pending_approval, late_minutes 0, check_in_late_minutes 40, app "Menunggu Persetujuan". Setelah approve: late **40** (Attendance.php:173-190). | A |
| ABS-13 **[BUG BUG-03 sebagian]** | ABS-12 lalu Tolak | Freelancer buka Absen Hari Ini di web. | Seharusnya badge merah "Ditolak". Aktual: badge hijau "Tepat Waktu" karena tidak ada cabang Rejected (absen-hari-ini.blade.php:186-198, presensi-widget.blade.php:76-85). Riwayat di app sudah benar menampilkan "Ditolak". | W |
| ABS-14 **[BUG BUG-07 / CO BUG-01]** | Pagi 08:00–16:00 | Masuk 08:00, pulang 10:00. | Seharusnya ada tanda pulang cepat 360 menit. Aktual: on_time, tanpa flag. Hanya worked 120 menit yang mencerminkan selisihnya (AttendanceCheckService.php:344-409). | A |
| ABS-15 | Sudah check-in | Tap "Kirim Absen Pulang" dua kali dengan cepat, lalu kirim lagi dari layar baru. | Hanya satu check-out tercatat. Pengiriman kedua → 409 "Sudah melakukan check out hari ini." | A |
| ABS-16 | Mode pesawat | Kirim check-out, lalu online dan kirim ulang. | Kirim pertama langsung gagal (offline) dan tidak diantrikan. check_out yang tercatat adalah jam saat online. | A |
| ABS-17 **[BUG CO BUG-06, perlu cek device]** | Masuk dan pulang di hari D, app di-background (tidak di-kill) | Buka lagi D+1 pukul 07:50 tanpa pull-to-refresh. | Seharusnya kartu menampilkan D+1 dengan tombol Absen Masuk. Dugaan: kartu masih D dengan "Tepat Waktu", karena focusManager tidak terhubung ke AppState (OfflineBanner.tsx:21, queryClient.ts:5-21). | A |

## 6. Lembur

| ID | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|
| OVT-01 | Freelancer: Lembur 16 Nov 17:00–20:00, alasan "stok opname". | 201, duration 180, pending, 1 step approval. | A |
| OVT-02 | Lembur 22:00–02:00. | 201, duration 240, "(melewati tengah malam)". | A |
| OVT-03 **[BUG OVT-2]** | Kirim lagi 17:00–20:00, lalu 18:00–19:00 di tanggal yang sama. | Seharusnya 422. Aktual: 201 dua-duanya. | A |
| OVT-04 **[BUG OVT-5]** | PUT pending 17:00–19:00 dengan `{end_time:"17:00"}` saja. | Seharusnya 422. Aktual: 200, duration **1440**. | API |
| OVT-05 | Flow 2 tahap, Koordinator approve. | Kedua tahap approved, status approved, push terkirim. Setelah tahap 1, edit atau batal → 422 "sudah mulai diproses". | A |
| OVT-06 **[BUG OVT-3]** | Web create 17:00–18:00, lalu ketik Estimasi 600 dan Aktual 600. | Seharusnya 60. Aktual: tersimpan 600. | W |
| OVT-07 **[BUG OVT-4]** | Freelancer login web, buka lembur approved miliknya (180), Isi Durasi Aktual = 600. | Seharusnya aksi tersembunyi. Aktual: tersimpan 600. | W |
| OVT-08 **[BUG OVT-6]** | Admin edit lembur approved, ubah 20:00 menjadi 23:00 dengan durasi 360. | Seharusnya diblokir atau kembali pending. Aktual: tetap approved. | W |

## 7. Koreksi absensi

| ID | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|
| COR-01 | Shift tol 10: koreksi masuk 08:10, 08:11, 09:30 di tiga tanggal berbeda, lalu approve. | on_time 0 / late 11 / late 90, is_corrected=1. | A+W |
| COR-02 **[BUG COR-1]** | Shift Malam tol 10: koreksi tanggal D masuk 00:30 pulang 06:00, approve. | Seharusnya late 150 di malam D. Aktual: on_time 0, check_in D 00:30 (sebelum shift mulai). | A+W |
| COR-03 **[BUG COR-4]** | Hari ini 17 Nov: koreksi tanggal 25 Nov 08:00–17:00. | Seharusnya 422. Aktual: 201, dan setelah approve terbentuk absensi on_time 540 menit di masa depan. | A |
| COR-04 **[BUG COR-3 / BUG-08]** | Absensi pending geofence (09:00, check_in_late_minutes 50), ajukan koreksi masuk 08:00, approve. | Seharusnya tetap pending, atau koreksi ditolak. Aktual: on_time, dan step geofence jadi yatim (tidak bisa diproses lagi). | A+W |
| COR-05 **[BUG BUG-08]** | Absensi Ditolak (08:05), koreksi masuk 08:05, approve. | Aktual: on_time, rejection_reason lama tetap tersimpan. Perlu keputusan produk apakah koreksi boleh membatalkan penolakan. | A+W |
| COR-06 **[BUG BUG-02]** | PIC/Admin di Data Absensi: Override check-in 07:55 menjadi 09:30, status dibiarkan. | Seharusnya late 90. Aktual: on_time, 0 (AttendanceResource.php:71-90, tidak ada recompute). | W |

## 8. Laporan / rekap untuk penggajian

| ID | Prasyarat | Langkah | Hasil diharapkan | Plat. |
|---|---|---|---|---|
| RPT-01 | ABS-02 (masuk 08:16, pulang 16:00, Pagi 8 jam) | Laporan Absensi, Rekap Tim (web dan app), unduh Excel. | Rekap: Terjadwal 1, Masuk 1, Terlambat 1, Telat 16, Jam Terjadwal 8, Jam Kerja 7,73, Tidak Hadir 0. Baris di app: "Masuk 1/1 · 1x telat · 7,73 jam". | B |
| RPT-02 | ABS-04 split | Rekap. | Masuk 1 (bukan 2), Jam Terjadwal 9,25, Jam Kerja = (13:05−07:50)+(22:00−18:30) = 8,75. | B |
| RPT-03 | ABS-05 malam | Rekap D dan D+1. | 1 hari masuk di D dengan Jam 7,75, tanpa hari hadir di D+1. | B |
| RPT-04 **[FIXED-WT RPT-B1]** | ABS-13 (ditolak, 08:00–17:00) | Rekap Tim dan ringkasan API. | Working tree: Masuk 0, Tidak Hadir 1, Jam 0 (TeamRecapService.php ~128: baris Rejected dilewati). HEAD: Masuk 1, Jam 9 (ikut dibayar). | B |
| RPT-05 **[BUG RPT-B2 / BUG-04]** | ABS-12 sebelum approve | Rekap Tim dan ringkasan API. | Seharusnya ada tanda pending atau terlambat 40. Aktual: Masuk 1, late 0, total_late 0. Badge Laporan sudah benar "Menunggu Persetujuan" (LaporanAbsensi.php:344-354). | B |
| RPT-06 **[FIXED-WT SCH-4 / RPT-B3]** | Pagi terjadwal 17 Nov, cuti approved | Rekap 18 Nov. | Working tree: leave_days 1, absent 0. HEAD: absent 1. | B |
| RPT-07 **[BUG RPT-B4 / OVT-1]** | Masuk 08:00 pulang 19:00, lembur 17–19 approved | Rekap dan Excel. | Seharusnya ada kolom Lembur 2 jam. Aktual: tidak ada kolom lembur, Jam Kerja 11. Kalau lembur ditolak, hasilnya tetap 11 (identik). | B |
| RPT-08 **[BUG RPT-B5]** | Tanpa jadwal Senin–Jumat, Sabtu/Minggu terjadwal tapi tidak hadir | App Riwayat, rentang Senin–Minggu. | Seharusnya Absen 2. Aktual: total_absent **7** (ExpectedWorkDays fallback ke hari kerja Senin–Jumat). Rekap Tim: 2. | A |
| RPT-09 **[BUG RPT-B7]** | ABS-10 (masuk di hari Libur 08–17) | Rekap. | Seharusnya dihitung 1 hari saja. Aktual: Libur 1 dan Masuk 1, Terjadwal 0, app menampilkan "Masuk 1/0". | B |
| RPT-10 **[BUG RPT-B8]** | Jadwal tanpa shift, atau shift yang sudah dihapus | Rekap. | Seharusnya Jam Terjadwal 9. Aktual: 0. | B |
| RPT-11 **[PRODUK RPT-B9]** | Masuk 08:00 tanpa pulang | Rekap dan Excel. | Masuk 1, Jam 0, Pulang "-". Tidak ada kolom "Tanpa Absen Pulang". | B |
| RPT-12 | Absensi 31 Okt, 1 Nov, dan malam 30 Nov (pulang 1 Des) | Rekap November. | Masuk 2 (1 dan 30 Nov), 31 Okt tidak ikut, 30 Nov dihitung 8 jam. | B |

---

## Bug terkonfirmasi (probe gagal pada working tree saat ini)

| ID | Sev | Inti | Bukti |
|---|---|---|---|
| BUG-02 | P1 | Override check-in di panel tidak menghitung ulang status dan menit telat (09:30 tetap on_time) | AttendanceResource.php:71-90, Attendance.php:57-61. Probe_lateness_BUG02Test:31 |
| BUG-03 (web) | P1 | Absensi Ditolak tampil hijau "Tepat Waktu" di Absen Hari Ini dan widget | absen-hari-ini.blade.php:186-198 (else → "Tepat Waktu" :198), presensi-widget.blade.php:84. Probe_lateness_BUG03Test:93 |
| BUG-04 / RPT-B2 | P1 | Absensi pending dihitung hadir dan tidak telat di Rekap dan API | AttendanceCheckService.php:242-250, TeamRecapService.php (sum late_minutes). Probe_lateness_BUG04Test:122, Probe_payroll_report_RPTB2Test:66,77 |
| BUG-06 | P3 | Toleransi 0: telat sampai 59 detik tetap on_time | WorkScheduleService.php:112-116. Probe_lateness_BUG06Test:90 |
| BUG-07 / CO BUG-01 | P2 | Pulang cepat tidak dicatat | AttendanceCheckService.php:344-409. Probe_lateness_BUG07Test:31 |
| BUG-08 / COR-3 | P1 | Koreksi yang di-approve menimpa status Rejected atau pending geofence jadi on_time | AttendanceCorrection.php:146-155. Probe_lateness_BUG08Test:101,114, Probe_overtime_BUGCOR3Test:82 |
| COR-1 | P1 | Koreksi shift malam dengan jam masuk lewat tengah malam tercatat on_time | AttendanceCorrection.php:44-61,149-152. Probe_overtime_BUGCOR1Test:32 |
| COR-4 | P1 | Koreksi untuk tanggal masa depan diterima | StoreAttendanceCorrectionRequest.php:18. Probe_overtime_BUGCOR4Test:53,68 |
| CO BUG-03 | P1 | Segmen 1 tanpa jendela buka: check-in 06:05 untuk shift 22:00 diterima on_time dan memblokir shift sebenarnya | AttendanceCheckService.php:234 (jendela hanya untuk segmen 2). Probe_checkout_mobile_BUG03Test:29 |
| CO BUG-04 | P1 | Lupa pulang di shift siang: push meminta pulang, app malah menawarkan Absen Masuk yang tercatat on_time | AttendanceApiController.php:235-237, AttendanceReminderService.php:197-198,234. Probe_checkout_mobile_BUG04Test:59 |
| PFO-B1 | P1 | start_date kontrak tidak dicek saat check-in | WorkerAccountService.php:119-130. Probe_pic_onboarding_PFOB1Test:77 |
| PFO-B3 | P1 | Hapus freelancer ikut menghapus riwayat absensi, lembur, dan cuti | migrasi 2026_07_27_150839:14, 2026_07_28_092921:13,21. PFOB3Test:30 |
| PFO-B4 | P2 | Nomor HP duplikat diterima, login OTP WhatsApp jadi rusak | StoreTeamFreelancerRequest.php:20-23, User.php:96-111. PFOB4Test:66 |
| PFO-B6 | P2 | Tanggal selesai boleh sebelum tanggal mulai | FreelanceResource.php:176-182. PFOB6Test:77 |
| PFO-B8 | P2 | Akun karyawan bisa ditautkan ke freelancer | ScopesToManagedCompanies.php:348-352. PFOB8Test:67 |
| OVT-1 / RPT-B4 | P0 bisnis | Lembur tidak muncul di laporan gaji mana pun | AttendanceRecapSheet.php:35, TeamRecapService export headers. Probe_overtime_BUGOVT1Test:31, RPTB4Test:28 |
| OVT-2 | P1 | Lembur duplikat atau tumpang tindih diterima | StoreOvertimeRequest.php:19-24. BUGOVT2Test:58,70 |
| OVT-3 | P1 | Form web memakai durasi yang diketik user (600 untuk 1 jam) | OvertimeRequestResource.php:159-170, CreateOvertimeRequest.php:16-38. BUGOVT3Test:56 |
| OVT-4 | P1 | "Isi Durasi Aktual" tanpa otorisasi | OvertimeRequestResource.php:286-310. BUGOVT4Test:22 |
| OVT-5 | P2 | PUT hanya end_time = start menghasilkan lembur 1440 menit | UpdateOvertimeRequest.php:22. BUGOVT5Test:58 |
| OVT-6 | P2 | Lembur approved bisa diedit tanpa approval ulang | OvertimeRequestResource.php:77-80. BUGOVT6Test:28 |
| RPT-B5 | P1 | Hitungan absen di API memakai hari kerja Senin–Jumat, bukan roster (7 vs 2) | ExpectedWorkDays.php:82-86. RPTB5Test:28 |
| RPT-B7 | P2 | Masuk di hari libur dihitung dua kali (Libur dan Masuk) | TeamRecapService.php (blok off_days dan present). RPTB7Test:27 |
| RPT-B8 | P2 | Jam Terjadwal 0 untuk jadwal tanpa shift atau shift yang dihapus | TeamRecapService.php (`shift_id !== null ? … : 0`). RPTB8Test:66,81 |
| SCH-5 | P2 (SQLite saja) | Salin minggu tidak menyalin hari Minggu | ScheduleCopyService.php:37. BUGSCH5Test:24 |
| CO BUG-06 | P2 | Kartu "hari ini" tidak refetch saat app kembali aktif (dikonfirmasi dari kode, belum dicek di device) | OfflineBanner.tsx:21, queryClient.ts:5-21 |

**Sudah diperbaiki di working tree tetapi belum di-commit (probe sekarang lulus; wajib dites ulang di staging setelah deploy):**
- BUG-01 / SCH-1 / CO BUG-02 (P0): check-in shift malam setelah 00:00 tercatat on_time. Perbaikannya ada di AttendanceCheckService.php:204,219,251 dan WorkScheduleService.php:113.
- RPT-B1 dan bagian TeamRecap dari BUG-03: absensi ditolak tidak lagi dihitung hadir.
- SCH-4 / RPT-B3: cuti sekarang dihitung leave_days.
- PFO-B2 dan PFO-B5: edit status kontrak sekarang menyinkronkan akun.

**Keputusan produk (perilaku disengaja, bukan bug kode):** BUG-05/SCH-2/COR-2 (masuk di hari libur dicatat "Tepat Waktu"), PFO-B7/SCH-6 (tanpa jadwal → dinilai dengan jam BU), SCH-3 (Hari Libur nasional: belum ada UI untuk membuatnya), RPT-B9 (lupa pulang tetap dihitung hadir), CO BUG-05 (baris tanpa check-out tidak ditutup otomatis).

**Dibantah (satu baris per item):**
- RPT-B6: shift malam lewat tengah malam sudah benar di working tree (late 130, tanggal D).
- RPT-B10: absent_rows di API sekarang memakai MAX, tidak lagi double count.
- Bagian "badge Laporan pending/rejected = Hadir" dari RPT-B2/BUG-03: LaporanAbsensi.php:344-354 sudah memakai label enum.
- Bagian "buat absensi manual" dari BUG-02: halaman Create tidak di-route (AttendanceResource.php:390-395).
- AttendanceRecapSheet menghitung baris Rejected: sudah dilewati (AttendanceRecapSheet.php:317-318).

**Penyebab utama keluhan "Tepat Waktu padahal telat":**
1. Shift malam yang check-in lewat 00:00 (P0). Sudah diperbaiki di working tree, belum ada di HEAD/staging.
2. Freelancer tanpa jadwal atau shift default dinilai dengan jam BU 08:00.
3. Check-in jauh sebelum shift diterima sebagai on_time (CO BUG-03, CO BUG-04).
4. Override di panel dan koreksi tidak menghitung ulang, atau malah menimpa status.
5. Masuk di hari libur dicatat "Tepat Waktu".
6. Absensi ditolak tampil hijau di web, dan absensi pending dihitung tepat waktu di rekap.

Untuk device: jalankan ABS-06, ABS-08, ABS-09 (perlu jam lewat tengah malam), ABS-11, COR-02, dan ABS-17 di Android dengan backend lokal setelah perubahan working tree aktif.
