# 📚 SISTEM INFORMASI LPPM IAKN TORAJA
## Panduan Instalasi Lengkap

---

## 🗂️ STRUKTUR FOLDER

```
lppm/
├── admin/
│   ├── dashboard.php        ← Dashboard admin LPPM
│   ├── plagiasi.php         ← Kelola cek plagiasi
│   ├── publikasi.php        ← Kelola verifikasi publikasi
│   ├── mahasiswa.php        ← Data mahasiswa
│   ├── surat.php            ← Arsip surat
│   └── pengaturan.php       ← Pengaturan sistem
├── assets/
│   └── css/style.css        ← Stylesheet utama
├── includes/
│   ├── config.php           ← KONFIGURASI UTAMA (edit ini dulu!)
│   ├── sidebar.php          ← Navigasi sidebar
│   └── email.php            ← Helper pengiriman email
├── modules/
│   ├── plagiasi/
│   │   ├── upload.php       ← Halaman upload skripsi (mahasiswa)
│   │   └── unduh_surat.php  ← Download surat bebas plagiasi
│   └── publikasi/
│       ├── upload.php       ← Halaman upload publikasi (mahasiswa)
│       └── unduh_surat.php  ← Download surat publikasi
├── uploads/                 ← File yang diupload (auto-created)
│   ├── skripsi/
│   └── publikasi/
├── composer.json
├── database.sql             ← Import ini ke MySQL
├── login.php
├── register.php
├── dashboard.php            ← Dashboard mahasiswa
└── logout.php
```

---

## 🚀 LANGKAH INSTALASI DI HOSTINGER

### Step 1: Beli Hosting
1. Buka **hostinger.co.id**
2. Pilih paket **Premium Shared Hosting** (~Rp 30-40 ribu/bulan)
3. Daftarkan domain (misal: `lppm.iakntoraja.ac.id`)

### Step 2: Upload File
1. Login ke **hPanel** (panel Hostinger)
2. Buka **File Manager** → folder `public_html`
3. Upload semua file sistem ini ke dalam `public_html`
4. Atau gunakan **FTP** dengan aplikasi FileZilla

### Step 3: Buat Database MySQL
1. Di hPanel, buka **Database** → **MySQL Databases**
2. Klik **Create new database**
3. Nama database: `lppm_iakntoraja` (atau sesuai keinginan)
4. Buat user database dan catat **username** dan **password**-nya

### Step 4: Import Database
1. Di hPanel, buka **phpMyAdmin**
2. Pilih database yang baru dibuat
3. Klik tab **Import**
4. Upload file `database.sql`
5. Klik **Go/Execute**

### Step 5: Edit Konfigurasi
Buka file `includes/config.php` dan ubah:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'lppm_iakntoraja');   // nama database Anda
define('DB_USER', 'user_anda');          // username database
define('DB_PASS', 'password_anda');      // password database
define('BASE_URL', 'https://lppm.iakntoraja.ac.id'); // domain Anda
```

### Step 6: Setup Gmail untuk Notifikasi Email
1. Login ke Gmail LPPM
2. Buka **Google Account Settings** → **Security**
3. Aktifkan **2-Step Verification**
4. Buka **App passwords** → Generate password untuk "Mail"
5. Copy password 16 karakter tersebut ke config:
```php
define('MAIL_USER', 'lppm@iakntoraja.ac.id');
define('MAIL_PASS', 'xxxx xxxx xxxx xxxx'); // App password dari Google
```

### Step 7: Install Library PHP (via Composer)
Di terminal/SSH hosting (atau lewat Hostinger Terminal):
```bash
composer install
```
Ini akan menginstall:
- **PHPMailer** (untuk kirim email)
- **FPDF** (untuk generate PDF surat)

**Jika tidak bisa pakai Composer:**
- Download PHPMailer: https://github.com/PHPMailer/PHPMailer/releases
- Download FPDF: http://fpdf.org/en/dl.php?v=18&f=zip
- Ekstrak ke folder `vendor/`

### Step 8: Set Permission Folder Upload
Di File Manager atau SSH:
```bash
chmod 755 uploads/
chmod 755 uploads/skripsi/
chmod 755 uploads/publikasi/
```

---

## 🔑 LOGIN PERTAMA

| Role | Email | Password |
|------|-------|----------|
| Admin LPPM | lppm@iakntoraja.ac.id | password |

**⚠️ PENTING: Ganti password admin segera setelah login pertama!**
Masuk ke **Pengaturan** → **Ganti Password**

---

## ⚙️ PENGATURAN AWAL

Setelah login sebagai admin, buka halaman **Pengaturan** dan update:
- Nama institusi
- Nama LPPM
- Alamat kampus
- Nama & NIP Ketua LPPM (untuk tanda tangan surat)
- Email LPPM

---

## 📧 CARA KERJA NOTIFIKASI EMAIL

```
Mahasiswa upload → Email otomatis ke admin LPPM
                   ↓
Admin proses → Email otomatis ke mahasiswa
               (terima/tolak + link download surat)
```

---

## 🔧 TROUBLESHOOTING

**Database error:**
- Periksa username, password, dan nama database di `config.php`

**Email tidak terkirim:**
- Pastikan App Password Gmail sudah benar (bukan password biasa)
- Aktifkan 2FA di akun Gmail LPPM terlebih dahulu
- Cek folder SPAM di email admin

**File tidak bisa diupload:**
- Periksa permission folder `uploads/` (harus 755)
- Periksa ukuran file (maksimal 20 MB)
- Periksa `php.ini`: `upload_max_filesize` dan `post_max_size`

**PDF tidak terbuka:**
- Pastikan library FPDF sudah terinstall (`vendor/setasign/fpdf`)
- Cek log PHP di hPanel → Logs

---

## 📞 BANTUAN

Jika mengalami kendala teknis, dapat menghubungi developer atau
menggunakan fitur bantuan di Claude.ai untuk debugging.

---

## 📋 FITUR LENGKAP

### Mahasiswa:
- ✅ Register & login
- ✅ Upload skripsi (PDF) untuk cek plagiasi
- ✅ Upload bukti publikasi (jurnal/book chapter/buku/prosiding)
- ✅ Lihat status permohonan real-time
- ✅ Download surat keterangan (PDF) otomatis
- ✅ Notifikasi email status permohonan
- ✅ Riwayat semua pengajuan
- ✅ Bilingual (Indonesia & Inggris)

### Admin LPPM:
- ✅ Dashboard dengan statistik lengkap
- ✅ Antrian permohonan plagiasi & publikasi
- ✅ Input skor Turnitin + preview live
- ✅ Auto-generate surat dengan nomor surat otomatis
- ✅ Kirim email notifikasi ke mahasiswa (satu klik)
- ✅ Arsip semua surat yang pernah diterbitkan
- ✅ Kelola data mahasiswa
- ✅ Pengaturan sistem (nama institusi, penandatangan, dll)
- ✅ Bilingual (Indonesia & Inggris)

---

*Dibuat dengan bantuan Claude AI — Anthropic*
*Untuk LPPM IAKN Toraja, Sulawesi Selatan*
