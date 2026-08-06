# Aplikasi MBG — Pelaporan, Monitoring & Aduan

Implementasi sesuai *Dokumen Teknis Pengembangan Aplikasi* yang diberikan:
- **Back End**: REST API PHP (PDO, auto-migration, S3 upload, notifikasi SNS, API key, session-based auth untuk role check). Berjalan di bawah **Apache2**.
- **Front End**: Dashboard PHP multi-role (BGN, SPPG, Masyarakat), memanggil BE lewat `ApiClient`, dengan **dark/light mode** dan **desain responsif**. Berjalan di bawah **Apache2**.

Kedua sisi ditulis **tanpa path absolut yang hardcode** — seluruh link, redirect, dan referensi asset dihitung otomatis relatif terhadap folder tempat aplikasi ditaruh (lihat bagian *"Kenapa bisa pindah folder/domain dengan bebas"* di bawah), jadi bisa dipindah dari subfolder localhost ke domain/server manapun tanpa edit kode.

## Struktur

```
mbg-app/
├── mbg-app-backend/
│   ├── api/                          # auth, laporan, aduan, monitoring, sppg, upload
│   ├── core/                         # Database, Auth, Response, S3Uploader, SnsNotifier
│   ├── public/                       # index.php (front controller), health.php, storage/ (fallback upload dev)
│   ├── config.php                    # SATU-SATUNYA sumber konfigurasi BE (tidak pakai .env)
│   ├── .htaccess                     # forward semua request ke public/ (dipakai kalau tanpa vhost)
│   ├── apache-mbg-backend.conf.example
│   └── seed_users.php                # generate akun awal tiap role
└── mbg-app-frontend/
    ├── includes/                     # session_init.php (base_url() helper), ApiClient.php, header/footer, logout
    ├── views/                        # auth/, bgn/, sppg/, masyarakat/
    ├── assets/                       # css/app.css (tema), js/app.js (toggle tema & nav)
    ├── health.php
    ├── index.php
    ├── config.php                    # SATU-SATUNYA sumber konfigurasi FE (tidak pakai .env)
    └── apache-mbg-frontend.conf.example
```

## Kenapa bisa pindah folder/domain dengan bebas

Sebelumnya semua link di FE (`href="/views/..."`, `src="/assets/..."`, `header('Location: /views/...')`) hardcode diawali `/`, artinya HANYA benar kalau FE ditaruh persis di root domain. Begitu ditaruh di subfolder (mis. `http://localhost/mbg-app-frontend/`), semua link itu salah arah ke root (`http://localhost/views/...` — 404).

Sekarang `includes/session_init.php` menghitung `BASE_PATH` **secara otomatis** (membandingkan lokasi file di disk dengan URL script yang sedang jalan) dan menyediakan fungsi `base_url('/path')`. Semua href/src/redirect di FE memakainya, jadi otomatis benar baik di:
- root domain (`http://10.11.12.13/`) → `base_url('/views/x.php')` = `/views/x.php`
- subfolder (`http://localhost/mbg-app-frontend/`) → `base_url('/views/x.php')` = `/mbg-app-frontend/views/x.php`

Backend (`public/index.php`) juga sama: routing `/api/...`-nya dihitung dengan membuang prefix folder tempat backend berada (dari `SCRIPT_NAME`), bukan diasumsikan selalu di root.

Satu-satunya nilai yang **tetap harus berupa URL absolut** (dengan domain) adalah `api_base_url` di `frontend/config.php` — ini wajar karena itu adalah alamat server *lain* (Back End) yang dipanggil dari PHP sisi server, bukan link di HTML, jadi secara teknis tidak bisa "relatif".

## Konfigurasi

Tidak ada file `.env` sama sekali — semua nilai konfigurasi diisi langsung di `config.php` masing-masing sisi.

**`mbg-app-backend/config.php`**
```php
return [
    'db'  => ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'pass' => '', 'name' => 'mbg_db'],
    's3'  => ['bucket' => '', 'region' => ''],   // kosongkan untuk fallback upload lokal (dev)
    'sns' => ['topic_arn' => ''],                 // kosongkan untuk fallback log (dev)
    'api_key' => 'mbg-secret-key-2024',
];
```

**`mbg-app-frontend/config.php`**
```php
return [
    'api_base_url'      => 'http://localhost/mbg-app-backend/api',
    'api_key'           => 'mbg-secret-key-2024', // harus SAMA dengan api_key di backend
    'session_save_path' => null,
];
```

## Menjalankan di localhost (tanpa vhost, subfolder di htdocs)

Skenario paling gampang untuk uji coba lokal — cukup taruh kedua folder di document root Apache (XAMPP/Laragon: `htdocs/`), tidak perlu setting vhost:

```
htdocs/
├── mbg-app-frontend/   → diakses via http://localhost/mbg-app-frontend/
└── mbg-app-backend/    → diakses via http://localhost/mbg-app-backend/  (otomatis diteruskan ke public/ lewat .htaccess)
```

**1. Aktifkan `mod_rewrite`** di Apache (WAJIB, dipakai `.htaccess` kedua sisi) dan pastikan `AllowOverride All` untuk folder htdocs (default XAMPP/Laragon biasanya sudah begini; kalau tidak, edit `httpd.conf`).

**2. Siapkan database MySQL** di localhost — sesuai yang kamu mau, user `root` tanpa password, database dibuat otomatis oleh auto-migration saat backend pertama kali diakses (tidak perlu bikin database manual sekalipun, MySQL akan otomatis pakai skema `mbg_db` — kalau server MySQL kamu tidak mengizinkan `CREATE DATABASE` implisit dari koneksi, buat dulu database kosong bernama `mbg_db`).

**3. Buat akun awal (tanpa email, berbasis username):**
```bash
cd htdocs/mbg-app-backend
php seed_users.php
```
Membuat akun berikut, password sama semua: **123**

| Username | Role       |
|----------|------------|
| bgn      | bgn        |
| sppg1    | sppg       |
| sppg2    | sppg       |
| sppg3    | sppg       |
| masy1     | masyarakat |
| masy2     | masyarakat |
| masy3     | masyarakat |

**4. Buka** `http://localhost/mbg-app-frontend/` di browser dan login.

⚠️ **Catatan penting untuk uji coba di 1 mesin**: karena FE memanggil BE dan meneruskan cookie session PHP secara manual (lihat `ApiClient::request()`), dan keduanya berjalan di PHP/Apache yang sama secara lokal, ini biasanya bekerja langsung tanpa setting tambahan (session tersimpan di folder default PHP yang sama). Kalau nanti FE dan BE dipisah ke **server fisik berbeda** (skenario `10.11.12.13` vs `10.11.12.15` di pesanmu), backend WAJIB juga diarahkan ke session storage yang bisa diakses bersama (mis. Redis/Memcached atau NFS shared folder) — ini di luar cakupan revisi kali ini, tanya lagi kalau sudah sampai tahap itu.

## Menjalankan di production (vhost terpisah)

Kalau nanti tiap sisi punya domain/subdomain sendiri (bukan subfolder), pakai vhost seperti biasa:

```bash
sudo a2enmod rewrite
sudo cp mbg-app-backend/apache-mbg-backend.conf.example /etc/apache2/sites-available/mbg-backend.conf
sudo cp mbg-app-frontend/apache-mbg-frontend.conf.example /etc/apache2/sites-available/mbg-frontend.conf
# edit ServerName & path DocumentRoot di kedua file
sudo a2ensite mbg-backend mbg-frontend
sudo systemctl reload apache2
```
`backend`'s `DocumentRoot` mengarah ke `mbg-app-backend/public`; `frontend`'s `DocumentRoot` mengarah ke `mbg-app-frontend/`. Update `frontend/config.php['api_base_url']` ke domain backend yang baru — tidak ada file lain yang perlu diubah karena semua link internal sudah relatif otomatis.

## Fitur UI (Front End)

- **Dark / Light mode**: tombol 🌙/☀️ di kanan atas, tersimpan di `localStorage`.
- **Responsif**: desktop → tablet → mobile, menu jadi hamburger di layar sempit.
- **Role-based views**: BGN (rekap semua SPPG, kelola master SPPG, update status), SPPG (buat laporan, tanggapi aduan), Masyarakat (buat aduan + upload foto).

## Catatan Production

- `S3Uploader` & `SnsNotifier` memakai AWS SDK for PHP (`composer require aws/aws-sdk-php`); kredensial otomatis lewat IAM role server. Fallback lokal (`public/storage/`) hanya untuk development — **jangan dipakai di production**.
- `frontend/includes/session_init.php` WAJIB di-include di setiap halaman sebelum output apa pun.
- Endpoint `/health.php` di kedua sisi selalu HTTP 200 selama sehat (dipakai Load Balancer).
- Login & registrasi berbasis **username** (bukan email); akun BGN/SPPG dibuat lewat `seed_users.php` atau insert manual — hanya `masyarakat` yang bisa self-register.
- Ganti password default (`123`) dan `api_key` setelah setup awal, jangan dipakai apa adanya di production.
- Jangan commit `config.php` versi production (berisi kredensial) ke repo publik — tambahkan ke `.gitignore`.
