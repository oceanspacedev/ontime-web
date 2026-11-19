<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\CompanyLocationResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\Users\UserResource;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Tutorial videos for every panel user: the whole OnTime flow, the web panel for PIC and
 * koordinator, and the mobile app. Each guide has a short video and steps synced to it.
 *
 * Videos live in resources/videos/panduan and are served by PanduanVideoController. The "at" of a
 * step is the second the step starts in its video. A guide's button only appears when the user may
 * open the page it points to.
 */
class Panduan extends Page
{
    protected string $view = 'filament.pages.panduan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'Panduan';

    protected static string|UnitEnum|null $navigationGroup = 'Bantuan';

    /** Groups follow the order of their first item, so a high sort keeps Bantuan at the bottom of the sidebar. */
    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Panduan';

    protected static ?string $slug = 'panduan';

    public function getSubheading(): ?string
    {
        return 'Pilih topik, tonton videonya, lalu klik langkah untuk melompat ke bagian itu.';
    }

    /**
     * @return list<array{
     *     id: string,
     *     group: string,
     *     title: string,
     *     summary: string,
     *     audience: string,
     *     video: string,
     *     poster: string,
     *     steps: list<array{text: string, at: float}>,
     *     action: array{label: string, url: string}|null,
     * }>
     */
    public function guides(): array
    {
        $start = 'Mulai di sini';
        $pic = 'Panel web · PIC';
        $coordinator = 'Panel web · Koordinator';
        $app = 'Aplikasi HP';

        return [
            $this->guide('00-ringkasan-alur-ontime', $start, 'Alur lengkap OnTime',
                summary: 'Seluruh proses dalam satu video: PIC menyiapkan klien, koordinator menyusun jadwal, karyawan absen dan mengajukan (termasuk koreksi dan tukar shift), atasan menyetujui, lalu rekap untuk payroll.',
                audience: 'Semua pengguna',
                steps: [
                    ['PIC menyiapkan klien', 4],
                    ['Koordinator menyusun jadwal', 201.7],
                    ['PIC dan koordinator dari HP', 309.9],
                    ['Karyawan absen dan mengajukan', 404.5],
                    ['Atasan menyetujui', 636.8],
                    ['Rekap dan laporan', 719.3],
                ],
                action: null,
            ),

            $this->guide('pic-01-masuk-dasbor', $pic, 'Masuk dan kenali dasbor',
                summary: 'Masuk ke panel dengan akun PIC dan mengenal menu yang tersedia.',
                audience: 'PIC',
                steps: [
                    ['Buka OnTime di browser, halaman Masuk', 0],
                    ['Isi email dan kata sandi akun PIC', 1.5],
                    ['Klik Masuk', 5.4],
                    ['Dasbor: siapa terjadwal dan sudah absen hari ini', 8.7],
                    ['Menu Persetujuan dan Struktur Organisasi', 13.5],
                    ['Menu Manajemen SDM dan Jadwal & Shift', 16.7],
                    ['Menu Presensi & Pengajuan dan Pengaturan Sistem', 19.9],
                ],
                action: ['label' => 'Buka Dasbor', 'url' => Dashboard::getUrl()],
            ),
            $this->guide('pic-08-tambah-badan-usaha', $pic, 'Tambah badan usaha',
                summary: 'Menambah badan usaha baru ke klien Anda, lalu melanjutkan ke kebijakan absensi dan divisinya.',
                audience: 'PIC',
                steps: [
                    ['Buka Struktur Organisasi → Badan Usaha', 0],
                    ['Klik Buat Badan Usaha', 2.6],
                    ['Isi nama dan kode badan usaha', 5.2],
                    ['Email, telepon, dan alamat kantor pusat (opsional)', 9.6],
                    ['Koordinat kantor pusat bisa dipilih di peta', 16.2],
                    ['Tab Lokasi & Cabang: outlet bisa ditambah di sini atau nanti', 18.8],
                    ['Klik Buat, badan usaha masuk ke klien Anda', 23.8],
                    ['Lanjut atur Kebijakan Absensi dan divisinya', 27.9],
                ],
                action: $this->resourceAction(CompanyResource::class),
            ),
            $this->guide('pic-02-kebijakan-absensi', $pic, 'Atur kebijakan absensi',
                summary: 'Menentukan cara absen tiap badan usaha: toleransi terlambat, wajib GPS, verifikasi selfie, kuota cuti, dan tahap approval.',
                audience: 'PIC',
                steps: [
                    ['Buka Struktur Organisasi → Badan Usaha', 0],
                    ['Daftar badan usaha milik klien Anda', 2.6],
                    ['Klik Kebijakan Absensi pada badan usaha', 5.9],
                    ['Atur toleransi keterlambatan (menit)', 8.2],
                    ['Wajib Lokasi GPS: nyalakan bila absen harus di area outlet', 10.3],
                    ['Pilih cara verifikasi: tanpa foto, selfie, atau cocokkan wajah', 16.5],
                    ['Atur kuota cuti, tahap approval, dan jadwal shift', 19.4],
                    ['Klik Simpan Kebijakan', 23.5],
                ],
                action: $this->resourceAction(CompanyResource::class),
            ),
            $this->guide('pic-03-divisi-lokasi', $pic, 'Divisi dan lokasi outlet',
                summary: 'Mengatur brand atau divisi tiap badan usaha dan menambah outlet beserta radius absennya.',
                audience: 'PIC',
                steps: [
                    ['Buka Divisi Perusahaan: brand atau divisi tiap badan usaha', 0],
                    ['Buka Lokasi & Cabang Kantor: titik absen tiap outlet', 5.6],
                    ['Klik Buat untuk menambah outlet baru', 8.7],
                    ['Pilih badan usaha dan brand', 11.0],
                    ['Isi nama outlet dan koordinatnya', 16.1],
                    ['Radius geofence: jarak maksimal absen dari titik outlet', 21.7],
                    ['Klik Buat, outlet siap dipakai untuk jadwal dan absen', 27.1],
                ],
                action: $this->resourceAction(CompanyLocationResource::class),
            ),
            $this->guide('pic-04-akun-koordinator', $pic, 'Buat akun koordinator',
                summary: 'Membuat akun koordinator dan membatasi datanya ke divisi yang ia kelola.',
                audience: 'PIC',
                steps: [
                    ['Buka Pengaturan Sistem → Manajemen User', 0],
                    ['Klik Buat untuk akun baru', 4.5],
                    ['Isi nama, email, dan kata sandi awal', 6.8],
                    ['Pilih role Koordinator', 13.5],
                    ['Matikan data karyawan bila akun ini hanya mengelola', 16.1],
                    ['Akses Data: batasi ke divisi yang dikelola', 17.8],
                    ['Klik Buat, akun koordinator siap dipakai', 23.6],
                ],
                action: $this->resourceAction(UserResource::class),
            ),
            $this->guide('pic-05-data-pekerja', $pic, 'Data pekerja dan akunnya',
                summary: 'Memasukkan karyawan, magang, dan freelance, lalu membuatkan akun agar mereka bisa masuk ke aplikasi.',
                audience: 'PIC',
                steps: [
                    ['Buka Manajemen SDM → Data Karyawan', 0],
                    ['Import / Export: masukkan banyak karyawan sekaligus dari Excel', 3.2],
                    ['Atau tambah satu per satu dengan Buat Karyawan', 7.9],
                    ['Data Magang dan Data Freelance bekerja dengan cara yang sama', 9.8],
                    ['Klik Buat Akun agar pekerja bisa masuk ke aplikasi', 12.9],
                    ['Konfirmasi Buat akun: email dan kata sandi dibuat otomatis', 15.2],
                    ['Kirim info akun ke pekerja lewat tombol Kirim via WhatsApp', 19.3],
                ],
                action: $this->resourceAction(EmployeeResource::class),
            ),
            $this->guide('pic-06-alur-persetujuan', $pic, 'Alur persetujuan',
                summary: 'Menentukan siapa yang menyetujui tiap jenis pengajuan, termasuk persetujuan bertingkat dan aturan khusus per divisi.',
                audience: 'PIC',
                steps: [
                    ['Buka Persetujuan → Alur Persetujuan Perusahaan', 0],
                    ['Tiap jenis pengajuan punya jumlah tahap sendiri', 2.6],
                    ['Klik badan usaha untuk mengatur alurnya', 5.4],
                    ['Pilih tab jenis pengajuan, misalnya Cuti / Izin', 8.1],
                    ['Tentukan siapa yang menyetujui: role atau user tertentu', 10.7],
                    ['Tambah tahap bila perlu persetujuan bertingkat', 14.6],
                    ['Aturan per Divisi: alur khusus untuk divisi tertentu', 16.4],
                    ['Klik Simpan', 19.2],
                ],
                action: $this->resourceAction(ApprovalFlowResource::class),
            ),
            $this->guide('pic-07-laporan-absensi', $pic, 'Laporan absensi',
                summary: 'Melihat ringkasan dan rincian kehadiran per periode, lalu mengekspornya ke Excel untuk payroll.',
                audience: 'PIC',
                steps: [
                    ['Buka Presensi & Pengajuan → Laporan Absensi', 0],
                    ['Pilih periode laporan', 4.0],
                    ['Saring per badan usaha atau divisi', 8.6],
                    ['Ringkasan: hadir, terlambat, cuti, tidak hadir, jam kerja, lembur', 12.2],
                    ['Rincian per pekerja per hari', 15.8],
                    ['Klik Export ke Excel untuk payroll', 18.7],
                ],
                action: $this->pageAction(LaporanAbsensi::class),
            ),

            $this->guide('k-01-masuk-dasbor', $coordinator, 'Masuk dan kenali dasbor',
                summary: 'Masuk dengan akun koordinator dan melihat kehadiran outlet yang Anda pegang hari ini.',
                audience: 'Koordinator',
                steps: [
                    ['Masuk dengan akun koordinator', 0],
                    ['Hari Ini di Outlet Saya: hanya outlet yang Anda pegang', 7.7],
                    ['Lihat siapa sudah masuk, terlambat, atau belum absen', 10.2],
                    ['Menu koordinator: presensi, jadwal, persetujuan, freelance', 14.8],
                ],
                action: ['label' => 'Buka Dasbor', 'url' => Dashboard::getUrl()],
            ),
            $this->guide('k-02-master-shift', $coordinator, 'Master shift',
                summary: 'Membuat jam kerja (shift) untuk brand yang Anda kelola.',
                audience: 'Koordinator',
                steps: [
                    ['Buka Jadwal & Shift → Master Shift', 0],
                    ['Daftar jam kerja tiap brand', 2.6],
                    ['Klik Buat Shift untuk jam kerja baru', 4.6],
                    ['Pilih badan usaha dan brand', 6.8],
                    ['Isi nama, kode, jam masuk, dan jam pulang', 11.9],
                    ['Klik Buat', 18.7],
                ],
                action: $this->resourceAction(ShiftResource::class),
            ),
            $this->guide('k-03-jadwal-mingguan', $coordinator, 'Jadwal mingguan',
                summary: 'Menjadwalkan pekerja langsung dari tabel seminggu: satu baris per pekerja, satu kolom per hari.',
                audience: 'Koordinator',
                steps: [
                    ['Buka Jadwal & Shift → Jadwal Mingguan', 0],
                    ['Satu baris per pekerja, satu kolom per hari', 3.2],
                    ['Jadwal bergembok sudah ada absensinya, ubah lewat koreksi', 4.8],
                    ['Klik sel kosong untuk menjadwalkan', 7.1],
                    ['Pilih Masuk, lalu outlet, shift, dan section', 9.4],
                    ['Klik Simpan (Libur untuk hari off pekerja)', 15.9],
                    ['Jadwal baru langsung tampil di tabel', 20.3],
                    ['Minggu depan: siapkan jadwal sebelum minggu berjalan', 22.5],
                ],
                action: $this->pageAction(JadwalMingguan::class),
            ),
            $this->guide('k-04-atur-jadwal', $coordinator, 'Atur jadwal banyak hari',
                summary: 'Mengisi jadwal satu pekerja untuk banyak hari sekaligus, menyalin minggu lalu, dan impor/ekspor Excel.',
                audience: 'Koordinator',
                steps: [
                    ['Buka Jadwal & Shift → Jadwal Shift', 0],
                    ['Atur Jadwal: isi jadwal satu pekerja untuk banyak hari', 3.2],
                    ['Pilih badan usaha dan pekerja', 5.5],
                    ['Tentukan rentang tanggal dan hari kerja', 11.7],
                    ['Pilih shift, outlet, dan section', 16.3],
                    ['Klik Simpan Jadwal', 23.6],
                    ['Salin Minggu: salin jadwal minggu lalu ke minggu ini', 26.9],
                    ['Ekspor dan Impor Excel untuk jadwal dalam jumlah besar', 28.8],
                ],
                action: $this->resourceAction(ShiftScheduleResource::class),
            ),
            $this->guide('k-05-persetujuan', $coordinator, 'Menyetujui pengajuan',
                summary: 'Memutuskan pengajuan cuti, izin, dan lembur dari tim lewat panel web.',
                audience: 'Koordinator dan atasan',
                steps: [
                    ['Buka Persetujuan (Inbox) → Persetujuan Masuk', 0],
                    ['Semua pengajuan tim yang menunggu keputusan Anda', 2.9],
                    ['Jenis, tanggal, alasan, dan tahap persetujuan', 6.6],
                    ['Tolak meminta alasan; Setujui untuk menyetujui', 9.9],
                    ['Konfirmasi, pengajuan langsung selesai', 13.7],
                ],
                action: $this->pageAction(ApprovalSaya::class),
            ),
            $this->guide('k-06-data-absensi', $coordinator, 'Data absensi tim',
                summary: 'Melihat jam masuk dan pulang tim, mencari pekerja, dan mengunduh rekap bulanan ke Excel.',
                audience: 'Koordinator',
                steps: [
                    ['Buka Presensi & Pengajuan → Data Absensi', 0],
                    ['Jam masuk, jam pulang, shift, dan outlet tiap pekerja', 3.4],
                    ['Cari nama pekerja', 6.3],
                    ['Rekap Bulanan: unduh rekap tim per bulan ke Excel', 12.4],
                    ['Klik Unduh Excel', 16.3],
                ],
                action: $this->resourceAction(AttendanceResource::class),
            ),

            $this->guide('m-01-karyawan-absen-masuk', $app, 'Karyawan: absen masuk',
                summary: 'Masuk ke aplikasi OnTime dan absen masuk di awal shift.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Buka aplikasi OnTime, isi email', 0],
                    ['Isi kata sandi, lalu tekan Masuk', 9.6],
                    ['Beranda: shift dan outlet hari ini, tekan Absen Masuk', 17.6],
                    ['Mode Klik saja: tekan Kirim Absen Masuk', 20.7],
                    ['Jam masuk tercatat di Beranda', 23.8],
                    ['Riwayat: semua absensi Anda', 28.8],
                ],
                action: null,
            ),
            $this->guide('m-02-karyawan-pengajuan', $app, 'Karyawan: ajukan cuti atau izin',
                summary: 'Mengajukan cuti atau izin dari HP dan memantau statusnya.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Beranda → menu Cuti / Izin', 0],
                    ['Pilih jenis Izin dan tanggalnya', 2.9],
                    ['Tulis alasan pengajuan', 12.3],
                    ['Tekan Kirim pengajuan', 27.4],
                    ['Status Menunggu sampai disetujui atasan', 30.9],
                ],
                action: null,
            ),
            $this->guide('m-03-karyawan-lembur', $app, 'Karyawan: ajukan lembur',
                summary: 'Mengajukan lembur dengan jam mulai dan selesai; durasinya dihitung otomatis.',
                audience: 'Karyawan',
                steps: [
                    ['Pengajuan → pilih Lembur', 0],
                    ['Tekan Ajukan Lembur', 0.7],
                    ['Pilih tanggal lembur', 3.7],
                    ['Isi jam mulai dan jam selesai lembur', 7.5],
                    ['Durasi dihitung otomatis; tulis pekerjaannya', 18.1],
                    ['Tekan Kirim pengajuan', 35.7],
                    ['Pengajuan lembur menunggu persetujuan', 39.2],
                ],
                action: null,
            ),
            $this->guide('m-04-freelancer-absen-pulang', $app, 'Absen pulang',
                summary: 'Absen pulang di akhir shift. Contohnya akun freelancer, caranya sama untuk karyawan dan magang.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Beranda: shift hari ini dan jam masuk', 0],
                    ['Shift selesai: tekan Absen Pulang', 3.0],
                    ['Tekan Kirim Absen Pulang', 6.5],
                    ['Jam masuk dan pulang tercatat', 10.5],
                ],
                action: null,
            ),
            $this->guide('m-13-karyawan-foto-profil', $app, 'Karyawan: pasang foto profil',
                summary: 'Memasang foto profil dari HP. Bila perusahaan memakai verifikasi wajah, foto ini menjadi pembanding selfie saat absen.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Profil → Foto profil', 0],
                    ['Foto ini juga dipakai untuk verifikasi wajah saat absen', 3.8],
                    ['Ambil dengan kamera, atau pilih dari galeri, lalu Simpan', 7.9],
                    ['Foto tersimpan dan tampil di profil', 12.3],
                ],
                action: null,
            ),
            $this->guide('m-08-karyawan-koreksi-absensi', $app, 'Karyawan: koreksi absensi',
                summary: 'Memperbaiki jam masuk atau pulang yang salah atau terlewat. Jam absen berubah setelah atasan menyetujui.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Pengajuan → Koreksi Absensi', 0],
                    ['Tekan Ajukan Koreksi Absensi', 1.7],
                    ['Pilih tanggal yang absennya salah', 4.9],
                    ['Isi jam masuk yang benar', 12.4],
                    ['Tulis alasannya', 26.7],
                    ['Tekan Kirim pengajuan', 36.2],
                    ['Status Menunggu sampai disetujui atasan', 39.7],
                ],
                action: null,
            ),
            $this->guide('m-09-tukar-shift', $app, 'Karyawan: tukar shift dengan rekan',
                summary: 'Mengajak rekan bertukar shift atau menyerahkan shift ke rekan yang libur. Jadwal berubah setelah rekan menerima dan atasan menyetujui.',
                audience: 'Karyawan, magang, dan freelancer',
                steps: [
                    ['Pengajuan → Tukar Shift', 0],
                    ['Tekan Ajukan Tukar Shift', 1.7],
                    ['Tukar Shift: saling tukar. Serahkan Shift: rekan yang libur menggantikan', 4.0],
                    ['Pilih tanggal shift Anda dan shift rekan', 8.3],
                    ['Daftar rekan dan jadwal mereka di tanggal itu', 21.2],
                    ['Pilih rekan, tulis alasan bila perlu', 26.1],
                    ['Tekan Kirim ajakan', 31.4],
                    ['Jadwal berubah setelah rekan menerima dan atasan menyetujui', 34.4],
                    ['Ajakan terkirim, menunggu jawaban rekan', 37.9],
                    ['Rekan (Bayu) membuka Pengajuan → Tukar Shift', 41.9],
                    ['Rekan melihat rincian jadwal, lalu tekan Terima', 46.9],
                    ['Diterima, kini menunggu persetujuan atasan', 52.4],
                ],
                action: null,
            ),
            $this->guide('m-10-karyawan-pengunduran-diri', $app, 'Karyawan: pengunduran diri',
                summary: 'Mengajukan resign dari HP. Setelah disetujui, akun nonaktif pada hari kerja terakhir.',
                audience: 'Karyawan',
                steps: [
                    ['Pengajuan → Pengunduran Diri', 0],
                    ['Tekan Ajukan Pengunduran Diri', 1.6],
                    ['Pengajuan ini langsung ke HR, bicarakan dulu dengan atasan', 3.8],
                    ['Pilih tanggal pengajuan dan hari kerja terakhir', 7.3],
                    ['Tulis alasan dan catatan serah terima', 16.7],
                    ['Tekan Kirim pengajuan resign', 26.8],
                    ['Setelah disetujui, akun nonaktif pada hari kerja terakhir', 30.3],
                    ['Status Menunggu Persetujuan HR', 33.3],
                ],
                action: null,
            ),
            $this->guide('m-14-magang-freelance', $app, 'Magang dan freelance: apa bedanya',
                summary: 'Magang dan freelance memakai aplikasi yang sama dengan karyawan. Bedanya ada di jenis cuti dan beberapa menu.',
                audience: 'Magang dan freelancer',
                steps: [
                    ['Magang: absen, izin, lembur, dan koreksi sama seperti karyawan', 0],
                    ['Bedanya: tidak ada Kalender Cuti dan cuti tahunan', 3.8],
                    ['Jenis cuti magang: Izin, Sakit, Melahirkan, Tanpa Gaji', 6.8],
                    ['Profil magang: tanpa menu Pengunduran diri', 12.1],
                    ['Freelance: menu sama, plus Kalender Cuti', 16.1],
                    ['Jenis cuti freelance hanya Izin dan Sakit', 19.3],
                ],
                action: null,
            ),
            $this->guide('m-05-koordinator-tim', $app, 'Koordinator: tim dan jadwal',
                summary: 'Memantau kehadiran tim dan menjadwalkan pekerja langsung dari HP.',
                audience: 'Koordinator',
                steps: [
                    ['Beranda koordinator: ringkasan tim hari ini', 0],
                    ['Buka tab Tim', 3.0],
                    ['Hari: status absen tiap pekerja per outlet', 6.5],
                    ['Minggu: jadwal seminggu tim Anda', 8.5],
                    ['Rekap: kehadiran tim per bulan', 12.5],
                    ['Tombol + : jadwalkan pekerja, salin minggu lalu, freelancer baru', 16.5],
                    ['Jadwalkan pekerja', 20.5],
                    ['Pilih pekerja', 24.0],
                    ['Pilih Masuk, outlet, dan shift, lalu Simpan', 32.4],
                    ['Jadwal baru langsung tampil di tim', 39.6],
                ],
                action: null,
            ),
            $this->guide('m-06-koordinator-persetujuan', $app, 'Koordinator: menyetujui dari HP',
                summary: 'Menyetujui atau menolak pengajuan tim dari tab Persetujuan.',
                audience: 'Koordinator dan atasan',
                steps: [
                    ['Buka tab Persetujuan (angka = menunggu)', 0],
                    ['Daftar pengajuan dari tim Anda', 3.5],
                    ['Buka pengajuan untuk melihat rinciannya', 6.0],
                    ['Tekan Setujui (atau Tolak dengan alasan)', 9.5],
                    ['Status jadi Disetujui, pekerja langsung tahu', 16.5],
                    ['Lembur disetujui dengan cara yang sama', 20.0],
                    ['Semua beres: tidak ada pengajuan menunggu', 26.4],
                ],
                action: null,
            ),
            $this->guide('m-11-koordinator-tukar-shift-koreksi', $app, 'Koordinator: setujui koreksi dan tukar shift',
                summary: 'Menyetujui koreksi absensi dan tukar shift tim dari tab Persetujuan. Jam absen dan jadwal langsung diperbarui.',
                audience: 'Koordinator dan atasan',
                steps: [
                    ['Buka tab Persetujuan', 0],
                    ['Koreksi absensi, pengunduran diri, dan tukar shift tim masuk ke sini', 2.6],
                    ['Buka koreksi: jam lama dan jam yang diajukan', 5.6],
                    ['Tekan Setujui, jam absen langsung diperbaiki', 9.1],
                    ['Tukar shift: rekan sudah menerima, tinggal Anda setujui', 18.1],
                    ['Setujui: jadwal kedua pekerja langsung ditukar', 23.6],
                ],
                action: null,
            ),
            $this->guide('m-12-koordinator-tambah-freelancer', $app, 'Koordinator: tambah freelancer',
                summary: 'Menambah freelancer baru dari HP; akunnya langsung dibuat dan bisa dikirim lewat WhatsApp.',
                audience: 'Koordinator',
                steps: [
                    ['Tab Tim → Freelancer: daftar freelancer tim', 0],
                    ['Tombol + → Freelancer baru', 2.8],
                    ['Isi nama, nomor HP, dan tanggal mulai kerja', 7.6],
                    ['Pilih outletnya', 14.1],
                    ['Tekan Tambah, akun langsung dibuat', 16.2],
                    ['Kirim info akun lewat WhatsApp, atau langsung Atur jadwal', 19.8],
                ],
                action: null,
            ),
            $this->guide('m-07-pic-dari-hp', $app, 'PIC: menu PIC dari HP',
                summary: 'Mengatur badan usaha, kebijakan absensi, lokasi, dan akun dari menu PIC di aplikasi.',
                audience: 'PIC',
                steps: [
                    ['Beranda PIC: tim di semua outlet', 0],
                    ['Buka Profil → PIC', 2.6],
                    ['Klien Anda, akun, dan daftar badan usaha', 8.0],
                    ['Buka badan usaha', 11.4],
                    ['Kebijakan Absensi: cara absen badan usaha ini', 14.6],
                    ['Jam kerja, toleransi, GPS, dan verifikasi selfie', 18.4],
                    ['Lokasi Absensi: daftar outlet dan radiusnya', 25.7],
                    ['Akun & Akses: buat akun dan atur cakupan data', 33.4],
                    ['Tambah Akun: peran dan akses langsung dari HP', 37.4],
                ],
                action: null,
            ),
        ];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return array{label: string, url: string}|null
     */
    private function resourceAction(string $resource): ?array
    {
        return $resource::canViewAny()
            ? ['label' => 'Buka '.$resource::getPluralModelLabel(), 'url' => $resource::getUrl()]
            : null;
    }

    /**
     * @param  class-string<Page>  $page
     * @return array{label: string, url: string}|null
     */
    private function pageAction(string $page): ?array
    {
        return $page::canAccess()
            ? ['label' => 'Buka '.$page::getNavigationLabel(), 'url' => $page::getUrl()]
            : null;
    }

    /**
     * @param  list<array{0: string, 1: float|int}>  $steps
     * @param  array{label: string, url: string}|null  $action
     * @return array{id: string, group: string, title: string, summary: string, audience: string, video: string, poster: string, steps: list<array{text: string, at: float}>, action: array{label: string, url: string}|null}
     */
    private function guide(string $file, string $group, string $title, string $summary, string $audience, array $steps, ?array $action): array
    {
        return [
            'id' => $file,
            'group' => $group,
            'title' => $title,
            'summary' => $summary,
            'audience' => $audience,
            'video' => route('panduan.video', ['file' => "{$file}.mp4"]),
            'poster' => route('panduan.video', ['file' => "{$file}.jpg"]),
            'steps' => array_map(fn (array $step): array => ['text' => $step[0], 'at' => (float) $step[1]], $steps),
            'action' => $action,
        ];
    }
}
