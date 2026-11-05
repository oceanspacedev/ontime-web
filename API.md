# OnTime REST API v1

Dokumentasi API untuk aplikasi mobile dan integrasi pihak ketiga. Dokumen ini menggabungkan `apiDocs.md`, `DOKUMENTASI_PENGGUNA_DAN_API.md` (bagian 2), dan `REKAP_LENGKAP_API_DAN_HALAMAN_MOBILE.txt`, lalu dicocokkan ulang dengan kode. Jika ada perbedaan, yang berlaku adalah `routes/api.php`, `app/Http/Controllers/Api/V1/`, `app/Http/Requests/Api/V1/`, dan `app/Http/Resources/Api/V1/`.

---

## 1. Ringkasan

### 1.1 Base URL dan header

| Lingkungan | Base URL |
| :--- | :--- |
| Server | `{APP_URL}/api/v1` |
| Lokal (web / iOS Simulator) | `http://127.0.0.1:8000/api/v1` |
| Lokal (Android Emulator) | `http://10.0.2.2:8000/api/v1` |
| HP fisik di Wi-Fi yang sama | `http://<IP-laptop>:8000/api/v1` |

| Header | Nilai |
| :--- | :--- |
| `Accept` | `application/json` |
| `Authorization` | `Bearer <token>` (semua endpoint kecuali login) |
| `Content-Type` | `application/json`, atau `multipart/form-data` untuk unggah foto/lampiran |

- Autentikasi memakai Laravel Sanctum (personal access token). Token didapat dari `POST /auth/login` dan dihapus lewat `POST /auth/logout`. Token tidak kedaluwarsa otomatis.
- Semua path di bawah `api/*` selalu membalas JSON, termasuk error.
- PHP tidak membaca `multipart/form-data` pada request `PUT`. Untuk mengubah pengajuan sambil mengunggah file, kirim `POST` ke URL yang sama dengan field `_method=PUT`.

### 1.2 Format tanggal dan waktu

| Sumber | Format di respons |
| :--- | :--- |
| Tanggal presensi, cuti, lembur, koreksi (`date`, `start_date`, `end_date`) | `YYYY-MM-DD`, contoh `2026-08-01` |
| Waktu (`check_in`, `check_out`, `corrected_check_in/out`, `created_at`, `updated_at`) | ISO-8601 UTC, contoh `2026-08-03T01:05:00.000000Z` (= 08.05 WIB). Konversi ke `Asia/Jakarta` sebelum ditampilkan. |
| Lembur `start_time`, `end_time` | String jam apa adanya (`17:00` saat baru dibuat, `17:00:00` saat dibaca ulang) |
| Resign `resignation_date`, `last_working_day`; kalender cuti `start_date`, `end_date` | `YYYY-MM-DD` |
| Koordinat presensi (`check_in_lat`, dll.) | String desimal 7 digit, contoh `"-6.1753924"` |

### 1.3 Tautan file

`check_in_photo`, `check_out_photo`, `attachment`, dan `master_face_photo` di `/auth/me` adalah URL yang langsung bisa dibuka. Untuk disk privat (`FILESYSTEM_DISK=local` atau s3), URL-nya bertanda tangan dan berlaku 7 hari, jadi jangan disimpan permanen di aplikasi.

### 1.4 Format error

| Status | Kapan | Bentuk body |
| :--- | :--- | :--- |
| 400 | Hapus foto master wajah yang belum pernah didaftarkan | `{"message": "..."}` |
| 401 | Token tidak ada/tidak valid; login gagal | `{"message": "Unauthenticated."}` / `{"message": "Credentials do not match our records."}` |
| 403 | Izin `can:` tidak dimiliki; detail data di luar akses | `{"message": "This action is unauthorized."}` / `{"message": "Unauthorized access."}` |
| 404 | Data tidak ditemukan | `{"message": "..."}` |
| 409 | Check-in/check-out kedua di hari yang sama | `{"message": "Sudah melakukan check in hari ini.", "errors": {"attendance": ["..."]}}` |
| 422 | Validasi input | `{"message": "...", "errors": {"field": ["..."]}}` |
| 422 | Aturan bisnis (kuota habis, status bukan pending, profil tidak ada) | `{"message": "..."}`; check-in/check-out dan tantangan liveness juga mengirim `errors` (lihat 3.2) |
| 503 | Layanan verifikasi wajah (CompreFace) tidak tersedia atau belum dikonfigurasi | `{"message": "...", "errors": {"photo": ["..."]}}` |

### 1.5 Format paginasi

Ada tiga bentuk, tergantung endpoint:

| Bentuk | Dipakai di | Struktur |
| :--- | :--- | :--- |
| A | `/attendances` (20/hal), `/laporan-absensi` (30/hal) | `{"data": [...], "pagination": {"current_page", "last_page", "per_page", "total"}}` |
| B | `/leave-requests`, `/overtime-requests`, `/attendance-corrections`, `/resignations` (15/hal) | `{"data": [...], "pagination": {"current_page", "last_page", "total"}}` |
| C | `/master/employees`, `/master/interns`, `/master/freelancers`, `/master/users` (20/hal) | Paginator Laravel di dalam `data`: `{"data": {"current_page", "data": [...], "per_page", "total", "last_page", "next_page_url", ...}}` |

Halaman dipilih dengan query `?page=2`. Endpoint lain tidak berpaginasi.

---

## 2. Akses data dan otorisasi

### 2.1 Role

| Role | Fungsi |
| :--- | :--- |
| Superadmin | Akses penuh. Data akses selalu `all`. Lolos semua cek izin. |
| Admin | Admin Badan Usaha: mengelola pekerja dan memproses persetujuan di dalam data aksesnya. Izinnya diatur lewat Shield. |
| BOD, Approver | Memproses tahap persetujuan sesuai role-nya, terbatas pada data aksesnya. |
| Employee | Karyawan: presensi, cuti/izin, lembur, koreksi, resign. |
| Intern | Peserta magang: presensi, cuti/izin, lembur, koreksi. |
| Freelancer | Pekerja lepas: presensi, izin/sakit, lembur, koreksi. |

Fitur pekerja (presensi dan pengajuan) butuh akun yang terhubung ke profil `employee`, `intern`, atau `freelancer`. Tanpa profil, endpoint tersebut membalas 422 `Profil pengguna tidak ditemukan.` dan daftar pengajuan kosong.

### 2.2 Data akses (`users.data_scope`)

| Nilai | Label | Pekerja yang tercakup |
| :--- | :--- | :--- |
| `all` | Semua | Seluruh badan usaha |
| `companies` | Badan Usaha | Badan usaha yang dipilih (`company_user`) beserta semua divisinya |
| `divisions` | Divisi | Divisi yang dipilih (`division_user`) termasuk semua sub-divisinya |
| `own` | Pribadi | Hanya data milik sendiri (nilai bawaan akun baru) |

Superadmin selalu `all`. Setiap pengguna selalu bisa melihat data miliknya sendiri. Logika ada di `app/Services/DataAccessService.php`.

### 2.3 Penerapan di endpoint

| Endpoint | Data yang dikembalikan |
| :--- | :--- |
| `GET /leave-requests`, `/overtime-requests`, `/attendance-corrections` | Hanya milik sendiri. Ini daftar pribadi beserta ringkasan kuota; pengajuan tim ada di `GET /approvals/pending` |
| `GET /leave-requests/{id}`, `/overtime-requests/{id}`, `/attendance-corrections/{id}`, `GET /resignations`, `/resignations/{id}` | Milik sendiri + pekerja dalam data akses. Detail di luar itu: 403 |
| `PUT`/`DELETE` pengajuan | Hanya milik sendiri yang masih `pending` |
| `GET /attendances`, `/attendances/{id}`, `/laporan-absensi` | Milik sendiri + pekerja dalam data akses, sama dengan panel. Detail di luar itu: 403 |
| `GET /approvals/pending` | Pengajuan yang boleh diproses pemanggil (lihat 2.4) |
| `GET /kalender-cuti` | Pekerja dalam data akses + rekan di badan usaha sendiri. `all`: semua. Peserta magang: 403, sama seperti di panel |
| `/master/companies`, `company-locations`, `company-policies`, `divisions`, `approval-flows` | Badan usaha yang terlihat: `all` semua; selain itu badan usaha profil sendiri + badan usaha terpilih (atau badan usaha dari divisi terpilih) |
| `/master/employees`, `interns`, `freelancers` | Pekerja dalam data akses + profil sendiri |
| `/master/users` | Akun sendiri + akun pekerja dalam data akses |
| `/master/approvers` | Akun Approver/BOD yang lolos aturan `/master/users`, lalu disaring `company_id`/`division_id` |
| `/master/job-titles`, `job-levels`, `holidays` | Tidak difilter |

Middleware izin: route `/master/*` dan `/shield/*` memakai `can:ViewAny:<Model>` (izin Spatie/Shield). Endpoint lain hanya butuh token; izin seperti `Create:LeaveRequest` tidak diperiksa oleh API, jadi aplikasi mobile perlu memakai daftar `permissions` dari `/auth/me` untuk menampilkan menu.

### 2.4 Hak memproses persetujuan

Aturan di `ApprovalFlowService::isUserAuthorizedToApprove`, dicek berurutan:

1. Pengajuan harus berstatus `pending` (atau `pending_approval` untuk presensi) dan hanya tahap aktif (`current_step`) yang bisa diproses.
2. Pengaju tidak pernah memberi persetujuan tahap terakhir atas pengajuannya sendiri. Tahap tengah yang cocok dengan role-nya masih boleh.
3. Superadmin: tahap apa pun, kecuali pengajuan sendiri.
4. Admin: tahap apa pun untuk pekerja dalam data aksesnya, kecuali pengajuan sendiri.
5. Tahap bertipe `user`: hanya user yang ditunjuk.
6. Tahap bertipe `role`: pemanggil punya role tahap tersebut **dan** penempatan pengaju (badan usaha atau divisi) ada dalam data aksesnya. Approver/BOD dengan akses `own` tidak menerima antrean bertipe role.

Satu tahap selesai bila salah satu user yang memenuhi syarat memprosesnya. Alur bawaan badan usaha baru: Approver, lalu BOD, lalu Superadmin untuk `leave`, `overtime`, `correction`, `resignation`; satu tahap Approver untuk `geofence`. Jika alur untuk jenis pengajuan belum dikonfigurasi, pembuatan pengajuan membalas 422 dengan `errors.approval_flow`.

---

## 3. Endpoint

### 3.1 Autentikasi

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| POST | `/auth/login` | Publik | Login dengan kata sandi, mengembalikan token |
| POST | `/auth/otp/request` | Publik | Kirim kode OTP ke WhatsApp atau email, mengembalikan `challenge_id` |
| POST | `/auth/otp/verify` | Publik | Tukar `challenge_id` + kode OTP dengan token |
| GET | `/auth/me` | Token | Profil akun saat ini |
| POST | `/auth/logout` | Token | Menghapus token yang sedang dipakai |

**POST /auth/login** (JSON): `email` (wajib, email), `password` (wajib).

```json
{
  "message": "Login successful",
  "token_type": "Bearer",
  "token": "<token>",
  "user": {
    "id": 12,
    "name": "Karyawan Contoh",
    "email": "karyawan@contoh.id",
    "master_face_photo": null,
    "master_face_verified_at": null,
    "roles": ["Employee"],
    "permissions": ["View:AbsenHariIni", "Create:LeaveRequest", "View:KalenderCuti"],
    "employee": {
      "id": 7,
      "nip": "2026.01.001",
      "full_name": "Karyawan Contoh",
      "master_face_photo": null,
      "master_face_verified_at": null,
      "company": {
        "id": 1, "name": "PT Contoh Sejahtera", "latitude": -6.2, "longitude": 106.8,
        "locations": [{"id": 1, "name": "Kantor Pusat", "latitude": -6.2, "longitude": 106.8, "radius_meters": 100}],
        "policy": {"require_gps": true, "require_photo": true, "require_face_recognition": false,
                   "require_liveness": false, "selfie_mode": "photo", "mock_location_action": "reject",
                   "face_match_threshold": 60, "face_fail_action": "reject", "mode_label": "GPS + Foto",
                   "late_tolerance_minutes": 15, "work_start_time": "08:00:00", "work_end_time": "17:00:00"}
      },
      "division": {"id": 3, "name": "Operasional"},
      "job_title": {"id": 5, "name": "Staf Administrasi"},
      "job_level": {"id": 2, "name": "Staff"},
      "has_annual_leave": true,
      "annual_leave_quota": 12
    },
    "intern": null,
    "freelancer": null
  }
}
```

- `intern` berisi `id, full_name, institution, master_face_photo, master_face_verified_at, company, division, mentor {id, full_name}`.
- `freelancer` berisi `id, full_name, institution, master_face_photo, master_face_verified_at, company, division, supervisor {id, full_name}`.
- `employee.has_annual_leave` bernilai `true` bila HR memberi karyawan ini cuti tahunan, dan `employee.annual_leave_quota` berisi kuota per tahun yang berlaku (0 tanpa cuti tahunan). Tampilkan jenis Cuti Tahunan dan kartu kuota hanya bila `has_annual_leave` bernilai `true`. Objek `intern` dan `freelancer` tidak punya field ini karena keduanya tidak pernah mendapat cuti tahunan.
- `GET /auth/me` membalas `{"user": {...}}` dengan isi yang sama. `data_scope` tidak ikut dikirim.
- `company.policy` menentukan mode presensi (GPS, foto, wajah, liveness). Arti tiap field ada di 3.2.
- Logout membalas `{"message": "Logged out successfully"}`.
- Akun nonaktif (`is_active = false`, misalnya setelah resign disetujui) ditolak saat login dengan 403 `Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.` Token yang sudah ada langsung tidak berlaku dan membalas 401.

#### Login dengan kode OTP

Alternatif tanpa kata sandi, memakai kanal yang sama dengan halaman login web: kode 6 digit lewat WhatsApp (WhatsApp Gateway harus dikonfigurasi) atau lewat email (mailer sungguhan harus dikonfigurasi). Dua langkah:

**POST /auth/otp/request** (JSON): `channel` (wajib, `whatsapp` atau `email`), lalu `phone` (wajib jika `channel = whatsapp`, nomor Indonesia dalam format apa pun, misalnya `0812-3456-7890`) atau `email` (wajib jika `channel = email`).

```json
{
  "message": "Kode OTP dikirim ke WhatsApp.",
  "channel": "whatsapp",
  "challenge_id": "otp_…",
  "expires_in": 300
}
```

- `challenge_id` mewakili satu permintaan kode dan berlaku selama `expires_in` detik (WhatsApp `WHATSAPP_OTP_TTL_MINUTES`, bawaan 5 menit; email `EMAIL_LOGIN_EXPIRES_IN`, bawaan 600 detik). Simpan di klien, jangan ditampilkan.
- Jawaban tidak membocorkan apakah nomor atau email terdaftar. Kode WhatsApp dikirim ke nomor valid mana pun; kode email hanya benar-benar dikirim jika alamatnya milik tepat satu akun, tetapi klien tetap menerima `challenge_id` dan pesan `Jika email terdaftar, kode OTP sudah dikirim ke email tersebut.`
- Meminta kode baru untuk nomor atau email yang sama membatalkan kode sebelumnya.
- 422 jika `channel` bukan `whatsapp`/`email`, nomor HP tidak valid (`errors.phone`), atau alamat email tidak valid (`errors.email`).
- 429 `Terlalu banyak permintaan OTP. Silakan coba lagi nanti.` dengan header `Retry-After` dan `retry_after` (detik) bila batas terlampaui: per IP 10 permintaan/menit (kedua kanal), per nomor 3 kode/2 menit, per email 3 kode/5 menit dan 10 kode/jam.
- 503 jika kanal belum dikonfigurasi di server (`Login dengan WhatsApp belum tersedia…` / `Login dengan email belum tersedia…`) atau pengiriman gagal (`Kode OTP belum bisa dikirim. Coba lagi sebentar lagi.`).

**POST /auth/otp/verify** (JSON): `challenge_id` (wajib), `code` (wajib, 6 digit).

Berhasil membalas 200 dengan isi yang persis sama seperti `POST /auth/login` (`message`, `token_type`, `token`, `user`). Satu kode hanya bisa dipakai sekali.

- 401 `Kode OTP tidak valid atau sudah kedaluwarsa.` untuk kode salah, kode atau `challenge_id` yang kedaluwarsa, `challenge_id` yang tidak dikenal, dan email yang tidak terdaftar.
- 401 `Nomor belum terdaftar untuk login. Hubungi administrator.` jika kode WhatsApp benar tetapi nomornya tidak tercatat pada tepat satu akun (kolom `phone` user, karyawan, magang, atau freelancer).
- 403 `Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.` jika kode benar tetapi akunnya nonaktif. Tidak ada token yang dibuat.
- 429 dengan `Retry-After` setelah 5 kode salah untuk satu `challenge_id` WhatsApp (`Terlalu banyak percobaan kode yang salah. Minta kode OTP baru.`), 15 percobaan/5 menit per email, atau 20 percobaan/menit per IP. Kode email berhenti berlaku setelah 5 kode salah dan membalas 401.
- 422 jika `code` bukan 6 digit angka atau `challenge_id` kosong.

### 3.2 Presensi

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/attendance/today` | Token + profil | Status presensi hari ini |
| POST | `/attendance/liveness-challenge` | Token + profil | Minta tantangan liveness (gerak kepala) |
| POST | `/attendance/check-in` | Token + profil | Presensi masuk |
| POST | `/attendance/check-out` | Token + profil | Presensi keluar |
| POST | `/attendance/master-face` | Token + profil | Unggah/ganti foto master wajah |
| DELETE | `/attendance/master-face` | Token + profil | Hapus foto master wajah |
| GET | `/attendances` | Token | Riwayat presensi (bentuk A) |
| GET | `/attendances/{id}` | Token | Detail presensi |

#### Mode presensi

Mode ditentukan kebijakan badan usaha dari dua pengaturan yang berdiri sendiri: GPS (mati/aktif) dan verifikasi selfie (`selfie_mode`). Aturan yang sama berlaku di aplikasi mobile dan di panel web (Presensi Mandiri), dan berlaku untuk check-in maupun check-out.

| `selfie_mode` | Yang dikirim klien | Yang dicek server |
| :--- | :--- | :--- |
| `none` (klik saja) | Tidak ada foto | Tidak ada pemeriksaan foto |
| `photo` | `photo` | Foto wajib ada |
| `face` | `photo` | Foto wajib ada, lalu dicocokkan dengan foto master wajah |
| `liveness` | `liveness_challenge_id` + `liveness_frames[]` | Tiga foto gerak kepala sesuai tantangan, lalu foto "Hadap depan" dicocokkan dengan foto master wajah |

| GPS | `selfie_mode` | `mode_label` |
| :--- | :--- | :--- |
| Mati | `none` | `Klik saja` |
| Aktif | `none` | `GPS` |
| Mati | `photo` | `Foto` |
| Aktif | `photo` | `GPS + Foto` |
| Mati | `face` | `Foto + Wajah` |
| Aktif | `face` | `GPS + Foto + Wajah` |
| Mati | `liveness` | `Liveness + Wajah` |
| Aktif | `liveness` | `GPS + Liveness + Wajah` |

Kebijakan dibaca dari `GET /auth/me` di `user.{employee|intern|freelancer}.company.policy`:

| Field | Isi |
| :--- | :--- |
| `require_gps` | `true` bila lokasi wajib dan geofence dicek. Bila `false`, aplikasi tidak perlu meminta izin lokasi. |
| `selfie_mode` | `none`, `photo`, `face`, atau `liveness`. Pakai field ini untuk memilih alur kamera. |
| `require_photo`, `require_face_recognition`, `require_liveness` | Bentuk boolean dari `selfie_mode` (liveness ⇒ wajah ⇒ foto), untuk klien lama. |
| `mock_location_action` | `reject` (bawaan) atau `approval`: tindakan bila lokasi palsu terdeteksi saat GPS aktif. |
| `face_match_threshold` | Skor kemiripan minimum dalam persen (bawaan 60). |
| `face_fail_action` | `reject` atau `approval`: tindakan bila wajah tidak cocok. |
| `mode_label` | Ringkasan mode untuk ditampilkan, misalnya `GPS + Foto + Wajah`. |

**GET /attendance/today**

```json
{"date": "2026-08-03", "has_checked_in": true, "has_checked_out": false, "attendance": { "...": "objek presensi" }}
```

**POST /attendance/liveness-challenge** (JSON): `purpose` wajib, `check_in` atau `check_out`.

```json
{
  "challenge_id": "9b1d6a0e-2f4c-4f7e-9a51-3c0f3f1f8d21",
  "steps": [
    {"key": "left", "label": "Tengok ke kiri", "instruction": "Putar kepala ke kiri sampai pipi kanan terlihat jelas."},
    {"key": "front", "label": "Hadap depan", "instruction": "Lihat lurus ke kamera."},
    {"key": "right", "label": "Tengok ke kanan", "instruction": "Putar kepala ke kanan sampai pipi kiri terlihat jelas."}
  ],
  "expires_at": "2026-08-03T01:08:00+00:00",
  "expires_in": 180
}
```

- Urutan `steps` diacak setiap kali. Ambil satu foto per langkah sesuai urutan itu, lalu kirim semuanya bersama check-in/check-out sebelum `expires_in` detik habis (`LIVENESS_TTL_SECONDS`, bawaan 180).
- Satu tantangan hanya berlaku sekali, untuk akun dan `purpose` yang memintanya, baik verifikasinya lolos maupun gagal. Setelah gagal, minta tantangan baru.
- 422 `Liveness tidak diwajibkan untuk badan usaha Anda.` bila `selfie_mode` bukan `liveness`; 422 `errors.purpose` bila `purpose` kosong atau tidak dikenal.
- 503 `Liveness membutuhkan layanan wajah CompreFace. Hubungi admin.` bila server memakai `FACE_DRIVER=basic`, yang tidak bisa membaca arah kepala.

**POST /attendance/check-in** dan **POST /attendance/check-out** (`multipart/form-data`)

| Field | Aturan | Keterangan |
| :--- | :--- | :--- |
| `latitude` | opsional, numerik -90..90 | Wajib bila `require_gps` aktif. Bila GPS mati, koordinat yang dikirim tetap disimpan. |
| `longitude` | opsional, numerik -180..180 | Sama seperti `latitude` |
| `accuracy` | opsional, numerik ≥ 0 | Akurasi lokasi dalam meter, hanya informasi |
| `is_mocked` | opsional, boolean (`1`, `0`, `true`, `false`) | `true` bila sistem operasi melaporkan lokasi palsu (Android `mocked`) |
| `photo` | opsional, gambar jpeg/png/jpg/webp, maks 5 MB | Wajib untuk `selfie_mode` `photo` dan `face` |
| `liveness_challenge_id` | opsional, string | `challenge_id` dari `/attendance/liveness-challenge` dengan `purpose` yang sesuai. Wajib untuk `liveness`. |
| `liveness_frames[]` | opsional, maks 3 gambar jpeg/png/jpg/webp, masing-masing maks 5 MB | Satu foto per langkah, urut sama dengan `steps`. Wajib untuk `liveness`. |

Aturan diterapkan berurutan; pelanggaran pertama yang menghentikan proses:

1. Akun tanpa profil pekerja: 422.
2. Check-in kedua di hari yang sama: 409. Check-out sebelum check-in: 422; check-out kedua: 409.
3. GPS aktif: koordinat wajib. Lokasi palsu (`is_mocked`) ditolak bila `mock_location_action = reject`, atau masuk persetujuan bila `approval`. Di luar radius kantor pusat dan semua lokasi aktif juga masuk persetujuan. GPS mati: hanya dicatat (`is_mock_location`), tidak ada pemeriksaan.
4. `photo`/`face`: foto wajib.
5. `face`/`liveness`: foto master wajah wajib sudah didaftarkan.
6. `liveness`: tantangan dan ketiga foto wajib. Gerakan kepala yang tidak sesuai selalu ditolak (tidak pernah dialihkan ke persetujuan). Foto "Hadap depan" menjadi foto presensi.
7. Wajah tidak cocok: ditolak bila `face_fail_action = reject`, masuk persetujuan bila `approval`.
8. Layanan wajah tidak bisa dihubungi: 503, presensi tidak tercatat.

Foto dari percobaan yang ditolak dihapus lagi dari server, jadi aman untuk langsung mengulang. Foto yang tidak dipakai oleh presensi yang tercatat juga dihapus, misalnya `photo` yang ikut terkirim di mode `liveness` (foto presensinya adalah foto "Hadap depan"). Di mode `none`, `photo` yang dikirim tetap disimpan sebagai foto presensi.

Bila ada alasan persetujuan, presensi tetap tercatat dengan status `pending_approval` (check-in dengan `late_minutes = 0`) dan masuk alur persetujuan `geofence`. Alasan yang mungkin, dalam urutan ini:

| Alasan (`approval_reasons[]`) | Kapan |
| :--- | :--- |
| `lokasi palsu terdeteksi` | GPS aktif, `is_mocked = true`, `mock_location_action = approval` |
| `di luar area kantor` | GPS aktif, koordinat di luar semua geofence |
| `verifikasi wajah tidak cocok` | `face`/`liveness`, skor di bawah ambang, `face_fail_action = approval` |

```json
{
  "message": "Check in berhasil",
  "requires_approval": false,
  "approval_reasons": [],
  "attendance": {
    "id": 102, "employee_id": 7, "intern_id": null, "freelancer_id": null,
    "date": "2026-08-03",
    "check_in": "2026-08-03T01:05:00.000000Z", "check_out": null,
    "check_in_lat": "-6.1753924", "check_in_lng": "106.8271528",
    "check_out_lat": null, "check_out_lng": null,
    "check_in_photo": "<url>", "check_out_photo": null,
    "status": "on_time", "is_corrected": false, "is_out_of_bounds": false, "is_mock_location": false,
    "is_face_verified": true, "face_match_score": 92,
    "face_verification_notes": "Verifikasi wajah berhasil (Skor: 92%, Threshold: 60%).",
    "liveness_verified": true, "liveness_notes": "Liveness terverifikasi: gerakan kepala sesuai instruksi.",
    "late_minutes": 0,
    "employee": {"id": 7, "full_name": "Karyawan Contoh", "nip": "2026.01.001",
                 "company": {"id": 1, "name": "PT Contoh Sejahtera"}, "division": {"id": 3, "name": "Operasional"},
                 "master_face_photo": null},
    "intern": null, "freelancer": null,
    "user": {"name": "Karyawan Contoh", "department": "Operasional"}
  }
}
```

- Pesan bila perlu persetujuan menyebut alasannya, digabung dengan " dan ", misalnya `Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena lokasi palsu terdeteksi dan di luar area kantor.` (pola yang sama untuk `Check out ...`). Tampilkan `message` apa adanya.
- `is_face_verified`, `face_match_score`, `face_verification_notes`, `liveness_verified`, dan `liveness_notes` bernilai `null` bila mode tidak memeriksanya.
- `is_out_of_bounds` dan `is_mock_location` bernilai `true` bila salah satu dari check-in atau check-out memenuhinya.
- Check-out yang perlu persetujuan mengubah status menjadi `pending_approval`; tahap persetujuan baru hanya dibuat bila tidak ada tahap yang masih menunggu (misalnya check-in sudah disetujui atau belum pernah perlu persetujuan).
- `late_minutes` dihitung dari jadwal shift hari itu, lalu shift bawaan, lalu jam kerja kebijakan perusahaan.

Error check-in/check-out selalu berbentuk `{"message": "...", "errors": {"<field>": ["..."]}}`:

| Status | `errors` | Pesan |
| :--- | :--- | :--- |
| 422 | `attendance` | `Profil pengguna tidak ditemukan.` |
| 409 | `attendance` | `Sudah melakukan check in hari ini.` / `Sudah melakukan check out hari ini.` |
| 422 | `attendance` | `Anda belum melakukan check in hari ini.` |
| 422 | `latitude` | `Lokasi GPS wajib diisi.` |
| 422 | `latitude` | `Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.` |
| 422 | `photo` | `Foto selfie absensi wajib diunggah.` |
| 422 | `photo` | `Foto Master Wajah belum didaftarkan. Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.` |
| 422 | `photo` | `Verifikasi wajah gagal: {catatan}`, misalnya `Verifikasi wajah gagal: Tingkat kemiripan wajah rendah (Skor: 40%, Threshold minimum: 60%).` |
| 422 | `liveness_frames` | `Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.` |
| 422 | `liveness_frames` | `Tantangan liveness sudah kedaluwarsa atau tidak dikenali. Mulai ulang verifikasi.` |
| 422 | `liveness_frames` | `Jumlah foto liveness tidak sesuai. Mulai ulang verifikasi.` |
| 422 | `liveness_frames` | `Wajah tidak terdeteksi dengan jelas pada langkah "{label}".` / `Terdeteksi lebih dari satu wajah pada langkah "{label}".` |
| 422 | `liveness_frames` | `Gerakan kepala tidak sesuai pada langkah "{label}". Ulangi dan ikuti instruksi dengan jelas.` |
| 503 | `photo` | `Layanan verifikasi wajah sedang tidak tersedia. Coba lagi beberapa saat.` |

Setiap 422 di `liveness_frames` berarti tantangan sudah terpakai: minta tantangan baru lalu ulangi ketiga langkah. Kesalahan validasi field (format gambar, ukuran, `is_mocked` bukan boolean, lebih dari 3 foto) memakai format 422 validasi biasa.

Nilai `status` presensi: `on_time` (Tepat Waktu), `late` (Terlambat), `absent` (Tidak Hadir), `leave` (Cuti / Izin), `holiday` (Hari Libur), `off` (Libur), `pending_approval` (Menunggu Persetujuan), `rejected` (Ditolak).

**POST /attendance/master-face** (`multipart/form-data`): `photo` wajib, gambar jpeg/png/jpg/webp, maks 5 MB.

```json
{"message": "Foto Master Wajah berhasil diperbarui",
 "data": {"master_face_photo": "<url>", "master_face_verified_at": "2026-08-03T07:00:00.000000Z"}}
```

**DELETE /attendance/master-face** membalas `{"message": "Foto Master Wajah berhasil dihapus."}`, atau 400 bila belum ada foto.

**GET /attendances**: query `start_date` + `end_date` (keduanya), atau `month` + `year` (keduanya), dan `status`. Urut tanggal terbaru. `GET /attendances/{id}`: 404 bila tidak ada, 403 bila bukan milik sendiri dan di luar data akses pemanggil.

### 3.3 Cuti / Izin

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/leave-requests` | Token | Riwayat milik sendiri + ringkasan kuota (bentuk B) |
| POST | `/leave-requests` | Token + profil | Buat pengajuan |
| GET | `/leave-requests/{id}` | Token | Detail (milik sendiri atau dalam data akses) |
| PUT | `/leave-requests/{id}` | Token + profil | Ubah, hanya milik sendiri & `pending` |
| DELETE | `/leave-requests/{id}` | Token + profil | Batalkan, hanya milik sendiri & `pending` |

**POST /leave-requests** (JSON atau `multipart/form-data`)

| Field | Aturan |
| :--- | :--- |
| `leave_type` | wajib: `annual_leave` (Cuti Tahunan), `permission` (Izin), `sick` (Sakit), `maternity` (Cuti Melahirkan), `unpaid` (Cuti Tanpa Gaji). Kode lama `annual` dan `permitted` masih diterima dan disimpan sebagai `annual_leave` dan `permission` |
| `start_date` | wajib, tanggal |
| `end_date` | wajib, tanggal, `>= start_date` |
| `reason` | wajib, maks 1000 karakter |
| `attachment` | opsional, jpeg/png/jpg/pdf/doc/docx, maks 5 MB |

- `days_count` = jumlah hari kalender dari `start_date` sampai `end_date` (termasuk akhir pekan).
- Cuti tahunan diberikan per karyawan. HR menyalakannya di data karyawan (bagian Cuti Tahunan, atau aksi massal Atur Cuti Tahunan); karyawan baru tidak berhak sampai dinyalakan. Peserta magang dan freelancer tidak pernah berhak.
- Kuota per tahun memakai kuota karyawan itu sendiri bila diisi, selain itu `annual_leave_quota` kebijakan badan usaha (bawaan 12). Sisa = kuota dikurangi cuti tahunan `approved` yang dimulai tahun ini, baik dari panel maupun API.
- `annual_leave` ditolak dengan 422 dan `errors.leave_type`:

| Kondisi | Pesan |
| :--- | :--- |
| Karyawan tanpa hak cuti tahunan | `Anda belum memiliki jatah cuti tahunan. Hubungi HR atau gunakan jenis Izin.` |
| Peserta magang | `Peserta magang tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.` |
| Freelancer | `Freelancer tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.` |
| Sisa kuota kurang | `Kuota cuti tahunan Anda tidak mencukupi (Sisa: 2 hari, Pengajuan: 3 hari).` |

- PUT menerima field yang sama (semua opsional), menghitung ulang `days_count`, dan memeriksa kuota lagi.

Respons 201:

```json
{
  "message": "Pengajuan cuti berhasil dibuat",
  "data": {
    "id": 15, "employee_id": 7, "intern_id": null, "freelancer_id": null,
    "leave_type": "annual_leave", "leave_type_label": "Cuti Tahunan",
    "start_date": "2026-08-01", "end_date": "2026-08-03",
    "days_count": 3, "reason": "Acara keluarga", "status": "pending",
    "current_step": 1, "total_steps": 3, "rejection_reason": null, "rejection_note": null, "attachment": null,
    "created_at": "...", "updated_at": "...",
    "employee": {"id": 7, "full_name": "Karyawan Contoh", "...": "..."}, "intern": null, "freelancer": null,
    "user": {"name": "Karyawan Contoh", "department": "Operasional"}
  }
}
```

`GET /leave-requests` menambahkan `quota_summary`. `eligible` bernilai `false` untuk peserta magang, freelancer, dan karyawan tanpa hak cuti tahunan; kuota dan sisanya lalu 0.

```json
{"quota_summary": {"eligible": true, "annual_leave_quota": 12, "used_days": 3, "remaining_days": 9}, "data": [], "pagination": {"current_page": 1, "last_page": 1, "total": 0}}
```

Pesan lain: PUT `Pengajuan cuti berhasil diperbarui`, DELETE `Pengajuan cuti berhasil dibatalkan.`, status bukan pending 422 `Pengajuan yang sudah diproses tidak dapat diubah.`, bukan milik sendiri 404.

### 3.4 Lembur

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/overtime-requests` | Token | Riwayat milik sendiri (bentuk B) |
| POST | `/overtime-requests` | Token + profil | Buat pengajuan |
| GET | `/overtime-requests/{id}` | Token | Detail (milik sendiri atau dalam data akses) |
| PUT | `/overtime-requests/{id}` | Token + profil | Ubah, hanya milik sendiri & `pending` |
| DELETE | `/overtime-requests/{id}` | Token + profil | Batalkan, hanya milik sendiri & `pending` |

**POST /overtime-requests** (JSON)

| Field | Aturan |
| :--- | :--- |
| `date` | wajib, tanggal |
| `start_time` | wajib, format `HH:MM` |
| `end_time` | wajib, format `HH:MM`, tidak sama dengan `start_time`. Jam yang lebih awal dari `start_time` berarti lembur melewati tengah malam, misalnya `22:00` sampai `01:00` dihitung 180 menit |
| `reason` | wajib, maks 1000 karakter |

```json
{"date": "2026-08-05", "start_time": "17:00", "end_time": "20:00", "reason": "Rilis aplikasi ke server staging"}
```

Respons 201 `{"message": "Pengajuan lembur berhasil dibuat", "data": {...}}`. Field `data`: `id, employee_id, intern_id, freelancer_id, date, start_time, end_time, duration_minutes, actual_duration_minutes, reason, status, current_step, total_steps, rejection_reason, rejection_note, created_at, updated_at, employee, intern, freelancer, user`. PUT menerima field yang sama (opsional). Pesan: `Pengajuan lembur berhasil diperbarui`, `Pengajuan lembur berhasil dibatalkan.`

### 3.5 Koreksi Absensi

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/attendance-corrections` | Token | Riwayat milik sendiri (bentuk B) |
| POST | `/attendance-corrections` | Token + profil | Buat pengajuan |
| GET | `/attendance-corrections/{id}` | Token | Detail (milik sendiri atau dalam data akses) |
| PUT | `/attendance-corrections/{id}` | Token + profil | Ubah, hanya milik sendiri & `pending` |
| DELETE | `/attendance-corrections/{id}` | Token + profil | Batalkan, hanya milik sendiri & `pending` |

**POST /attendance-corrections** (JSON atau `multipart/form-data`)

| Field | Aturan |
| :--- | :--- |
| `date` | wajib, tanggal presensi yang dikoreksi |
| `corrected_check_in` | opsional, jam (mis. `08:00`), ditempatkan pada `date` |
| `corrected_check_out` | opsional, jam (mis. `17:00`), ditempatkan pada `date` |
| `reason` | wajib, maks 1000 karakter |
| `attachment` | opsional, jpeg/png/jpg/pdf/doc/docx, maks 5 MB |

Respons 201 `{"message": "Pengajuan koreksi absensi berhasil dibuat", "data": {...}}`. Field `data`: `id, employee_id, intern_id, freelancer_id, date, corrected_check_in, corrected_check_out, reason, status, current_step, total_steps, rejection_reason, rejection_note, attachment, created_at, updated_at, employee, intern, freelancer, user`. Setelah disetujui penuh, presensi di tanggal itu dibuat/diperbarui dengan jam koreksi dan `is_corrected = true`.

### 3.6 Pengunduran Diri (Resign)

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/resignations` | Token | Milik sendiri + dalam data akses (bentuk B) |
| POST | `/resignations` | Token + profil karyawan | Buat pengajuan |
| GET | `/resignations/{id}` | Token | Detail (403 bila di luar akses) |
| PUT | `/resignations/{id}` | Token + profil karyawan | Ubah, hanya milik sendiri & `pending` |
| DELETE | `/resignations/{id}` | Token + profil karyawan | Batalkan, hanya milik sendiri & `pending` |

Hanya akun dengan profil **employee** (Intern/Freelancer: 422 `Profil Karyawan tidak ditemukan.`). Satu karyawan hanya boleh punya satu pengajuan `pending` atau `approved`.

**POST /resignations** (JSON)

| Field | Aturan |
| :--- | :--- |
| `resignation_date` | wajib, tanggal |
| `last_working_day` | wajib, tanggal, `>= resignation_date` |
| `reason` | wajib, teks |
| `handover_notes` | opsional, teks |

```json
{
  "message": "Pengajuan pengunduran diri berhasil dibuat.",
  "data": {
    "id": 4, "employee_id": 7, "intern_id": null, "freelancer_id": null,
    "employee_name": "Karyawan Contoh",
    "resignation_date": "2026-08-15", "last_working_day": "2026-09-15",
    "reason": "Melanjutkan studi", "handover_notes": "Dokumen serah terima sudah diserahkan ke atasan.",
    "status": "pending", "current_step": 1, "rejection_reason": null,
    "created_at": "2026-08-03T02:00:00.000000Z", "updated_at": "2026-08-03T02:00:00.000000Z",
    "employee": {"...": "..."}, "intern": null, "freelancer": null, "user": {"name": "Karyawan Contoh", "department": "Operasional"}
  }
}
```

Setelah disetujui penuh, status karyawan menjadi `inactive` dan akunnya `is_active = false`.

### 3.7 Persetujuan

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/approvals/pending` | Token | Antrean yang boleh diproses pemanggil (lihat 2.4) |
| PUT | `/approvals/{type}/{id}/process` | Token | Setujui atau tolak tahap aktif |

**GET /approvals/pending** (tanpa paginasi):

```json
{"leave_requests": [], "overtime_requests": [], "attendance_corrections": [], "geofence_attendances": [], "resignations": []}
```

Isi tiap array memakai bentuk objek yang sama dengan endpoint detailnya (presensi untuk `geofence_attendances`).

**PUT /approvals/{type}/{id}/process**

| `type` | Alias yang juga diterima | Model |
| :--- | :--- | :--- |
| `leave` | `leave-requests` | Cuti/izin |
| `overtime` | `overtime-requests` | Lembur |
| `correction` | `attendance-corrections` | Koreksi absensi |
| `geofence` | `attendance`, `attendances` | Presensi luar geofence / gagal verifikasi wajah |
| `resignation` | `resignations` | Resign |

| Field | Aturan |
| :--- | :--- |
| `action` | wajib: `approved` atau `rejected` |
| `rejection_note` | wajib bila `rejected`, maks 1000. Saat `approved` boleh diisi sebagai catatan tahap |

```json
{"message": "Pengajuan berhasil disetujui", "status": "pending", "current_step": 2}
```

- `status` menjadi `approved` setelah tahap terakhir disetujui (presensi geofence menjadi `on_time`/`late`). Penolakan langsung mengakhiri alur (`rejected`), pesan `Pengajuan telah ditolak`.
- Error: `type` tidak dikenal 422; data tidak ada 404; bukan pending 422 `Pengajuan ini sudah tidak berstatus pending.`; tidak berhak 422 dengan `errors.approve` atau `errors.reject`.
- Efek persetujuan akhir: cuti menandai presensi tiap tanggal sebagai `leave`; koreksi menerapkan jam ke presensi; resign menonaktifkan karyawan dan akunnya.

### 3.8 Kalender dan Laporan

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/kalender-cuti` | Token | Cuti `approved` dan `pending` yang beririsan dengan bulan |
| GET | `/laporan-absensi` | Token | Ringkasan dan daftar presensi (bentuk A) |

**GET /kalender-cuti**: query `month` (bawaan bulan ini), `year` (bawaan tahun ini), `company_id` (opsional). Peserta magang mendapat 403.

```json
{
  "month": 8, "year": 2026,
  "data": [{
    "id": 15, "person_name": "Karyawan Contoh", "user_name": "Karyawan Contoh",
    "company_name": "PT Contoh Sejahtera", "division_name": "Operasional", "department": "Operasional",
    "leave_type": "annual_leave", "start_date": "2026-08-01", "end_date": "2026-08-03",
    "days_count": 3, "status": "approved", "reason": "Acara keluarga", "master_face_photo": null
  }]
}
```

**GET /laporan-absensi**: query `start_date` (bawaan awal bulan), `end_date` (bawaan hari ini), `company_id` (opsional).

```json
{
  "start_date": "2026-08-01", "end_date": "2026-08-31",
  "summary": {"total_present": 18, "total_late": 2, "total_leave": 1, "total_absent": 1},
  "data": [], "pagination": {"current_page": 1, "last_page": 1, "per_page": 30, "total": 18}
}
```

| Ringkasan | Cara hitung |
| :--- | :--- |
| `total_present` | Record berstatus `on_time`, `late`, `pending_approval`, atau punya `check_in` |
| `total_late` | Jumlah kejadian terlambat (bukan total menit) |
| `total_leave` | Jumlah tanggal unik cuti `approved` dalam rentang |
| `total_absent` | Record `absent`/`rejected` + hari Senin-Jumat sampai hari ini tanpa presensi dan tanpa cuti |

### 3.9 Master Data

Semua `GET`, read-only, dengan pola `GET /master/<resource>` (daftar) dan `GET /master/<resource>/{id}` (detail, 404 bila tidak ada atau di luar akses). Respons `{"data": ...}` berisi atribut model apa adanya beserta relasi yang dimuat.

| Resource | Izin (`can:`) | Filter akses data | Query | Relasi | Paginasi |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `companies` | `ViewAny:Company` | Ya | - | `locations`, `policy` | - |
| `company-locations` | `ViewAny:CompanyLocation` | Ya | `company_id` | `company` | - |
| `company-policies` | `ViewAny:CompanyPolicy` | Ya | `company_id` | `company` | - |
| `divisions` | `ViewAny:Division` | Ya (per badan usaha) | `company_id` | `company` | - |
| `job-titles` | `ViewAny:JobTitle` | Tidak | `division_id` | `division` | - |
| `job-levels` | `ViewAny:JobLevel` | Tidak | - | - | - |
| `employees` | `ViewAny:Employee` | Ya | `company_id`, `division_id`, `status` (`active`/`inactive`) | `company`, `division`, `jobTitle`, `jobLevel`, `user` | C |
| `interns` | `ViewAny:Intern` | Ya | `company_id` | `company`, `division`, `mentor`, `user` | C |
| `freelancers` | `ViewAny:Freelancer` | Ya | `company_id` | `company`, `division`, `supervisor`, `user` | C |
| `holidays` | `ViewAny:Holiday` | Tidak | `year` | - (urut `date`) | - |
| `approval-flows` | `ViewAny:ApprovalFlow` | Ya | `company_id`, `request_type` | `company` | - |
| `approvers` | `ViewAny:Approver` | Ya | `company_id`, `division_id` | lihat di bawah | - |
| `users` | `ViewAny:User` | Ya | `email` (sebagian) | `roles`, `employee`, `intern`, `freelancer` (+`permissions` di detail) | C |

- `approval-flows`: `request_type` salah satu `leave`, `overtime`, `correction`, `resignation`, `geofence`. Urut `company_id`, `request_type`, `step_order`. Field utama: `step_order`, `name`, `approver_type` (`role`/`user`), `approver_role`, `user_id`.
- `company-policies`: field utama `late_tolerance_minutes`, `require_photo`, `require_gps`, `require_face_recognition`, `require_liveness`, `face_match_threshold`, `face_fail_action`, `mock_location_action`, `geofence_radius_meters`, `annual_leave_quota`, `work_start_time`, `work_end_time`.

**GET /master/approvers** mengembalikan **akun** pengguna dengan role Approver atau BOD (tabel lama `approvers` tidak dipakai lagi), urut nama, tanpa paginasi.

| Query | Efek |
| :--- | :--- |
| `company_id` | Hanya approver yang data aksesnya mencakup badan usaha ini |
| `division_id` | Hanya approver yang data aksesnya mencakup divisi ini (badan usaha diambil dari divisi bila `company_id` kosong) |

```json
{
  "data": [{
    "id": 21, "user_id": 21, "name": "Approver Penjualan", "email": "approver@contoh.id",
    "roles": ["Approver"], "data_scope": "divisions", "data_scope_label": "Divisi",
    "companies": [],
    "divisions": [{"id": 4, "name": "Penjualan", "company_id": 1, "company_name": "PT Contoh Sejahtera"}]
  }]
}
```

`companies` (`id, code, name`) hanya terisi untuk `data_scope = companies`, `divisions` hanya untuk `data_scope = divisions`. Daftar ini juga dibatasi data akses pemanggil: akun approver tanpa profil pekerja hanya terlihat oleh pemanggil berakses `all`.

### 3.10 Shield (role dan izin)

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| GET | `/shield/roles` | `can:ViewAny:Role` | `{"roles": [{"id", "name", "guard_name", "permissions": ["..."]}]}` |
| GET | `/shield/permissions` | `can:ViewAny:Role` | `{"permissions": [{"id", "name", "guard_name"}]}` |

### 3.11 Webhook karyawan (di luar v1)

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| POST | `/api/webhooks/employees` | Tanda tangan HMAC | Sinkronisasi data karyawan dari sistem inti |

- Header `X-Signature` = HMAC-SHA256 (hex) dari body mentah dengan kunci `CORE_WEBHOOK_SECRET`. Kunci belum diset: 503. Tanda tangan salah: 401.
- Body: `action` (wajib), `employee.id` (wajib, integer), `employee.name` (wajib), `employee.email`, `employee.phone`, `employee.status` (wajib).
- `created`/`updated` hanya memperbarui karyawan yang sudah ada (`full_name`, `email`, `phone`, `status`); `deleted` menghapus karyawan. Respons `{"status": "success"}`.

### 3.12 Perangkat dan push notification

| Method | Path | Akses | Keterangan |
| :--- | :--- | :--- | :--- |
| POST | `/devices` | Token | Daftarkan atau perbarui Expo push token perangkat ini |
| DELETE | `/devices` | Token | Hapus token perangkat ini; panggil sebelum `POST /auth/logout` |

**POST /devices** (JSON)

| Field | Aturan |
| :--- | :--- |
| `token` | wajib, Expo push token berbentuk `ExponentPushToken[...]`, maks 255 |
| `platform` | wajib: `ios` atau `android` |

```json
{"message": "Perangkat terdaftar untuk notifikasi.", "device": {"token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]", "platform": "ios", "last_seen_at": "2026-11-05T09:30:00+07:00"}}
```

- 201 saat token baru, 200 saat token yang sama dikirim ulang (`last_seen_at` diperbarui). Kirim ulang setiap kali aplikasi dibuka dalam keadaan masuk.
- Satu token hanya milik satu akun: bila akun lain masuk di perangkat yang sama, token berpindah ke akun itu.
- Token bukan format Expo atau `platform` di luar daftar: 422 dengan `errors.token` / `errors.platform`.

**DELETE /devices** (JSON): `token` (wajib). Membalas `{"message": "Perangkat dihapus dari notifikasi."}` walau token tidak ditemukan; token milik akun lain tidak tersentuh. Token juga ikut terhapus saat akun dihapus atau saat Expo melaporkan `DeviceNotRegistered`.

**Isi notifikasi**

Notifikasi dikirim dari antrean (Horizon) ke Expo Push API `https://exp.host/--/api/v2/push/send` (`EXPO_PUSH_URL`; `EXPO_ACCESS_TOKEN` hanya bila *enhanced push security* diaktifkan di proyek Expo). Semua notifikasi memakai `sound: default`, `channelId: default`, `priority: high`, dan `data` berikut:

| `data.kind` | Penerima | Kapan | Field lain |
| :--- | :--- | :--- | :--- |
| `approval_requested` | User yang berhak memproses tahap aktif (aturan 2.4) | Pengajuan baru dibuat, atau tahap sebelumnya disetujui sehingga pengajuan masuk ke tahap user | `request_type`, `request_id`, `step_order` |
| `approval_resolved` | Akun pengaju | Tahap terakhir disetujui (`status: approved`) atau pengajuan ditolak (`status: rejected`) | `request_type`, `request_id`, `status` |

`request_type` salah satu `leave`, `overtime`, `correction`, `resignation`, `geofence`; `request_id` adalah id pengajuan (untuk `geofence`: id presensi). Judul dan isi berbahasa Indonesia, misalnya `Pengajuan baru menunggu persetujuan Anda` / `Pengajuan Cuti / Izin dari Nama Pekerja menunggu persetujuan Anda.` dan `Pengajuan Cuti / Izin ditolak` / `Pengajuan Cuti / Izin Anda ditolak: <alasan>`.

Aplikasi mobile: saat notifikasi ditekan, `approval_requested` membuka `/approval/{request_type}/{request_id}` (inbox persetujuan bila halaman tidak dikenal), `approval_resolved` membuka `/request/{request_type}/{request_id}` atau `/attendance/{request_id}` untuk `geofence`. Push hanya berjalan di development build / build EAS (bukan Expo Go) dan memerlukan `extra.eas.projectId` di `app.json` untuk mendapatkan token.

---

## 4. Halaman mobile per role

### 4.1 Matriks tampilan

Keterangan: **Ya** tampilkan; **Tidak** sembunyikan; **Profil** tampilkan bila akun terhubung ke profil pekerja; **Izin** tampilkan bila `user.permissions` memuat izin terkait (tidak diberikan oleh seeder bawaan). Akun dengan beberapa role mendapat gabungan hak.

| Halaman | Endpoint | Izin web | Superadmin | Admin | BOD | Approver | Employee | Intern | Freelancer |
| :--- | :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Login, profil, logout | `/auth/*` | - | Ya | Ya | Ya | Ya | Ya | Ya | Ya |
| Kartu pembimbing | `/auth/me` | - | Tidak | Tidak | Tidak | Tidak | Tidak | Ya (mentor) | Ya (supervisor) |
| Presensi hari ini, masuk/keluar | `/attendance/*` | `View:AbsenHariIni` | Tidak | Profil | Profil | Profil | Ya | Ya | Ya |
| Foto master wajah | `/attendance/master-face` | `View:AbsenHariIni` | Tidak | Profil | Profil | Profil | Ya | Ya | Ya |
| Riwayat presensi, laporan | `/attendances`, `/laporan-absensi` | `View:LaporanAbsensi` | Ya (semua) | Profil | Profil | Profil | Ya | Ya | Ya |
| Cuti/izin + kuota | `/leave-requests` | `Create:LeaveRequest` | Profil | Izin | Izin | Izin | Ya | Ya (tanpa cuti tahunan) | Ya (izin/sakit) |
| Lembur | `/overtime-requests` | `Create:OvertimeRequest` | Profil | Izin | Izin | Izin | Ya | Ya | Ya |
| Koreksi absensi | `/attendance-corrections` | `Create:AttendanceCorrection` | Profil | Izin | Izin | Izin | Ya | Ya | Ya |
| Form resign | `/resignations` | `Create:Resignation` | Profil | Izin | Izin | Izin | Ya | Tidak | Tidak |
| Kalender cuti tim | `/kalender-cuti` | semua kecuali Intern | Ya | Ya | Ya | Ya | Ya | Tidak | Ya |
| Inbox persetujuan + badge | `/approvals/*` | role Approver/BOD/Superadmin, Admin berdata akses | Ya | Ya | Ya | Ya | Tidak | Tidak | Tidak |
| Referensi master data | `/master/*` | `ViewAny:<Model>` | Ya | Izin | Tidak | Tidak | Tidak | Tidak | Tidak |

Catatan:
- Freelancer tidak punya cuti tahunan. Tampilkan pilihan `permission` dan `sick` saja, seperti form di panel.
- Cuti tahunan dan kartu kuotanya hanya untuk karyawan dengan `employee.has_annual_leave` bernilai `true`. Peserta magang dan karyawan lain tetap bisa memilih jenis cuti lainnya.
- Inbox persetujuan Admin hanya berisi pekerja dalam data aksesnya. Approver/BOD dengan akses `own` akan selalu kosong.
- Riwayat presensi dan laporan berisi data milik sendiri ditambah pekerja dalam data akses. Superadmin melihat seluruh pekerja.

### 4.2 Halaman dan endpoint

| Halaman | Endpoint | Isi utama |
| :--- | :--- | :--- |
| Login | `POST /auth/login` | Simpan token, user, roles, permissions |
| Dashboard | `GET /auth/me`, `GET /attendance/today`, `GET /approvals/pending` | Status presensi hari ini, status foto master, kartu kuota/pembimbing, badge antrean |
| Presensi | `POST /attendance/liveness-challenge`, `check-in`, `check-out`, `GET /attendances` | Alur mengikuti `policy.selfie_mode` dan `require_gps` (3.2): tanpa kamera, satu selfie, atau tiga langkah liveness; GPS dan deteksi lokasi palsu hanya bila wajib; riwayat terakhir |
| Foto master wajah | `POST`/`DELETE /attendance/master-face` | Unggah dari galeri atau kamera |
| Pusat pengajuan | `GET` cuti, lembur, koreksi, resign | Menu per role (4.1) dan riwayat dengan badge status |
| Form cuti | `POST /leave-requests` | Jenis, tanggal mulai/selesai, alasan, lampiran |
| Form lembur | `POST /overtime-requests` | Tanggal, jam mulai/selesai, alasan |
| Form koreksi | `POST /attendance-corrections` | Tanggal, jam masuk/keluar koreksi, alasan, lampiran |
| Form resign | `POST /resignations` | Tanggal resign, hari kerja terakhir, alasan, catatan serah terima |
| Inbox persetujuan | `GET /approvals/pending`, `PUT /approvals/{type}/{id}/process` | Tab cuti, lembur, koreksi, luar geofence (peta lokasi pekerja vs kantor), resign; tombol Setujui/Tolak + alasan |
| Kalender cuti | `GET /kalender-cuti` | Grid bulanan cuti rekan |
| Laporan presensi | `GET /laporan-absensi` | Kartu hadir, terlambat, cuti, tidak hadir |
| Profil | `GET /auth/me`, `POST /auth/logout` | Biodata, badan usaha, divisi, jabatan, pembimbing, pengaturan base URL |
| Push notification | `POST /devices` setelah login dan tiap aplikasi dibuka, `DELETE /devices` sebelum logout | Minta izin notifikasi, daftarkan Expo push token; tekan notifikasi membuka detail pengajuan (3.12) |

Label status pengajuan: `pending` Menunggu Persetujuan, `approved` Disetujui, `rejected` Ditolak.

---

## 5. Keterbatasan yang perlu diketahui klien

| Hal | Dampak |
| :--- | :--- |
| `master_face_photo` di objek pekerja dan kalender | Berisi path penyimpanan, bukan URL. URL hanya ada di `/auth/me` dan respons master-face. |
| Riwayat tahap persetujuan | Endpoint detail mengirim `current_step` dan `total_steps`, tetapi belum mengirim daftar tahap beserta penyetujunya. |

### Perubahan untuk klien lama

| Perubahan | Yang perlu disesuaikan |
| :--- | :--- |
| `date`, `start_date`, `end_date` kini `YYYY-MM-DD` | Sebelumnya ISO UTC yang bergeser sehari. Parser tanggal biasa tetap bisa membacanya. |
| `leave_type` kini `annual_leave` dan `permission` | Kode lama masih diterima saat mengirim, tetapi respons selalu memakai kode baru. Gunakan `leave_type_label` untuk tampilan. |
| `rejection_reason` dikirim di cuti, lembur, dan koreksi | `rejection_note` masih ada dengan isi yang sama untuk aplikasi versi lama. |
| Akun nonaktif ditolak | Tangani 403 saat login dan 401 pada token lama dengan mengarahkan ke layar login. |
| Pengajuan tanpa alur persetujuan | Respons 422 `errors.approval_flow` tidak lagi meninggalkan pengajuan setengah jadi, jadi aman untuk dikirim ulang setelah alur dibuat. |
| Push notification (`/devices`) | Opsional. Klien yang tidak mendaftarkan token tidak menerima push; notifikasi panel web tetap berjalan seperti sebelumnya. |
| Mode presensi (`selfie_mode`, liveness, lokasi palsu) | Pilih alur dari `policy.selfie_mode`, bukan dari `require_face_recognition` saja. Mode `liveness` butuh `POST /attendance/liveness-challenge` dan `liveness_frames[]`; klien lama yang hanya mengirim `photo` ditolak 422 di mode itu. Kirim `is_mocked` dan `accuracy` bila GPS aktif. |
| Check-out memverifikasi wajah | Check-out kini menjalankan pemeriksaan yang sama dengan check-in, termasuk foto, wajah, dan liveness. |
| Respons check-in/check-out | Menambah `requires_approval` dan `approval_reasons`. Pesan persetujuan kini menyebut alasannya, dan error aturan presensi kini menyertakan `errors.<field>`. |
