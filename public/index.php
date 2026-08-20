<?php
/**
 * Entry point Back End — routing seluruh endpoint REST API.
 * Struktur path: /api/{resource}/{id?}/{subAction?}
 */

// WAJIB paling atas: load Composer autoloader supaya class AWS SDK
// (\Aws\S3\S3Client, \Aws\Sns\SnsClient) benar-benar ter-load dan
// class_exists() di S3Uploader.php / SnsNotifier.php bisa mendeteksinya.
// Tanpa baris ini, SDK yang sudah ter-install lewat composer TETAP TIDAK
// TERPAKAI oleh aplikasi (selalu fallback ke lokal/log) walau vendor/
// sudah ada di server.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/setup.php';
require_once __DIR__ . '/../api/laporan.php';
require_once __DIR__ . '/../api/aduan.php';
require_once __DIR__ . '/../api/monitoring.php';
require_once __DIR__ . '/../api/sppg.php';
require_once __DIR__ . '/../api/upload.php';

Auth::startSession();

// CORS dasar — sesuaikan origin di production sesuai domain Front End
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Ambil bagian path SETELAH "/public/" atau setelah folder app kalau app
// diakses langsung di root domain (tanpa /public/ terlihat, karena root
// .htaccess sudah internal-rewrite ke public/ secara transparan).
//
// CATATAN: sengaja TIDAK memakai dirname(SCRIPT_NAME) untuk strip prefix,
// karena saat request masuk lewat rewrite dari .htaccess root (tanpa
// '/public/' di REQUEST_URI), SCRIPT_NAME tetap menunjuk ke lokasi FISIK
// index.php (mis. '/mbg-app-backend/public/index.php'), sehingga prefix
// '/mbg-app-backend/public' TIDAK PERNAH cocok dengan REQUEST_URI yang
// sebenarnya ('/mbg-app-backend/api/...') -> selalu berakhir 404 walau
// endpoint & kode routing-nya benar. Pendekatan di bawah ini cari
// posisi '/api' atau '/health' langsung di URL, jadi tidak bergantung
// pada berapa lapis subfolder tempat app ini ditaruh.
if (preg_match('#/(api|health(?:\.php)?)(/.*)?$#', $path, $m)) {
    $path = '/' . $m[1] . ($m[2] ?? '');
} else {
    $path = '';
}
$parts  = array_values(array_filter(explode('/', $path)));

// Health check tidak butuh API key
if (($parts[0] ?? '') === 'health.php' || ($parts[0] ?? '') === 'health') {
    require __DIR__ . '/health.php';
    exit;
}

if (($parts[0] ?? '') !== 'api') {
    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}

Auth::requireApiKey();

$resource   = $parts[1] ?? '';
$sub1       = $parts[2] ?? null; // bisa id, atau action seperti 'register'
$sub2       = $parts[3] ?? null; // bisa subAction, mis. 'status'

switch ($resource) {
    case 'auth':
        handle_auth((string)$sub1, $method);
        break;

    case 'setup':
        handle_setup((string)$sub1, $method);
        break;

    case 'laporan':
        handle_laporan($method, $sub1, $sub2);
        break;

    case 'aduan':
        handle_aduan($method, $sub1, $sub2);
        break;

    case 'monitoring':
        handle_monitoring((string)$sub1, $method);
        break;

    case 'sppg':
        handle_sppg($method, $sub1);
        break;

    case 'upload':
        handle_upload($method);
        break;

    default:
        Response::error('Resource tidak ditemukan', 'NOT_FOUND', 404);
}