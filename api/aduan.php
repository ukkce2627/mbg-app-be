<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/S3Uploader.php';
require_once __DIR__ . '/../core/SnsNotifier.php';

function handle_aduan(string $method, ?string $id, ?string $subAction): void
{
    $pdo = Database::get();

    // GET /api/aduan — sesuai role: masyarakat (miliknya), sppg (miliknya), bgn (semua)
    if ($method === 'GET' && !$id) {
        $user = Auth::requireLogin();
        $sql = 'SELECT * FROM aduan WHERE 1=1';
        $params = [];

        if ($user['role'] === 'masyarakat') {
            $sql .= ' AND user_id = ?';
            $params[] = $user['id'];
        } elseif ($user['role'] === 'sppg') {
            $sql .= ' AND sppg_id = ?';
            $params[] = $user['sppg_id'];
        } // bgn: tanpa filter role (lihat semua), filter opsional di bawah

        if (!empty($_GET['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['sppg_id']) && $user['role'] === 'bgn') {
            $sql .= ' AND sppg_id = ?';
            $params[] = $_GET['sppg_id'];
        }
        if (!empty($_GET['user_id']) && $user['role'] === 'bgn') {
            $sql .= ' AND user_id = ?';
            $params[] = $_GET['user_id'];
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll());
    }

    // POST /api/aduan — masyarakat membuat aduan baru + upload foto (memicu SNS)
    if ($method === 'POST' && !$id) {
        $user = Auth::requireRole(['masyarakat']);

        $sppgId   = $_POST['sppg_id'] ?? null;
        $kategori = trim($_POST['kategori'] ?? '');
        $isi      = trim($_POST['isi'] ?? '');

        if (!$sppgId || $kategori === '' || $isi === '') {
            Response::error('sppg_id, kategori, dan isi wajib diisi', 'VALIDATION_ERROR', 400);
        }

        $fileUrl = null;
        if (!empty($_FILES['file'])) {
            $fileUrl = (new S3Uploader())->upload($_FILES['file'], 'aduan/');
        }

        $stmt = $pdo->prepare('INSERT INTO aduan (user_id, sppg_id, kategori, isi, file_url) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user['id'], $sppgId, $kategori, $isi, $fileUrl]);
        $newId = $pdo->lastInsertId();

        $sppgStmt = $pdo->prepare('SELECT nama FROM sppg WHERE id = ?');
        $sppgStmt->execute([$sppgId]);
        $sppgNama = $sppgStmt->fetchColumn() ?: '-';

        (new SnsNotifier())->publish('aduan', ['sppg_nama' => $sppgNama, 'kategori' => $kategori]);

        Response::success(['id' => $newId, 'file_url' => $fileUrl], 'Aduan berhasil dikirim', 201);
    }

    // PATCH /api/aduan/{id}/tanggapan — SPPG mengisi tanggapan
    if ($method === 'PATCH' && $id && $subAction === 'tanggapan') {
        $user = Auth::requireRole(['sppg']);
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $tanggapan = trim($in['tanggapan'] ?? '');

        if ($tanggapan === '') {
            Response::error('tanggapan wajib diisi', 'VALIDATION_ERROR', 400);
        }

        $stmt = $pdo->prepare('UPDATE aduan SET tanggapan = ?, status = "diproses" WHERE id = ? AND sppg_id = ?');
        $stmt->execute([$tanggapan, $id, $user['sppg_id']]);
        Response::success(null, 'Tanggapan tersimpan');
    }

    // PATCH /api/aduan/{id}/status — BGN update status
    if ($method === 'PATCH' && $id && $subAction === 'status') {
        Auth::requireRole(['bgn']);
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $in['status'] ?? '';

        if (!in_array($status, ['baru', 'diproses', 'selesai'], true)) {
            Response::error('Status tidak valid', 'VALIDATION_ERROR', 400);
        }

        $stmt = $pdo->prepare('UPDATE aduan SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        Response::success(null, 'Status aduan diperbarui');
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
