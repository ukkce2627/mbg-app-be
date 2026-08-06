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

    public function __construct()
    {
        $full = require __DIR__ . '/../config.php';
        $this->cfg = $full['s3'];
    }

    /**
     * @param array  $file     Elemen dari $_FILES['field']
     * @param string $prefix   Prefix folder di bucket, mis. "aduan/" atau "laporan/"
     * @return string          URL file yang bisa dipakai FE
     */
    public function upload(array $file, string $prefix): string
    {
        $this->validate($file);

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $objectKey = rtrim($prefix, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        if (class_exists('\Aws\S3\S3Client') && !empty($this->cfg['bucket'])) {
            $client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => $this->cfg['region'],
            ]);
            $result = $client->putObject([
                'Bucket'     => $this->cfg['bucket'],
                'Key'        => $objectKey,
                'SourceFile' => $file['tmp_name'],
                'ACL'        => 'private',
                'ContentType'=> $file['type'],
            ]);
            return (string) $result['ObjectURL'];
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
