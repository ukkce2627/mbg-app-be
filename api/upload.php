<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/S3Uploader.php';

function handle_upload(string $method): void
{
    if ($method !== 'POST') {
        Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
    }

    Auth::requireLogin(); // semua role yang login boleh upload

    if (empty($_FILES['file'])) {
        Response::error('File tidak ditemukan pada request', 'VALIDATION_ERROR', 400);
    }

    $prefix = $_POST['prefix'] ?? 'misc/';
    $s3 = new S3Uploader();
    $fileKey = $s3->upload($_FILES['file'], $prefix);

    // PENTING: kalau file_key ini mau disimpan sendiri oleh pemanggil (mis.
    // disimpan ke kolom lain di DB), simpan $fileKey (S3 object key) — BUKAN
    // file_url di response ini, karena file_url di sini cuma presigned URL
    // sementara (expire) untuk ditampilkan saat itu juga.
    Response::success([
        'file_key' => $fileKey,
        'file_url' => $s3->getUrl($fileKey),
    ], 'Upload berhasil', 201);
}