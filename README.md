# OnTime

Aplikasi presensi dan pengajuan karyawan untuk banyak badan usaha. Panel admin dibangun dengan Filament, dan aplikasi mobile memakai REST API v1.

## Fitur

- **Struktur organisasi:** badan usaha, divisi bertingkat, lokasi dan cabang dengan geofence, tingkat jabatan, dan posisi.
- **Pekerja:** karyawan, peserta magang, dan freelancer. Akun login bisa dibuat dari data pekerja, dan info akunnya dikirim lewat WhatsApp.
- **Presensi:** check-in dan check-out dengan foto, GPS, dan geofence. Absen di luar geofence masuk alur persetujuan.
- **Jadwal dan shift:** master shift per badan usaha, shift default per pekerja, dan jadwal massal per tanggal.
- **Pengajuan:** cuti dan izin dengan kuota tahunan, lembur dengan durasi aktual, koreksi absensi, dan pengunduran diri.
- **Persetujuan bertingkat:** tahap diatur per badan usaha dan jenis pengajuan, dengan notifikasi di panel untuk penyetuju.
- **Akses data ala ERP:** role menentukan apa yang boleh dilakukan, Akses Data menentukan data siapa. Lihat [AKSES.md](AKSES.md).
- **Laporan:** laporan absensi dengan ekspor Excel, kalender cuti tim, dan surat yang bisa dicetak.
- **Import dan ekspor:** CSV atau Excel untuk karyawan, magang, dan freelancer, serta import JSON dari Talenta.

## Teknologi

| Komponen | Versi |
|---|---|
| PHP | 8.4 |
| Laravel | 13 |
| Filament | 5, dengan tema Mekaya |
| Livewire | 4 |
| Role dan permission | Spatie Permission dan Filament Shield |
| API | Laravel Sanctum |
| Test | PHPUnit 12 |

## Menjalankan di lokal

1. Pasang dependensi.

   ```bash
   composer install
   ```

   ```bash
   npm install
   ```

2. Siapkan environment, lalu isi koneksi database di `.env`.

   ```bash
   cp .env.example .env
   ```

   ```bash
   php artisan key:generate
   ```

3. Jalankan migrasi. Tambahkan `--seed` kalau ingin data demo.

   ```bash
   php artisan migrate
   ```

4. Build aset frontend.

   ```bash
   npm run build
   ```

5. Jalankan server, queue worker, log, dan Vite sekaligus.

   ```bash
   composer run dev
   ```

Panel admin ada di `/admin`. Import dan ekspor CSV atau Excel berjalan di queue, jadi queue worker harus hidup. Perintah `composer run dev` sudah menjalankannya.

Data demo dibuat oleh `database/seeders/DatabaseSeeder.php`. Akun dan password demo bisa dilihat di file itu dan hanya untuk lokal.

## Integrasi opsional

Semua kunci di bawah ini boleh dikosongkan di lokal.

| Kunci `.env` | Fungsi |
|---|---|
| `WAG_URL`, `WAG_TOKEN` | Login dengan kode OTP WhatsApp dan pengiriman info akun lewat WagHub. Nama variabelnya sama dengan gais-web, dnd-web, dan helpdesk-web. `WAG_URL` berisi URL dasar WagHub dan sudah terisi bawaan, jadi cukup isi `WAG_TOKEN`. Opsional: `WHATSAPP_OTP_TTL_MINUTES` (bawaan 5 menit), `WA_CONNECT_TIMEOUT` (5 detik), `WA_API_TIMEOUT` (15 detik). |
| `MAIL_MAILER` (misalnya `smtp`), `MAIL_FROM_ADDRESS` | Login dengan email: satu email berisi kode OTP dan magic link. Tidak tampil selama `MAIL_MAILER=log`. |
| `CORE_WEBHOOK_SECRET` | Sinkronisasi karyawan dari Core HR. Webhook ditolak selama kosong. |
| `CORE_CLIENT_ID`, `CORE_CLIENT_SECRET`, `CORE_REDIRECT_URI` | Tombol login lewat akun Core (SSO). |

## Login

Halaman login sama dengan aplikasi lain di grup (gais-web, dnd-web, helpdesk-web):

- **Email dan password** selalu tersedia.
- **WhatsApp:** kode OTP dikirim ke nomor HP yang tercatat di akun atau data pekerjanya. Nomor yang dipakai lebih dari satu akun tidak bisa login.
- **Email:** satu email berisi kode OTP dan tombol magic link. Keduanya hanya bisa dipakai sekali.
- **Akun Core (SSO):** tampil bila SSO dikonfigurasi.

Tombol WhatsApp, Email, dan SSO hanya muncul bila integrasinya diisi. Akun nonaktif atau tanpa role tidak bisa masuk lewat cara apa pun. Halaman login tidak pernah memberi tahu apakah sebuah nomor atau email terdaftar.

## Perintah artisan

| Perintah | Fungsi |
|---|---|
| `php artisan employees:import-talenta <file.json>` | Import atau sinkronkan karyawan dari export Talenta. Tambahkan `--dry-run` untuk pratinjau dan `--deactivate-missing` untuk menonaktifkan karyawan yang tidak ada di file. |
| `php artisan approvals:generate-missing-steps` | Membuat tahap persetujuan untuk pengajuan pending yang tersimpan tanpa tahap. |
| `php artisan core:sync-permissions` | Mengirim permission Shield ke Core API. |

## Test

```bash
php artisan test --compact
```

Test memakai SQLite in-memory, jadi tidak menyentuh database lokal.

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [AKSES.md](AKSES.md) | Role, Akses Data, dan alur persetujuan. |
| [API.md](API.md) | REST API v1 dan halaman mobile per role. |
| [UAT.md](UAT.md) | Skenario uji penerimaan. |
| [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md) | Panduan untuk AI agent, dibuat oleh Laravel Boost. |
