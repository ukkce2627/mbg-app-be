<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';

function handle_sppg(string $method, ?string $id): void
{
    $pdo = Database::get();

    if ($method === 'GET' && !$id) {
        // list boleh dilihat siapa saja yang login (dipakai utk pilih SPPG saat bikin aduan)
        Auth::requireLogin();
        Response::success($pdo->query('SELECT * FROM sppg ORDER BY nama')->fetchAll());
    }

    if ($method === 'GET' && $id) {
        Auth::requireLogin();
        $stmt = $pdo->prepare('SELECT * FROM sppg WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) Response::error('SPPG tidak ditemukan', 'NOT_FOUND', 404);
        Response::success($row);
    }

    if ($method === 'POST') {
        Auth::requireRole(['bgn']);
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $nama = trim($in['nama'] ?? '');
        if ($nama === '') Response::error('nama wajib diisi', 'VALIDATION_ERROR', 400);

        $stmt = $pdo->prepare('INSERT INTO sppg (nama, alamat, wilayah, penanggung_jawab) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nama, $in['alamat'] ?? null, $in['wilayah'] ?? null, $in['penanggung_jawab'] ?? null]);
        Response::success(['id' => $pdo->lastInsertId()], 'SPPG berhasil ditambahkan', 201);
    }

    if ($method === 'PUT' && $id) {
        Auth::requireRole(['bgn']);
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $stmt = $pdo->prepare('UPDATE sppg SET nama = ?, alamat = ?, wilayah = ?, penanggung_jawab = ? WHERE id = ?');
        $stmt->execute([$in['nama'] ?? '', $in['alamat'] ?? null, $in['wilayah'] ?? null, $in['penanggung_jawab'] ?? null, $id]);
        Response::success(null, 'SPPG berhasil diperbarui');
    }

    if ($method === 'DELETE' && $id) {
        Auth::requireRole(['bgn']);
        $stmt = $pdo->prepare('DELETE FROM sppg WHERE id = ?');
        $stmt->execute([$id]);
        Response::success(null, 'SPPG berhasil dihapus');
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
