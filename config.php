<?php
/**
 * config.php — satu-satunya sumber konfigurasi Back End.
 *
 * Tidak lagi memakai file .env. Isi langsung nilai-nilai di bawah ini
 * (misalnya oleh tim infrastruktur saat deploy ke server), lalu pastikan
 * file ini TIDAK di-commit dengan kredensial production ke repo publik
 * (gunakan config.php.example sebagai template kalau perlu, dan tambahkan
 * config.php ke .gitignore).
 *
 * Catatan: core/Database.php otomatis menjalankan CREATE DATABASE IF NOT
 * EXISTS memakai kredensial di bawah ini kalau database 'name' belum ada.
 * Kalau nanti user DB di production dibuat lebih terbatas (bukan root),
 * pastikan user itu tetap punya privilege CREATE di level server, atau
 * buat database-nya manual sekali di awal dan privilege itu tidak lagi
 * diperlukan setelahnya.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'user' => 'root',
        'pass' => '',
        'name' => 'mbg_db',
    ],
    's3' => [
        'bucket' => '',   // isi nama bucket S3, kosongkan untuk fallback lokal
        'region' => '',   // mis. 'ap-southeast-1'
    ],
    'sns' => [
        'topic_arn' => '', // ARN topic SNS, kosongkan untuk fallback log
    ],
    'api_key' => 'mbg-secret-key-2024',
];
