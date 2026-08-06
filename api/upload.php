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
    $url = (new S3Uploader())->upload($_FILES['file'], $prefix);

    Response::success(['file_url' => $url], 'Upload berhasil', 201);
}
