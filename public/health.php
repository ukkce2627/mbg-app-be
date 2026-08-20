<?php
/**
 * Health check untuk Load Balancer.
 * WAJIB selalu mengembalikan HTTP 200 selama aplikasi & koneksi DB sehat.
 *
 * Selain DB, endpoint ini juga mengecek konektivitas ke S3 dan SNS.
 * Catatan: kegagalan cek S3/SNS TIDAK mengubah status HTTP (tetap 200
 * selama DB sehat), karena keduanya punya mekanisme fallback sendiri
 * (lihat S3Uploader & SnsNotifier) dan bukan syarat mutlak app "up".
 * Statusnya tetap dilaporkan di body response agar mudah dipantau.
 */
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

$full   = require __DIR__ . '/../config.php';
$dbOk   = false;
$s3     = checkS3($full['s3'] ?? []);
$sns    = checkSns($full['sns'] ?? [], $full['s3']['region'] ?? '');

try {
    Database::get()->query('SELECT 1');
    $dbOk = true;
} catch (\Throwable $e) {
    $dbOk = false;
}

http_response_code($dbOk ? 200 : 500);

echo json_encode([
    'status' => $dbOk ? 'ok' : 'error',
    'db'     => $dbOk ? 'connected' : 'disconnected',
    's3'     => $s3,
    'sns'    => $sns,
]);

/**
 * Cek konektivitas ke S3.
 * - Kalau bucket belum dikonfigurasi -> mode "fallback lokal" (bukan error).
 * - Kalau SDK AWS tidak terpasang -> dianggap not_configured juga.
 * - Kalau bucket ada, coba headBucket() untuk memastikan bucket bisa diakses.
 */
function checkS3(array $cfg): array
{
    if (empty($cfg['bucket'])) {
        return ['status' => 'not_configured', 'detail' => 'bucket kosong, memakai fallback lokal'];
    }

    if (!class_exists('\Aws\S3\S3Client')) {
        return ['status' => 'not_configured', 'detail' => 'AWS SDK tidak terpasang'];
    }

    try {
        $client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $cfg['region'] ?? null,
        ]);
        $client->headBucket(['Bucket' => $cfg['bucket']]);
        return ['status' => 'connected'];
    } catch (\Throwable $e) {
        return ['status' => 'disconnected', 'detail' => $e->getMessage()];
    }
}

/**
 * Cek konektivitas ke SNS.
 * - Kalau topic_arn belum dikonfigurasi -> mode "fallback log" (bukan error).
 * - Kalau SDK AWS tidak terpasang -> dianggap not_configured juga.
 * - Kalau topic_arn ada, coba getTopicAttributes() untuk memastikan topic bisa diakses.
 */
function checkSns(array $cfg, string $region): array
{
    $topicArn = $cfg['topic_arn'] ?? null;

    if (empty($topicArn)) {
        return ['status' => 'not_configured', 'detail' => 'topic_arn kosong, memakai fallback log'];
    }

    if (!class_exists('\Aws\Sns\SnsClient')) {
        return ['status' => 'not_configured', 'detail' => 'AWS SDK tidak terpasang'];
    }

    try {
        $client = new \Aws\Sns\SnsClient([
            'version' => 'latest',
            'region'  => $region ?: null,
        ]);
        $client->getTopicAttributes(['TopicArn' => $topicArn]);
        return ['status' => 'connected'];
    } catch (\Throwable $e) {
        return ['status' => 'disconnected', 'detail' => $e->getMessage()];
    }
}