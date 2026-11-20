<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Kebijakan Privasi — OnTime</title>
    <style>
        :root {
            --bg: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #d81f1f;
            --card: #f9fafb;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111827;
                --text: #e5e7eb;
                --muted: #9ca3af;
                --border: #374151;
                --accent: #f87171;
                --card: #1f2937;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.65;
        }

        main {
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 16px 64px;
        }

        h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 4px; }
        h2 { font-size: 1.2rem; margin: 36px 0 8px; padding-top: 12px; border-top: 1px solid var(--border); }
        h3 { font-size: 1rem; margin: 20px 0 4px; }
        p, ul { margin: 8px 0; }
        ul { padding-left: 22px; }
        li { margin: 4px 0; }
        a { color: var(--accent); }
        .muted { color: var(--muted); font-size: 0.9rem; }
        .brand { color: var(--accent); font-weight: 700; letter-spacing: 0.02em; }
        .note { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin: 16px 0; }
    </style>
</head>
<body>
<main>
    <p class="brand">OnTime</p>
    <h1>Kebijakan Privasi</h1>
    <p class="muted">Terakhir diperbarui: 20 November 2026</p>

    <p>
        Kebijakan ini menjelaskan data pribadi apa yang dikumpulkan oleh OnTime, yaitu aplikasi seluler OnTime
        (Android dan iOS) dan panel web di <a href="https://ontime.oceanspace.co.id">ontime.oceanspace.co.id</a>,
        untuk apa data itu dipakai, dengan siapa dibagikan, dan apa hak Anda atas data tersebut.
    </p>

    <h2>1. Siapa kami dan peran masing-masing pihak</h2>
    <p>
        OnTime adalah aplikasi presensi dan administrasi kepegawaian (jadwal kerja, cuti, lembur, koreksi absen,
        tukar shift, dan pengunduran diri) yang dipakai oleh perusahaan atau klien sebagai pemberi kerja.
    </p>
    <ul>
        <li>
            <strong>Pemberi kerja (perusahaan/klien Anda)</strong> menentukan siapa yang didaftarkan, aturan
            presensinya, dan siapa yang boleh melihat data. Untuk data kepegawaian Anda, pemberi kerja bertindak
            sebagai <em>pengendali data pribadi</em>.
        </li>
        <li>
            <strong>Pengelola OnTime (Ocean Space)</strong> menyediakan dan menjalankan layanan atas nama pemberi kerja
            dan bertindak sebagai <em>prosesor data pribadi</em>.
        </li>
    </ul>
    <p>
        Akun OnTime dibuat oleh pemberi kerja Anda; aplikasi ini tidak menyediakan pendaftaran mandiri untuk umum.
    </p>

    <h2>2. Data yang kami kumpulkan</h2>

    <h3>Data akun dan kepegawaian</h3>
    <ul>
        <li>Nama lengkap, nomor HP, alamat email, dan nomor induk pegawai (NIP).</li>
        <li>Perusahaan/badan usaha, divisi, jabatan, level, status kepegawaian, dan tanggal bergabung.</li>
        <li>Jenis kelamin, tanggal lahir, status pernikahan, dan status pajak bila diisi oleh pemberi kerja.</li>
        <li>Peran dan hak akses di aplikasi (misalnya karyawan, Koordinator, Admin, PIC, atau penyetuju).</li>
    </ul>

    <h3>Foto dan data wajah</h3>
    <ul>
        <li><strong>Foto master wajah</strong> (foto profil acuan) yang Anda ambil dengan kamera atau pilih dari galeri.</li>
        <li>
            <strong>Foto selfie presensi</strong> saat absen masuk dan pulang, termasuk beberapa foto singkat untuk
            pemeriksaan keaslian wajah (menghadap depan, menoleh ke kiri dan ke kanan).
        </li>
        <li>Selfie dibandingkan dengan foto master wajah untuk memastikan yang absen adalah orang yang benar.</li>
    </ul>

    <h3>Lokasi</h3>
    <ul>
        <li>
            Koordinat GPS diambil <strong>hanya pada saat Anda menekan absen masuk atau absen pulang</strong>, untuk
            memastikan presensi dilakukan di area kerja yang ditentukan pemberi kerja. Aplikasi juga mencatat bila
            lokasi berada di luar area atau terdeteksi sebagai lokasi palsu (<em>mock location</em>).
        </li>
        <li>
            Admin/PIC dapat memakai lokasi perangkatnya saat menetapkan titik lokasi kantor di aplikasi.
        </li>
        <li>OnTime <strong>tidak</strong> melacak lokasi Anda di latar belakang atau di luar momen absen.</li>
    </ul>

    <h3>Data presensi dan pengajuan</h3>
    <ul>
        <li>Waktu absen masuk/pulang, shift dan jadwal kerja, status (tepat waktu, terlambat, pulang cepat), dan catatan.</li>
        <li>
            Pengajuan cuti/izin/sakit, lembur, koreksi absen, tukar shift, dan pengunduran diri beserta alasan,
            tanggal, status persetujuan, dan <strong>lampiran</strong> yang Anda unggah (foto atau dokumen, misalnya surat dokter).
        </li>
    </ul>

    <h3>Data perangkat dan teknis</h3>
    <ul>
        <li>Token notifikasi push dan jenis platform perangkat (Android/iOS), untuk mengirim notifikasi.</li>
        <li>Token sesi login yang tersimpan aman di perangkat Anda.</li>
        <li>
            Laporan crash dan kinerja aplikasi (misalnya model perangkat, versi sistem operasi, versi aplikasi,
            pesan galat, dan ID pengguna OnTime Anda) untuk memperbaiki masalah. Laporan ini tidak memuat foto atau
            lokasi Anda.
        </li>
    </ul>
    <p>OnTime tidak merekam suara atau video dan tidak meminta izin mikrofon.</p>

    <h2>3. Untuk apa data dipakai</h2>
    <ul>
        <li>Masuk ke akun dengan email dan kata sandi, kode OTP yang dikirim lewat WhatsApp atau email, atau tautan masuk melalui email.</li>
        <li>Mencatat dan memverifikasi kehadiran (wajah dan lokasi) sesuai aturan presensi pemberi kerja.</li>
        <li>Menyusun jadwal kerja, rekap kehadiran, perhitungan keterlambatan, lembur, dan sisa cuti.</li>
        <li>Memproses pengajuan dan alur persetujuan oleh atasan atau penyetuju yang ditunjuk.</li>
        <li>Mengirim notifikasi dan pengingat (misalnya pengingat absen dan status persetujuan) lewat push, WhatsApp, atau email.</li>
        <li>Menjaga keamanan akun, mencegah kecurangan presensi, serta memperbaiki galat dan meningkatkan aplikasi.</li>
    </ul>
    <p>Kami tidak menjual data pribadi Anda dan tidak memakainya untuk iklan.</p>

    <h2>4. Dasar pemrosesan</h2>
    <ul>
        <li>
            <strong>Pelaksanaan perjanjian kerja</strong> dan <strong>kepentingan sah pemberi kerja</strong> untuk
            mengelola kehadiran dan administrasi kepegawaian.
        </li>
        <li>
            <strong>Persetujuan Anda</strong> untuk akses kamera, galeri, lokasi, dan notifikasi. Izin ini diminta
            oleh sistem operasi dan dapat Anda cabut kapan saja lewat pengaturan perangkat, namun fitur presensi
            yang memerlukannya tidak akan bisa dipakai.
        </li>
        <li>Kewajiban hukum pemberi kerja, misalnya di bidang ketenagakerjaan dan perpajakan.</li>
    </ul>

    <h2>5. Siapa yang dapat melihat data Anda</h2>
    <ul>
        <li>
            <strong>Pihak di pemberi kerja Anda</strong> sesuai perannya: PIC, Admin, Koordinator, atasan, dan penyetuju
            hanya dapat melihat data karyawan dalam cakupan akses yang diberikan kepada mereka (misalnya perusahaan,
            divisi, atau tim tertentu).
        </li>
        <li><strong>Pengelola OnTime</strong> untuk menjalankan, mendukung, dan mengamankan layanan.</li>
        <li>
            <strong>Penyedia layanan (prosesor) yang membantu menjalankan OnTime</strong>, hanya sebatas yang diperlukan:
            <ul>
                <li>Penyedia server dan penyimpanan cloud tempat aplikasi, basis data, dan foto disimpan.</li>
                <li>Layanan pencocokan wajah yang dijalankan di server OnTime (bila diaktifkan) untuk membandingkan selfie dengan foto master wajah.</li>
                <li>OneSignal, untuk mengirim notifikasi push.</li>
                <li>Sentry, untuk laporan crash dan kinerja aplikasi.</li>
                <li>Penyedia gateway WhatsApp, untuk mengirim kode OTP dan notifikasi lewat WhatsApp.</li>
                <li>Penyedia layanan email, untuk mengirim kode OTP, tautan masuk, dan notifikasi lewat email.</li>
            </ul>
        </li>
        <li>Otoritas yang berwenang bila diwajibkan oleh hukum.</li>
    </ul>
    <p>
        Sebagian penyedia di atas dapat memproses data di luar Indonesia. Kami hanya memakai penyedia yang menerapkan
        perlindungan data yang memadai.
    </p>

    <h2>6. Berapa lama data disimpan</h2>
    <p>
        Data disimpan selama Anda masih terdaftar sebagai pekerja pada pemberi kerja yang memakai OnTime, dan setelah
        itu selama diperlukan pemberi kerja untuk keperluan administrasi kepegawaian, audit, atau kewajiban hukum.
        Saat Anda berhenti bekerja atau mengundurkan diri, akun Anda dinonaktifkan: sesi login di aplikasi diakhiri dan
        perangkat Anda tidak lagi menerima notifikasi. Riwayat presensi dan pengajuan tetap tersimpan sebagai catatan
        pemberi kerja sampai dihapus sesuai permintaan atau kebijakan retensi mereka.
    </p>

    <h2>7. Keamanan data</h2>
    <ul>
        <li>Komunikasi antara aplikasi dan server dienkripsi dengan HTTPS.</li>
        <li>Token login disimpan di penyimpanan aman perangkat; kode OTP berlaku singkat.</li>
        <li>Foto presensi, foto wajah, dan lampiran ditampilkan hanya kepada pengguna yang berhak, melalui tautan bertanda tangan yang masa berlakunya terbatas.</li>
        <li>Akses data dibatasi berdasarkan peran dan cakupan pengguna.</li>
    </ul>
    <p>Tidak ada sistem yang sepenuhnya bebas risiko, tetapi kami berupaya melindungi data Anda dengan langkah yang wajar.</p>

    <h2>8. Hak Anda</h2>
    <p>
        Sesuai Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi, Anda berhak untuk:
    </p>
    <ul>
        <li>mendapatkan informasi tentang pemrosesan data Anda dan mengakses salinannya;</li>
        <li>memperbaiki data yang tidak akurat atau tidak lengkap;</li>
        <li>meminta penghapusan atau pemusnahan data, dan meminta pembatasan pemrosesan;</li>
        <li>menarik kembali persetujuan yang telah Anda berikan;</li>
        <li>mengajukan keberatan atas pemrosesan tertentu.</li>
    </ul>
    <p>
        Karena data Anda dikelola untuk pemberi kerja, ajukan permintaan terlebih dahulu ke bagian HR atau Admin di
        perusahaan Anda. Anda juga dapat menghubungi kami lewat kontak di bawah, dan kami akan membantu meneruskan serta
        menindaklanjutinya bersama pemberi kerja Anda.
    </p>

    <h2>9. Penghapusan akun</h2>
    <p>
        Untuk meminta akun dan data Anda dihapus, hubungi HR/Admin perusahaan Anda atau kirim email ke
        <a href="mailto:developer@rizqis.com">developer@rizqis.com</a> dari alamat email yang terdaftar, dengan
        menyebutkan nama, nomor HP, dan nama perusahaan Anda. Setelah identitas dan permintaan dikonfirmasi bersama
        pemberi kerja, akun dinonaktifkan dan data pribadi dihapus, kecuali data yang wajib disimpan pemberi kerja
        menurut hukum.
    </p>

    <h2>10. Anak-anak</h2>
    <p>
        OnTime ditujukan untuk pekerja, peserta magang, dan mitra kerja berusia 18 tahun ke atas, dan tidak
        ditujukan untuk anak-anak.
    </p>

    <h2>11. Perubahan kebijakan</h2>
    <p>
        Kebijakan ini dapat diperbarui sewaktu-waktu. Versi terbaru selalu tersedia di halaman ini dengan tanggal
        pembaruan di bagian atas. Bila ada perubahan penting, kami akan memberi tahu melalui aplikasi atau pemberi kerja Anda.
    </p>

    <h2>12. Hubungi kami</h2>
    <div class="note">
        <p>
            Pertanyaan, permintaan, atau keluhan tentang privasi dapat dikirim ke
            <a href="mailto:developer@rizqis.com">developer@rizqis.com</a>.
        </p>
    </div>
</main>
</body>
</html>
