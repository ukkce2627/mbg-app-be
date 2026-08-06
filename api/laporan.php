<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/S3Uploader.php';
require_once __DIR__ . '/../core/SnsNotifier.php';

function handle_laporan(string $method, ?string $id, ?string $subAction): void
{
    $pdo = Database::get();

    // GET /api/laporan — SPPG (miliknya), BGN (semua)
    if ($method === 'GET' && !$id) {
        $user = Auth::requireRole(['sppg', 'bgn']);
        $status = $_GET['status'] ?? null;
        $sppgId = $_GET['sppg_id'] ?? null;

        $sql = 'SELECT * FROM laporan WHERE 1=1';
        $params = [];

        if ($user['role'] === 'sppg') {
            $sql .= ' AND sppg_id = ?';
            $params[] = $user['sppg_id'];
        } elseif ($sppgId) {
            $sql .= ' AND sppg_id = ?';
            $params[] = $sppgId;
        }
        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll());
    }

    // POST /api/laporan — SPPG membuat laporan baru (memicu SNS)
    if ($method === 'POST' && !$id) {
        $user = Auth::requireRole(['sppg']);

        $jenis = trim($_POST['jenis_laporan'] ?? '');
        $isi   = trim($_POST['isi'] ?? '');
        if ($jenis === '' || $isi === '') {
            Response::error('jenis_laporan dan isi wajib diisi', 'VALIDATION_ERROR', 400);
        }

        $fileUrl = null;
        if (!empty($_FILES['file'])) {
            $fileUrl = (new S3Uploader())->upload($_FILES['file'], 'laporan/');
        }

        $stmt = $pdo->prepare('INSERT INTO laporan (sppg_id, jenis_laporan, isi, file_url) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user['sppg_id'], $jenis, $isi, $fileUrl]);
        $newId = $pdo->lastInsertId();

        $sppgStmt = $pdo->prepare('SELECT nama FROM sppg WHERE id = ?');
        $sppgStmt->execute([$user['sppg_id']]);
        $sppgNama = $sppgStmt->fetchColumn() ?: '-';

        (new SnsNotifier())->publish('laporan', ['sppg_nama' => $sppgNama]);

        Response::success(['id' => $newId, 'file_url' => $fileUrl], 'Laporan berhasil dibuat', 201);
    }

    // PATCH /api/laporan/{id}/status — BGN
    if ($method === 'PATCH' && $id && $subAction === 'status') {
        Auth::requireRole(['bgn']);
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $in['status'] ?? '';

        if (!in_array($status, ['menunggu', 'ditinjau', 'selesai'], true)) {
            Response::error('Status tidak valid', 'VALIDATION_ERROR', 400);
        }

        $stmt = $pdo->prepare('UPDATE laporan SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        Response::success(null, 'Status laporan diperbarui');
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
