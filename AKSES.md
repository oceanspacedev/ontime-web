# Role, Akses Data, dan Persetujuan

Hak setiap akun di OnTime ditentukan oleh tiga hal:

- **Klien** menentukan batas paling luar. Setiap badan usaha dan setiap akun (kecuali Superadmin) milik satu klien, dan tidak ada akses yang menembus klien lain.
- **Role** menentukan apa yang boleh dilakukan, misalnya mengelola karyawan atau menyetujui cuti.
- **Akses Data** menentukan untuk pekerja mana hak itu berlaku, di dalam kliennya.

Aturan ini dipakai di semua tempat: panel admin, laporan, kalender, API mobile, import dan ekspor, serta hak menyetujui. Seluruh aturannya ada di `app/Services/DataAccessService.php`.

## Klien dan PIC

OnTime dipakai banyak klien sekaligus. Superadmin (pemilik platform) tidak mengurus operasional klien:

1. Superadmin membuat **Klien** di menu Klien, sekaligus akun **PIC**-nya. Data login PIC dikirim lewat WhatsApp.
2. PIC mengatur sendiri kliennya: badan usaha, divisi, lokasi, kebijakan absensi (klik saja, GPS, foto, wajah, liveness), master shift, alur persetujuan, pekerja, dan akun (termasuk Admin lain).

Aturan klien:

- **Akun PIC:** role Admin dan PIC, Akses Data Semua. Hanya Superadmin yang bisa memberi role PIC.
- **Akses "Semua":** untuk semua akun selain Superadmin artinya seluruh badan usaha milik kliennya, termasuk yang ditambahkan nanti.
- **Badan usaha baru:** dibuat oleh Superadmin (memilih klien) atau PIC (otomatis masuk kliennya). Kode badan usaha cukup unik di dalam satu klien.
- **Akun baru:** otomatis masuk klien pembuatnya. Akun pekerja masuk klien badan usaha pekerjanya.
- **Pilihan Badan Usaha atau Divisi** yang milik klien lain diabaikan.
- **Tanpa klien:** badan usaha atau akun tanpa klien hanya saling melihat sesamanya, tidak pernah data klien mana pun.
- **Data bersama:** Tingkat Jabatan, Posisi, dan Roles dipakai semua klien, jadi hanya Superadmin yang mengubahnya. Admin dan PIC memilih dari daftar itu.
- **Horizon dan Log Viewer:** hanya Superadmin, karena menampilkan data semua klien.
- **Dari aplikasi:** PIC dan Admin badan usaha juga mengatur Kebijakan absensi, Lokasi absensi, Badan usaha & divisi, serta Akun & akses dari aplikasi mobile, dengan aturan yang sama persis dengan panel web (`/api/v1/client/*`, lihat API.md 3.13). Alur Persetujuan, Master Shift, dan import/export tetap di web.

## Role

| Role | Fungsi |
|---|---|
| Superadmin | Pemilik platform. Membuat klien dan PIC-nya. Akses datanya seluruh platform. |
| PIC | Pemilik akun klien. Selalu juga Admin. Menambah atau menghapus badan usaha kliennya dan membuat akun Admin. |
| Admin | Mengelola pekerja, absensi, shift, pengajuan, dan akun di dalam Akses Data-nya. |
| BOD | Menyetujui tahap BOD untuk pekerja di dalam Akses Data-nya. |
| Approver | Menyetujui tahap Approver untuk pekerja di dalam Akses Data-nya. |
| Employee | Absen dan membuat pengajuan untuk dirinya sendiri. Cuti tahunan hanya bila HR menyalakannya di data karyawan. |
| Intern | Seperti Employee, tanpa menu Kalender Cuti Tim dan tanpa cuti tahunan. |
| Freelancer | Seperti Employee, tanpa cuti tahunan. |

Nama role di atas dipakai langsung oleh kode, jadi tidak bisa diganti atau dihapus dari menu Roles.

## Akses Data

| Tingkat | Data yang terlihat |
|---|---|
| Semua | Seluruh badan usaha milik kliennya (Superadmin: seluruh platform). |
| Badan Usaha | Badan usaha yang dipilih. |
| Divisi | Divisi yang dipilih beserta semua sub-divisinya. |
| Pribadi | Hanya data milik sendiri. |

Aturan umum:

- **Yang mengatur:** Superadmin, PIC, dan Admin, di menu Manajemen User, bagian Akses Data. Selain Superadmin, tidak boleh lebih luas dari akses sendiri.
- **Akun tanpa profil pekerja** (mis. BOD atau Admin lain): hanya dikelola PIC atau Admin berakses Semua. Admin berakses Badan Usaha atau Divisi hanya mengelola akun pekerja di dalam aksesnya.
- **Role yang bisa diberikan:** PIC memberi Admin, Koordinator, BOD, Approver, Employee, Intern, Freelancer; Admin memberi yang sama tanpa Admin. Superadmin, PIC, dan role tambahan hanya oleh Superadmin. Akun dengan role yang tidak bisa diberikan seseorang juga tidak bisa ia ubah.
- **Reset password:** akun langsung keluar dari aplikasi di semua perangkat.
- **Akun baru:** mulai dari Pribadi.
- **Superadmin:** selalu Semua, apa pun pilihan di formnya.
- **Data milik sendiri:** selalu terlihat oleh pemiliknya, di tingkat mana pun.
- **Sub-divisi:** ikut otomatis, termasuk sub-divisi yang dibuat belakangan.
- **Kalender cuti:** juga menampilkan rekan satu badan usaha, supaya pekerja tahu siapa yang sedang cuti.

## Kombinasi role dan Akses Data

| Role | Semua atau Badan Usaha | Divisi | Pribadi |
|---|---|---|---|
| Admin | Mengelola pekerja dan pengaturan badan usaha: lokasi, divisi, master shift, alur persetujuan, dan import Talenta. | Mengelola pekerja, jadwal shift, pengajuan, dan akun di divisi itu saja. Pengaturan badan usaha tidak tersedia. | Tidak mengelola apa pun. |
| Approver atau BOD | Menyetujui tahap sesuai role-nya untuk badan usaha itu. | Menyetujui tahap sesuai role-nya untuk divisi itu. | Tidak menyetujui pengajuan orang lain. |

Akses Data tidak pernah menggantikan role. BOD dengan akses Semua tetap tidak bisa menyetujui tahap Approver.

## Menu panel

| Menu | Superadmin | Admin | Approver dan BOD | Pekerja |
|---|---|---|---|---|
| Presensi Mandiri | Tidak | Jika punya data pekerja | Jika punya data pekerja | Ya |
| Pengajuan cuti, lembur, koreksi | Semua data | Dalam akses | Dalam akses | Milik sendiri |
| Pengunduran Diri Karyawan | Semua data | Dalam akses | Dalam akses | Milik sendiri, khusus karyawan |
| Laporan Absensi | Semua data | Dalam akses | Dalam akses | Milik sendiri |
| Kalender Cuti Tim | Semua data | Dalam akses dan rekan | Dalam akses dan rekan | Rekan satu badan usaha, kecuali magang |
| Persetujuan Masuk | Ya | Jika akses bukan Pribadi | Ya | Tidak |
| Data Absensi, Data Karyawan, Magang, Freelance | Ya | Jika akses bukan Pribadi | Tidak | Tidak |
| Jadwal Shift, Manajemen User | Ya | Jika akses bukan Pribadi | Tidak | Tidak |
| Klien | Ya | Tidak | Tidak | Tidak |
| Badan Usaha, Divisi, Lokasi, Master Shift, Alur Persetujuan | Ya | Akses Semua atau Badan Usaha (tambah dan hapus badan usaha: hanya PIC) | Tidak | Tidak |
| Tingkat Jabatan, Posisi | Ya | Lihat saja | Tidak | Tidak |
| Roles | Ya | Tidak | Tidak | Tidak |

Admin tidak bisa memberi role Superadmin, PIC, atau Admin, dan tidak bisa mengubah akun Superadmin, PIC, atau Admin lain. PIC bisa memberi role Admin dan mengelola semua akun kliennya, kecuali akun PIC lain.

## Alur persetujuan

Tahap persetujuan diatur per badan usaha dan jenis pengajuan di menu Alur Persetujuan Perusahaan. Badan usaha tanpa pengaturan memakai tahap bawaan berikut.

| Jenis pengajuan | Tahap bawaan |
|---|---|
| Semua jenis pengajuan | Approver |

Badan usaha yang butuh BOD atau tahap lain menambahkannya di Alur Persetujuan. Superadmin tidak lagi menjadi tahap persetujuan klien. Tahap lama yang masih memakai role Superadmin sudah dialihkan ke Admin klien.

Seseorang boleh memproses tahap yang sedang aktif bila salah satu syarat ini terpenuhi:

1. **Role cocok:** role-nya sama dengan role tahap, dan pengaju ada di dalam Akses Data-nya.
2. **Ditunjuk langsung:** tahap itu menunjuk akunnya sebagai penyetuju.
3. **Superadmin:** boleh memproses tahap apa pun untuk keperluan bantuan, tetapi tidak pernah dinotifikasi dan tidak dihitung sebagai penyetuju tahap.
4. **Admin, PIC, dan Koordinator:** boleh memproses tahap apa pun untuk pekerja di dalam Akses Data-nya.

Batasan yang selalu berlaku:

- **Pengajuan sendiri:** pengaju tidak pernah memberi persetujuan akhir untuk pengajuannya sendiri. Superadmin, Admin, dan Koordinator tidak boleh memproses pengajuannya sendiri sama sekali.
- **Cukup satu penyetuju:** bila beberapa orang berhak di tahap yang sama, persetujuan dari salah satunya sudah memajukan pengajuan.
- **Data berubah di tahap akhir:** data absensi dan cuti baru berubah setelah tahap terakhir disetujui.
- **Penolakan:** penolakan di tahap mana pun langsung menghentikan pengajuan.
- **Notifikasi:** penyetuju yang berhak mendapat notifikasi di panel setiap kali pengajuan masuk ke tahapnya.

Pengajuan lama yang tersimpan tanpa tahap bisa dibuatkan tahapnya dengan perintah ini:

```bash
php artisan approvals:generate-missing-steps
```

## Contoh pengaturan

| Kebutuhan | Role | Akses Data |
|---|---|---|
| Pemilik akun klien | PIC (dibuat Superadmin di menu Klien) | Semua |
| HRD yang mengelola seluruh klien | Admin | Semua |
| HRD yang mengelola satu badan usaha | Admin | Badan Usaha: badan usaha itu |
| Admin cabang yang hanya mengurus satu depo | Admin | Divisi: depo itu |
| Kepala divisi yang menyetujui timnya | Approver | Divisi: divisi itu |
| Direktur yang menyetujui seluruh klien | BOD | Semua |
| Karyawan biasa | Employee | Pribadi |

## Riwayat

Sebelumnya, penyetuju diatur lewat menu Penugasan Approver, yang menyimpan datanya di tabel `approvers`. Admin Badan Usaha diatur lewat pilihan badan usaha di form user. Keduanya sudah dipindahkan otomatis ke Akses Data. Menu lamanya disembunyikan, dan tabel `approvers` disimpan sebagai riwayat.
