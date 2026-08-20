<?php
/**
 * Health check untuk Load Balancer.
 * WAJIB selalu mengembalikan HTTP 200 selama aplikasi & koneksi DB sehat.
 *
 * Selain DB, endpoint ini juga mengecek konektivitas ke S3 dan SNS lewat
 * AWS CLI (bukan AWS SDK for PHP, supaya tidak perlu composer install).
 * AWS CLI otomatis memakai IAM instance profile yang sama seperti yang
 * dipakai mekanisme lain di server ini.
 *
 * Catatan: kegagalan cek S3/SNS TIDAK mengubah status HTTP (tetap 200
 * selama DB sehat), karena keduanya punya mekanisme fallback sendiri
 * (lihat S3Uploader & SnsNotifier) dan bukan syarat mutlak app "up".
 * Statusnya tetap dilaporkan di body response agar mudah dipantau.
 */
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

$full = require __DIR__ . '/../config.php';
$dbOk = false;

try {
    Database::get()->query('SELECT 1');
    $dbOk = true;
} catch (\Throwable $e) {
    $dbOk = false;
}

$s3  = checkS3Cli($full['s3'] ?? []);
$sns = checkSnsCli($full['sns'] ?? [], $full['s3']['region'] ?? '');

http_response_code($dbOk ? 200 : 500);

echo json_encode([
    'status' => $dbOk ? 'ok' : 'error',
    'db'     => $dbOk ? 'connected' : 'disconnected',
    's3'     => $s3,
    'sns'    => $sns,
]);

/**
 * Jalankan perintah shell dengan aman (escape tiap argumen) dan kembalikan
 * [exitCode, output, errorOutput]. Butuh shell_exec/proc_open aktif (tidak
 * di-disable lewat disable_functions di php.ini).
 */
function runCli(array $args): array
{
    if (!function_exists('proc_open')) {
        return [-1, '', 'proc_open dinonaktifkan di php.ini'];
    }

    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [-1, '', 'Gagal menjalankan proses'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, trim($stdout), trim($stderr)];
}

function awsCliAvailable(): bool
{
    [$code] = runCli(['which', 'aws']);
    return $code === 0;
}

/**
 * Cek konektivitas ke S3 lewat `aws s3api head-bucket`.
 */
function checkS3Cli(array $cfg): array
{
    if (empty($cfg['bucket'])) {
        return ['status' => 'not_configured', 'detail' => 'bucket kosong, memakai fallback lokal'];
    }

    if (!awsCliAvailable()) {
        return ['status' => 'unknown', 'detail' => 'AWS CLI tidak ditemukan di server'];
    }

    $args = ['aws', 's3api', 'head-bucket', '--bucket', $cfg['bucket']];
    if (!empty($cfg['region'])) {
        $args[] = '--region';
        $args[] = $cfg['region'];
    }

    [$code, , $stderr] = runCli($args);

    if ($code === 0) {
        return ['status' => 'connected'];
    }

    return ['status' => 'disconnected', 'detail' => $stderr ?: 'head-bucket gagal'];
}

/**
 * Cek konektivitas ke SNS lewat `aws sns get-topic-attributes`.
 */
function checkSnsCli(array $cfg, string $region): array
{
    $topicArn = $cfg['topic_arn'] ?? null;

    if (empty($topicArn)) {
        return ['status' => 'not_configured', 'detail' => 'topic_arn kosong, memakai fallback log'];
    }

    if (!awsCliAvailable()) {
        return ['status' => 'unknown', 'detail' => 'AWS CLI tidak ditemukan di server'];
    }

    $args = ['aws', 'sns', 'get-topic-attributes', '--topic-arn', $topicArn];
    if (!empty($region)) {
        $args[] = '--region';
        $args[] = $region;
    }

    [$code, , $stderr] = runCli($args);

    if ($code === 0) {
        return ['status' => 'connected'];
    }

    return ['status' => 'disconnected', 'detail' => $stderr ?: 'get-topic-attributes gagal'];
}