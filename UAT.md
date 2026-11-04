# Panduan UAT

Daftar skenario uji penerimaan untuk OnTime. Centang setiap langkah yang lulus, lalu isi lembar persetujuan di bagian akhir.

## Persiapan

Siapkan satu badan usaha uji dengan dua divisi induk, misalnya Sales dan Finance. Tambahkan satu sub-divisi di bawah Sales.

Siapkan juga akun uji berikut lewat menu Manajemen User:

| Akun | Role | Akses Data |
|---|---|---|
| Superadmin | Superadmin | Semua |
| Admin badan usaha | Admin | Badan Usaha: badan usaha uji |
| Admin divisi | Admin | Divisi: Sales |
| Approver Sales | Approver, dengan data karyawan di Sales | Divisi: Sales |
| Approver Finance | Approver, dengan data karyawan di Finance | Divisi: Finance |
| BOD | BOD | Badan Usaha: badan usaha uji |
| Karyawan Sales | Employee, ditempatkan di sub-divisi Sales | Pribadi |
| Karyawan Finance | Employee, ditempatkan di Finance | Pribadi |
| Magang dan Freelancer | Intern dan Freelancer | Pribadi |

Jangan menulis password akun uji di dokumen ini.

## 1. Akses Data

- [ ] **1.1** Superadmin membuka Manajemen User dan mengubah Akses Data sebuah akun. Kolom Akses Data di daftar user ikut berubah.
- [ ] **1.2** Admin badan usaha tidak melihat bagian Akses Data di form user.
- [ ] **1.3** Admin badan usaha melihat semua pekerja badan usaha uji dan tidak melihat badan usaha lain.
- [ ] **1.4** Admin divisi hanya melihat pekerja Sales dan sub-divisinya. Menu Badan Usaha, Divisi, Lokasi, Master Shift, dan Alur Persetujuan tidak muncul.
- [ ] **1.5** Superadmin menambah sub-divisi baru di bawah Sales. Admin divisi langsung bisa memilih sub-divisi itu.
- [ ] **1.6** Akun dengan role Admin dan akses Pribadi tidak melihat menu pengelolaan apa pun.

## 2. Struktur organisasi

- [ ] **2.1** Superadmin mengubah Kebijakan Absensi badan usaha, misalnya toleransi terlambat, lalu menyimpannya.
- [ ] **2.2** Daftar divisi menampilkan susunan bertingkat. Divisi tidak bisa dijadikan induk bagi dirinya sendiri atau bagi induknya.
- [ ] **2.3** Lokasi kantor bisa dipilih lewat peta, lengkap dengan radius geofence.

## 3. Data pekerja, import, dan ekspor

- [ ] **3.1** Admin membuat karyawan baru, lalu memakai aksi Buat Akun. Info akun terkirim lewat WhatsApp bila gateway aktif.
- [ ] **3.2** Admin divisi tidak bisa menempatkan karyawan di divisi di luar aksesnya.
- [ ] **3.3** Import CSV / Excel dengan file contoh berhasil. Baris yang gagal bisa diunduh beserta alasannya.
- [ ] **3.4** Export CSV / Excel mengikuti filter tabel, dan hasilnya bisa diimport kembali tanpa perubahan.
- [ ] **3.5** Import Talenta dengan mode pratinjau menampilkan ringkasan tanpa menyimpan data.

## 4. Shift

- [ ] **4.1** Admin membuat master shift, misalnya Pagi dan Siang.
- [ ] **4.2** Aksi Atur Shift Default memberi shift ke beberapa karyawan sekaligus.
- [ ] **4.3** Aksi Atur Jadwal di menu Jadwal Shift mengisi jadwal massal hanya pada hari yang dipilih.
- [ ] **4.4** Keterlambatan dihitung dari jadwal shift, lalu shift default, lalu jam kerja badan usaha.

## 5. Presensi

- [ ] **5.1** Karyawan check-in dan check-out lewat Presensi Mandiri. Foto dan GPS diminta sesuai kebijakan badan usaha.
- [ ] **5.2** Check-in di luar radius geofence tersimpan sebagai pengajuan dan masuk ke Persetujuan Masuk milik Approver.
- [ ] **5.3** Superadmin tidak melihat menu Presensi Mandiri.

## 6. Pengajuan

- [ ] **6.1** Cuti tahunan yang melebihi sisa kuota ditolak dengan pesan yang jelas.
- [ ] **6.2** Freelancer hanya bisa mengajukan izin dan sakit, tanpa cuti tahunan.
- [ ] **6.3** Setelah lembur disetujui, aksi Isi Durasi Aktual tersedia. Durasi yang melebihi estimasi diberi tanda.
- [ ] **6.4** Koreksi absensi tersimpan pada tanggal yang dikoreksi.
- [ ] **6.5** Pengajuan resign bisa dicetak lewat aksi Cetak Surat.
- [ ] **6.6** Aksi Lacak Progres menampilkan tahap persetujuan dan siapa yang memprosesnya.

## 7. Persetujuan

- [ ] **7.1** Karyawan Sales mengajukan cuti. Approver Sales mendapat notifikasi, sedangkan Approver Finance tidak.
- [ ] **7.2** Approver Sales menyetujui tahap pertama. Pengajuan pindah ke tahap BOD, dan data absensi belum berubah.
- [ ] **7.3** BOD menyetujui tahap kedua, lalu Superadmin menyetujui tahap akhir. Data absensi baru berubah setelah tahap akhir.
- [ ] **7.4** Penolakan di tahap mana pun langsung menghentikan pengajuan.
- [ ] **7.5** Approver yang mengajukan cuti untuk dirinya sendiri tidak bisa memberi persetujuan akhir.
- [ ] **7.6** Admin divisi bisa memproses pengajuan karyawan Sales, tetapi tidak bisa memproses pengajuan karyawan Finance.

## 8. Laporan dan kalender

- [ ] **8.1** Laporan Absensi untuk Approver Sales hanya berisi data Sales. Pilihan filter badan usaha dan divisi ikut terbatas.
- [ ] **8.2** Export ke Excel menghasilkan data yang sama dengan isi tabel.
- [ ] **8.3** Kalender Cuti Tim untuk karyawan menampilkan rekan satu badan usaha.
- [ ] **8.4** Peserta magang tidak melihat menu Kalender Cuti Tim.

## 9. API mobile

- [ ] **9.1** Login, check-in, dan pengajuan cuti lewat API berhasil, sesuai [API.md](API.md).
- [ ] **9.2** Approver bisa memproses pengajuan lewat API hanya untuk pekerja di dalam aksesnya.
- [ ] **9.3** Daftar master karyawan lewat API hanya berisi pekerja di dalam akses pemanggil.

## Lembar persetujuan

| Peran | Nama penguji | Hasil | Catatan | Tanggal |
|---|---|---|---|---|
| Superadmin atau HR pusat | | Lulus / Gagal | | |
| Admin badan usaha | | Lulus / Gagal | | |
| Approver atau kepala divisi | | Lulus / Gagal | | |
| Karyawan | | Lulus / Gagal | | |
