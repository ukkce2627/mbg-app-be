# Aplikasi MBG — Pelaporan, Monitoring & Aduan

Studi kasus UKK Cloud Engineer SMKN 1 Banyumas TA. 2026/2027: deployment aplikasi PHP/MySQL di AWS Academy Learner Lab (us-east-1).
README ini dipakai di **kedua repo** (`mbg-app-be` dan `mbg-app-fe`) dan berisi arsitektur, konfigurasi, alur session, troubleshooting, serta **ringkasan serah-terima (bagian 9)** untuk melanjutkan pekerjaan di percakapan lain.

---

## 1. Komponen

| Sisi | Isi | Server |
|------|-----|--------|
| **Back End** (`mbg-app-be`) | REST API PHP (PDO, auto-migration, upload S3, notifikasi SNS, API key, auth berbasis session) | Apache, 1 instance `mbg-ec2-be` |
| **Front End** (`mbg-app-fe`) | Dashboard PHP multi-role (BGN, SPPG, Masyarakat), memanggil BE lewat `ApiClient`, dark/light mode, responsif | Apache, Auto Scaling `mbg-asg-fe` (min 2, max 4) |

Struktur penting:

```
mbg-app-be/
├── api/            auth, laporan, aduan, monitoring, sppg, upload, setup
├── core/           Auth.php, Response.php, Database.php, S3Uploader.php, SnsNotifier.php
├── public/         index.php (front controller), health.php
├── config.php      satu-satunya sumber konfigurasi BE
└── seed_users.php  akun awal tiap role

mbg-app-fe/
├── includes/       session_init.php, efs.php, ApiClient.php, header/footer, logout.php
├── views/          auth/, bgn/, sppg/, masyarakat/
├── health.php      health check ALB (statis, selalu 200)
├── status.php      status bar footer (instance, FE->BE, FE->EFS, health BE)
└── config.php      satu-satunya sumber konfigurasi FE
```

## 2. Arsitektur AWS (ringkas, nama WAJIB persis soal)

- **VPC** `mbg-vpc` 10.20.0.0/16; subnet public ALB `mbg-subnet-public-alb-1a/1b` (10.20.1.0/24, 10.20.2.0/24); private FE `mbg-subnet-private-fe-1a/1b` (10.20.11.0/24, 10.20.12.0/24); private BE `mbg-subnet-private-be` (10.20.10.0/24); DB `mbg-subnet-db-1a/1b` (10.20.20.0/24, 10.20.21.0/24).
- **Routing**: `mbg-igw`, `mbg-natgw-1a` (di public 1a), `mbg-rt-public` (0.0.0.0/0 → IGW, asosiasi public 1a dan 1b), `mbg-rt-private` (0.0.0.0/0 → NAT, asosiasi 2 subnet FE + subnet BE).
- **Security Group**: `mbg-sg-alb`, `mbg-sg-fe`, `mbg-sg-be`, `mbg-sg-rds`, `mbg-sg-efs` (2049 dari `mbg-sg-fe`).
- **Data/layanan**: RDS `mbg-rds-mysql` (MySQL), S3 `mbg-uploads-<account-regional-suffix>` (Block Public Access, prefix `aduan/` dan `laporan/`), SNS `mbg-sns-notifikasi` (email BGN, harus Confirmed), EFS `mbg-efs-fe-session` (mount `/mnt/efs/mbg-session`).
- **Compute**: BE `mbg-ec2-be` (private, tanpa IP publik); FE lewat Launch Template `mbg-lt-fe` + AMI `mbg-ami-fe` + ASG `mbg-asg-fe` + Target Group `mbg-tg-fe` (health `/health.php`) + ALB `mbg-alb-fe` (HTTP:80, subnet public). Scaling: target tracking CPU 60%.
- Semua EC2 berada di subnet **private**; akses shell lewat SSM Session Manager; keluar internet lewat NAT.

## 3. Konfigurasi

**`mbg-app-be/config.php`**
```php
return [
    "db"  => ["host" => "<endpoint-mbg-rds-mysql>", "port" => "3306", "user" => "admin", "pass" => "<password-rds>", "name" => "mbg_db"],
    "s3"  => ["bucket" => "<nama-bucket>", "region" => "us-east-1"],
    "sns" => ["topic_arn" => "arn:aws:sns:us-east-1:<account-id>:mbg-sns-notifikasi"],
    "api_key" => "mbg-secret-key-2024",
];
```
Tidak ada access key AWS; kredensial dari `LabInstanceProfile`. Sesuaikan `user` dengan master username RDS yang benar-benar dibuat (soal menyebut `admin`, contoh config menyebut `mbg_admin`).

**`mbg-app-fe/config.php`**
```php
return [
    "api_base_url"      => "http://<ip-privat-mbg-ec2-be>/api",
    "api_key"           => "mbg-secret-key-2024",     // harus sama dengan BE
    "session_save_path" => "/mnt/efs/mbg-session",
    // opsional:
    // "session_require_mount" => true,   // default true jika path diawali /mnt/
    // "session_lifetime"      => 28800,  // detik, default 8 jam
];
```
`api_base_url` harus sudah benar **sebelum** AMI dibuat, karena nilainya ikut tersalin ke semua instance ASG.

## 4. Alur login & session (penting dipahami)

1. Browser membuka halaman FE. `includes/session_init.php` memastikan EFS siap, lalu membuka session (read-only secara default).
2. Login: `views/auth/login.php` memanggil `POST /auth/login` di BE lewat `ApiClient`. `ApiClient` meneruskan cookie `PHPSESSID` milik FE ke BE, jadi **FE dan BE memakai session ID yang sama**.
3. BE menyimpan `$_SESSION["user"]` di disk **lokal BE** (BE hanya 1 instance, jadi aman). FE menyimpan `$_SESSION["user"]` di **EFS** (dibagi semua instance FE).
4. Setiap halaman FE memanggil `fe_require_login()` / `fe_require_role([...])`; setiap endpoint BE memanggil `Auth::requireLogin()` / `requireRole()`.
5. Upload file tidak lewat EFS: FE → BE → S3. Data aplikasi di RDS.

Aturan yang tidak boleh dilanggar:
- Halaman yang **menulis** session wajib `define("FE_SESSION_WRITE", true);` sebelum meng-include `session_init.php`, lalu memakai `fe_session_reopen()` dan `fe_session_commit()` **sebelum redirect**.
- `ApiClient` menutup session sebelum cURL (mencegah deadlock lock file); jangan dihapus.
- `status.php` sengaja **tidak** memakai `session_init.php`.
- BE jangan memakai `session_regenerate_id()` (ID harus sama dengan FE) dan `use_strict_mode` harus 0.
- Kode error BE untuk session tidak valid adalah `SESSION_EXPIRED` (bukan `UNAUTHORIZED`); FE memakainya untuk memaksa login ulang.

## 5. Deploy

### Back End (`mbg-ec2-be`)
```bash
sudo su
hostnamectl set-hostname mbg-ec2-be && exec bash
dnf update -y
dnf install php git httpd php-mysqlnd composer -y
systemctl enable --now httpd
cd /var/www/html && rm -rf * .* 2>/dev/null
git clone https://github.com/ukkce2627/mbg-app-be .
composer require aws/aws-sdk-php
nano config.php
# /etc/httpd/conf/httpd.conf -> pada <Directory "/var/www/html"> ubah AllowOverride None menjadi All
systemctl restart httpd
curl -L http://localhost/health      # harapkan status ok, db/s3/sns connected
```

### Front End (instance sementara → AMI)
```bash
sudo su
hostnamectl set-hostname mbg-ec2-fe && exec bash
dnf update -y
dnf install php git httpd amazon-efs-utils python3-botocore composer cronie -y
systemctl enable --now httpd crond
# mount EFS ke /mnt/efs/mbg-session (fstab), pastikan df -h menampilkannya
chmod -R 777 /mnt/efs/mbg-session
cd /var/www/html && git clone https://github.com/ukkce2627/mbg-app-fe .
nano config.php
systemctl restart httpd
```
Lalu buat AMI `mbg-ami-fe` → Launch Template `mbg-lt-fe` → Target Group → ALB → ASG (2/2/4) → scaling policy CPU 60%.

### User-data (otomatis)

Folder `userdata/` berisi dua script siap tempel di *Advanced details > User data*:

| File | Dipakai untuk | Yang diisi |
|------|---------------|-----------|
| `userdata-be.sh` | EC2 `mbg-ec2-be` | `RDS_ENDPOINT`, `DB_PASS`, `S3_BUCKET`, `SNS_TOPIC_ARN` (opsional `DB_USER`, `API_KEY`) |
| `userdata-fe.sh` | EC2 sementara FE (untuk AMI) **dan** Launch Template `mbg-lt-fe` | `EFS_ID`, `BE_PRIVATE_IP` (IP privat BE dari hasil `userdata-be.sh`) |

Keduanya idempotent, menulis log ke `/var/log/mbg-userdata.log`, dan berhenti dengan pesan jelas jika variabel belum diisi. `userdata-fe.sh` hanya menyalakan Apache setelah EFS benar-benar ter-mount; set Health check type = ELB pada ASG.

## 6. Troubleshooting login "nyangkut"

| Gejala | Kemungkinan penyebab | Cek |
|--------|----------------------|-----|
| Login berhasil lalu kembali ke halaman login, acak | EFS tidak ter-mount di salah satu instance | Footer menampilkan EFS `disconnected`, atau halaman membalas 503 "storage session belum siap"; di server: `mountpoint /mnt/efs/mbg-session`, `df -h`, `mount -a` |
| Sama, tapi semua instance ter-mount | Session belum tertulis saat redirect | Pastikan file hasil perbaikan terpasang (`fe_session_commit()` di `login.php`); cek `error_log` baris `[MBG-FE]` |
| Pesan "Sesi Anda berakhir" setelah login | Session BE tidak ditemukan | Di BE: izin `/var/lib/php/session`, `session.use_strict_mode`; pastikan API key sama |
| Halaman lambat/timeout saat login | Lock session di EFS ditahan request lain | Pastikan `status.php` tidak meng-include `session_init.php` |
| FE tidak bisa menulis session | Permission folder EFS | `ls -ld /mnt/efs/mbg-session`, `chmod -R 777` |

Log: `/var/log/httpd/error_log` di FE dan BE. Baris diawali `[MBG-FE]` berasal dari perbaikan session.

## 7. Uji lokal (opsional)

Taruh kedua folder di `htdocs/` (XAMPP/Laragon), aktifkan `mod_rewrite` dan `AllowOverride All`, set `session_save_path` ke `null` (session lokal), buat DB kosong `mbg_db`, jalankan `php seed_users.php` di folder BE. Akun awal (password **123**): `bgn`, `sppg1..3`, `masy1..3`. Gunakan `127.0.0.1`, bukan `localhost`, pada `api_base_url`. Perilaku multi-instance/EFS tidak bisa diuji secara lokal.

## 8. Catatan production

- `S3Uploader` dan `SnsNotifier` memakai AWS SDK for PHP; fallback lokal (`public/storage/`) hanya untuk development.
- Ganti password default dan `api_key` setelah setup awal. Jangan commit `config.php` berisi kredensial ke repo publik.
- Hanya role `masyarakat` yang bisa self-register; BGN/SPPG lewat `seed_users.php` atau insert manual.
- Login berbasis **username**, bukan email.

---

## 9. RESUME / SERAH-TERIMA (untuk melanjutkan di chat lain)

**Konteks.** Pengguna adalah siswa/peserta UKK Cloud Engineer SMKN 1 Banyumas. Sumber soal: dokumen "Soal - SPK - UKK AWS Cloud Engineer" (Word) + repo `mbg-app-be` dan `mbg-app-fe` (di-upload sebagai zip). Penilaian otomatis via AWS CLI (16 poin: VPC/subnet, NAT/route, SG, RDS, S3, SNS, EC2 BE, Launch Template, ASG, Target Group, ALB, auto-migration, notifikasi SNS, uji ketahanan ASG, EFS, session shared). Penamaan resource wajib persis soal. Bahasa percakapan: Indonesia.

**Yang sudah dilakukan di chat sebelumnya**
1. Membaca soal lengkap dan mengekstrak kedua repo.
2. Menjelaskan fungsi EFS: menyimpan file session PHP FE agar login tetap valid saat ALB memindahkan request antar instance FE. Upload tetap ke S3, data ke RDS, session BE di disk lokal BE.
3. Menganalisis masalah "user kadang nyangkut di login pertama" dan memperbaikinya di kode (paket `mbg-robust-fix.zip`).

**Penyebab yang ditemukan dari analisis kode (belum dikonfirmasi lewat log server)**
- Redirect setelah login terkirim sebelum PHP menulis session ke EFS (race).
- Mount EFS bisa gagal saat boot tanpa terdeteksi, karena folder mount point sudah ada di AMI → session diam-diam tersimpan lokal.
- `status.php` (di-poll tiap 20 detik) menahan lock session selama memanggil BE `/health`.
- Session FE dan BE bisa tidak sinkron tanpa penanganan khusus.

**File yang diubah/ditambah**
- FE: `includes/efs.php` (baru), `includes/session_init.php`, `includes/ApiClient.php`, `includes/logout.php`, `views/auth/login.php`, `status.php`.
- BE: `core/Auth.php`, `core/Response.php`, `public/index.php`.
- Baru: `userdata/userdata-be.sh` dan `userdata/userdata-fe.sh` (provisioning otomatis BE dan FE; sintaks bash sudah dicek, **belum dijalankan di AWS**).
- Ringkas: commit session sebelum redirect; deteksi mount NFS via `/proc/mounts` (503 jika belum siap); session FE read-only secara default; BE menutup session sebelum membalas; kode `SESSION_EXPIRED` → FE logout otomatis; umur session 8 jam; cookie HttpOnly + SameSite=Lax.

**Status & batasan**
- Perubahan **belum dijalankan/diuji**: lingkungan kerja tidak punya PHP (hanya pengecekan kurung dan peninjauan diff), dan belum dites di AWS.
- Belum ada konfirmasi dari pengguna bahwa masalah login sudah hilang setelah perbaikan.

**Keputusan yang sengaja diambil**
- `health.php` FE **tidak diubah** (soal mewajibkan selalu 200). Opsi: gagalkan health check jika EFS tidak ter-mount.
- Tidak memakai `session_regenerate_id()` karena BE memakai ID session yang sama dengan FE.
- Konfigurasi tidak dipindah ke `.env` (aplikasi memang memakai `config.php`).

**Hal janggal di dokumen soal (ikuti isinya, bukan nomor rujukannya)**
- C.10 menaruh FE test di subnet public dengan IP publik, sementara aturan lain melarang EC2 di subnet public → anggap hanya untuk tahap test/AMI; ASG produksi tetap di subnet private.
- Tabel `mbg-rt-public` menulis `mbg-subnet-public-alb-1a` dua kali → maksudnya 1a dan 1b.
- Nomor rujukan (C.2/C.3/C.4/C.7.5) tidak konsisten dengan isi bab.
- Master username RDS: tabel `admin`, contoh config `mbg_admin`.
- Bab "H" menyebut aturan tambahan (EFS wajib, semua EC2 di private, BE cukup 1 instance, akses via SSM).

**Langkah berikutnya yang disarankan**
1. Pasang file perbaikan: BE dulu, lalu FE; `systemctl restart httpd`; uji login berulang di 2 instance FE.
2. Jalankan `userdata-be.sh` (BE), catat IP privatnya, isi ke `userdata-fe.sh`; jalankan di EC2 sementara FE, uji login, buat `mbg-ami-fe`, lalu `mbg-lt-fe` dengan `userdata-fe.sh` sebagai user-data dan instance refresh ASG.
3. Jalankan uji akhir bab D.9: login 3 role, aduan/laporan + upload S3, email SNS diterima, terminate 1 instance FE (pengganti muncul < 5 menit), cek NAT dari instance, cek session shared lintas instance.
4. Isi Lembar Isian bab G (VPC ID, NAT ID, RDS endpoint, bucket, SNS ARN, IP privat BE, DNS ALB, TG ARN, EFS ID, email BGN).
5. Opsional: skrip AWS CLI untuk membuat seluruh infrastruktur dan skrip self-check 16 poin penilaian.

**Prompt awal untuk chat baru (salin)**
> Saya mengerjakan UKK Cloud Engineer (deploy aplikasi MBG PHP/MySQL di AWS Academy, FE Auto Scaling + EFS session, BE 1 instance, semua EC2 di subnet private). Baca README.md bagian 9 yang saya lampirkan beserta soal dan kode repo. Lanjutkan dari "Langkah berikutnya". Jawab dalam bahasa Indonesia.
