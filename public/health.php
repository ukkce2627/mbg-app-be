<?php
/**
 * Health check untuk Load Balancer.
 * WAJIB selalu mengembalikan HTTP 200 selama aplikasi & koneksi DB sehat.
 */
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    Database::get()->query('SELECT 1');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'db' => 'connected']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'db' => 'disconnected']);
}
