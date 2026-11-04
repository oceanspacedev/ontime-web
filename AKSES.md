# Role, Akses Data, dan Persetujuan

Hak setiap akun di OnTime ditentukan oleh dua hal:

- **Role** menentukan apa yang boleh dilakukan, misalnya mengelola karyawan atau menyetujui cuti.
- **Akses Data** menentukan untuk pekerja mana hak itu berlaku.

Aturan ini dipakai di semua tempat: panel admin, laporan, kalender, API mobile, import dan ekspor, serta hak menyetujui. Seluruh aturannya ada di `app/Services/DataAccessService.php`.

## Role

| Role | Fungsi |
|---|---|
| Superadmin | Mengatur seluruh sistem. Akses datanya selalu Semua. |
| Admin | Mengelola pekerja, absensi, shift, pengajuan, dan akun di dalam Akses Data-nya. |
| BOD | Menyetujui tahap BOD untuk pekerja di dalam Akses Data-nya. |
| Approver | Menyetujui tahap Approver untuk pekerja di dalam Akses Data-nya. |
| Employee | Absen dan membuat pengajuan untuk dirinya sendiri. |
| Intern | Seperti Employee, tanpa menu Kalender Cuti Tim. |
| Freelancer | Seperti Employee, tanpa cuti tahunan. |

Nama role di atas dipakai langsung oleh kode, jadi tidak bisa diganti atau dihapus dari menu Roles.

## Akses Data

| Tingkat | Data yang terlihat |
|---|---|
| Semua | Seluruh badan usaha. |
| Badan Usaha | Badan usaha yang dipilih. |
| Divisi | Divisi yang dipilih beserta semua sub-divisinya. |
| Pribadi | Hanya data milik sendiri. |

Aturan umum:

- **Yang mengatur:** hanya Superadmin, di menu Manajemen User, bagian Akses Data.
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
| Badan Usaha, Divisi, Lokasi, Master Shift, Alur Persetujuan | Ya | Akses Semua atau Badan Usaha | Tidak | Tidak |
| Tingkat Jabatan, Posisi, Roles | Ya | Tidak | Tidak | Tidak |

Admin tidak bisa memberi role Superadmin atau Admin, dan tidak bisa mengubah akun Superadmin atau Admin lain.

## Alur persetujuan

Tahap persetujuan diatur per badan usaha dan jenis pengajuan di menu Alur Persetujuan Perusahaan. Badan usaha tanpa pengaturan memakai tahap bawaan berikut.

| Jenis pengajuan | Tahap bawaan |
|---|---|
| Cuti dan izin, lembur, koreksi absensi, pengunduran diri | Approver, lalu BOD, lalu Superadmin |
| Absen di luar geofence | Approver |

Seseorang boleh memproses tahap yang sedang aktif bila salah satu syarat ini terpenuhi:

1. **Role cocok:** role-nya sama dengan role tahap, dan pengaju ada di dalam Akses Data-nya.
2. **Ditunjuk langsung:** tahap itu menunjuk akunnya sebagai penyetuju.
3. **Superadmin:** boleh memproses tahap apa pun.
4. **Admin:** boleh memproses tahap apa pun untuk pekerja di dalam Akses Data-nya.

Batasan yang selalu berlaku:

- **Pengajuan sendiri:** pengaju tidak pernah memberi persetujuan akhir untuk pengajuannya sendiri. Superadmin dan Admin tidak boleh memproses pengajuannya sendiri sama sekali.
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
| HRD yang mengelola satu badan usaha | Admin | Badan Usaha: badan usaha itu |
| Admin cabang yang hanya mengurus satu depo | Admin | Divisi: depo itu |
| Kepala divisi yang menyetujui timnya | Approver | Divisi: divisi itu |
| Direktur yang menyetujui seluruh grup | BOD | Semua |
| Karyawan biasa | Employee | Pribadi |

## Riwayat

Sebelumnya, penyetuju diatur lewat menu Penugasan Approver, yang menyimpan datanya di tabel `approvers`. Admin Badan Usaha diatur lewat pilihan badan usaha di form user. Keduanya sudah dipindahkan otomatis ke Akses Data. Menu lamanya disembunyikan, dan tabel `approvers` disimpan sebagai riwayat.
