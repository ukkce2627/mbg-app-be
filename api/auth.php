<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';

function handle_auth(string $action, string $method): void
{
    $pdo = Database::get();

    if ($action === 'register' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $nama     = trim($in['nama'] ?? '');
        $username = trim($in['username'] ?? '');
        $pass     = (string)($in['password'] ?? '');

        if ($nama === '' || $username === '' || strlen($pass) < 6) {
            Response::error('Nama, username, dan password (min 6 karakter) wajib diisi', 'VALIDATION_ERROR', 400);
        }
        if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            Response::error('Username hanya boleh huruf, angka, titik, underscore (3-50 karakter)', 'VALIDATION_ERROR', 400);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            Response::error('Username sudah terpakai', 'VALIDATION_ERROR', 400);
        }

        $stmt = $pdo->prepare('INSERT INTO users (nama, username, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nama, $username, Auth::hashPassword($pass), 'masyarakat']);

        Response::success(['id' => $pdo->lastInsertId()], 'Registrasi berhasil', 201);
    }

    if ($action === 'login' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($in['username'] ?? '');
        $pass     = (string)($in['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !Auth::verifyPassword($pass, $user['password'])) {
            Response::error('Username atau password salah', 'UNAUTHORIZED', 401);
        }

        Auth::login($user);
        unset($user['password']);
        Response::success($user, 'Login berhasil');
    }

    if ($action === 'me' && $method === 'GET') {
        $user = Auth::requireLogin();
        Response::success($user);
    }

    if ($action === 'logout' && $method === 'POST') {
        Auth::requireLogin();
        Auth::logout();
        Response::success(null, 'Logout berhasil');
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
