<?php
/**
 * Wrapper upload file ke Amazon S3.
 *
 * Butuh AWS SDK for PHP terpasang lewat Composer:
 *   composer require aws/aws-sdk-php
 * Kredensial AWS TIDAK perlu diisi manual — SDK otomatis memakai mekanisme
 * autentikasi yang sudah disiapkan tim infrastruktur di server (instance
 * profile / environment). Programmer cukup pakai region & bucket dari config.php.
 *
 * Jika SDK belum terpasang (mis. saat development lokal), class ini otomatis
 * fallback menyimpan file ke folder lokal storage/ agar kode tetap bisa
 * dites end-to-end tanpa akun AWS. Fallback ini TIDAK untuk dipakai di
 * production (lihat Bab 2: FE/BE tidak boleh mengandalkan disk lokal).
 */

require_once __DIR__ . '/Response.php';

class S3Uploader
{
    private array $cfg;
    private ?\Aws\S3\S3Client $client = null;

    public function __construct()
    {
        $full = require __DIR__ . '/../config.php';
        $this->cfg = $full['s3'];

        if (class_exists('\Aws\S3\S3Client') && !empty($this->cfg['bucket'])) {
            $this->client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => $this->cfg['region'],
            ]);
        }
    }

    /**
     * @param array  $file     Elemen dari $_FILES['field']
     * @param string $prefix   Prefix folder di bucket, mis. "aduan/" atau "laporan/"
     * @return string          Nilai yang disimpan ke kolom file_url di DB:
     *                         - kalau S3 aktif: S3 OBJECT KEY (bukan URL publik),
     *                           karena bucket private -> akses lewat presigned URL
     *                           yang di-generate on-demand lewat getUrl().
     *                         - kalau fallback lokal (dev): tetap URL lokal seperti
     *                           sebelumnya, karena fallback ini memang tidak lewat S3.
     */
    public function upload(array $file, string $prefix): string
    {
        $this->validate($file);

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $objectKey = rtrim($prefix, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        if ($this->client) {
            $this->client->putObject([
                'Bucket'     => $this->cfg['bucket'],
                'Key'        => $objectKey,
                'SourceFile' => $file['tmp_name'],
                // CATATAN: parameter 'ACL' sengaja dihapus. Bucket S3 modern
                // (default sejak 2023, Object Ownership = "Bucket owner
                // enforced") menolak SEMUA request putObject yang menyertakan
                // ACL dengan error "AccessControlListNotSupported", karena
                // ACL memang di-disable total di level bucket. Bucket TETAP
                // private (memang tujuannya begitu, lihat getUrl() di bawah).
                'ContentType'=> $file['type'],
            ]);
            return $objectKey;
        }

        // Fallback lokal (development only) — disimpan DI DALAM public/ supaya
        // bisa diakses langsung lewat browser, dan URL-nya dihitung dinamis
        // dari SCRIPT_NAME (bukan hardcode "/storage/..."), jadi tetap benar
        // walau backend ditaruh di subfolder (mis. /mbg-app-backend/public).
        $dir = __DIR__ . '/../public/storage/' . $prefix;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest = $dir . basename($objectKey);
        move_uploaded_file($file['tmp_name'], $dest);

        $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $baseDir . '/storage/' . $prefix . basename($objectKey);
    }

    /**
     * Ubah nilai yang tersimpan di kolom file_url (S3 object key ATAU URL
     * lokal dari fallback dev) menjadi URL yang benar-benar bisa diakses FE
     * saat itu juga.
     * - S3 object key -> presigned URL sementara (default berlaku 1 jam).
     *   Digenerate baru setiap kali dipanggil, jadi selalu valid selama
     *   objectnya masih ada di bucket, tanpa perlu bucket jadi publik.
     * - URL lokal (fallback dev, selalu diawali '/' atau 'http') ->
     *   dikembalikan apa adanya, karena bukan lewat S3.
     *
     * @param  string|null $stored             Nilai kolom file_url dari DB
     * @param  int         $expiresInSeconds   Masa berlaku presigned URL
     * @return string|null                     URL siap pakai, atau null kalau
     *                                          $stored kosong / tidak bisa di-resolve
     */
    public function getUrl(?string $stored, int $expiresInSeconds = 3600): ?string
    {
        if (!$stored) return null;

        // Hasil fallback lokal selalu diawali '/' (path relatif) atau 'http'
        // (kalau suatu saat disimpan sebagai URL absolut) — bukan S3 key.
        if (str_starts_with($stored, '/') || str_starts_with($stored, 'http')) {
            return $stored;
        }

        if (!$this->client) {
            // Datanya berupa S3 key tapi SDK/bucket sedang tidak tersedia
            // (mis. config kosong) -> tidak bisa di-resolve jadi URL asli.
            return null;
        }

        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->cfg['bucket'],
            'Key'    => $stored,
        ]);
        $request = $this->client->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");

        return (string) $request->getUri();
    }

    private function validate(array $file): void
    {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $maxSize = 5 * 1024 * 1024; // 5 MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Upload file gagal', 'UPLOAD_ERROR', 400);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            Response::error('Tipe file tidak diizinkan (hanya jpg/png/pdf)', 'VALIDATION_ERROR', 400);
        }
        if ($file['size'] > $maxSize) {
            Response::error('Ukuran file maksimal 5MB', 'VALIDATION_ERROR', 400);
        }
    }
}