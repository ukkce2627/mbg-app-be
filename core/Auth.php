<?php
require_once __DIR__ . '/Response.php';

class Auth
{
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** Wajib dipanggil di awal setiap endpoint kecuali /health.php */
    public static function requireApiKey(): void
    {
        $cfg = require __DIR__ . '/../config.php';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $sent = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');

        if (!$sent || !hash_equals($cfg['api_key'], $sent)) {
            Response::error('API key tidak valid', 'UNAUTHORIZED', 401);
        }
    }

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function login(array $user): void
    {
        self::startSession();
        // CATATAN: session_regenerate_id() SENGAJA TIDAK dipakai di sini.
        // FE meneruskan PHPSESSID miliknya sendiri ke BE lewat cURL
        // (lihat ApiClient.php), dan BE memakai ID yang sama itu untuk
        // session-nya. Kalau ID diregenerate di sini, ID baru cuma
        // tersimpan di Set-Cookie response BE yang tidak pernah sampai
        // ke browser (panggilannya server-to-server), sehingga request
        // berikutnya dari FE selalu memakai ID lama yang datanya sudah
        // pindah -> requireLogin() selalu gagal ("session tidak valid").
        $_SESSION['user'] = [
            'id'       => $user['id'],
            'nama'     => $user['nama'],
            'username' => $user['username'],
            'role'     => $user['role'],
            'sppg_id'  => $user['sppg_id'],
        ];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function currentUser(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    /** Wajib login. Mengembalikan data user jika valid, atau langsung 401. */
    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if (!$user) {
            Response::error('Belum login atau session tidak valid', 'UNAUTHORIZED', 401);
        }
        return $user;
    }

    /** Wajib login DAN salah satu dari role yang diizinkan. */
    public static function requireRole(array $allowedRoles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::error('Anda tidak berhak mengakses resource ini', 'FORBIDDEN', 403);
        }
        return $user;
    }
}