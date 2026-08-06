<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';

function handle_monitoring(string $endpoint, string $method): void
{
    $pdo = Database::get();

    if ($endpoint === 'bgn' && $method === 'GET') {
        Auth::requireRole(['bgn']);

        $laporan = $pdo->query('SELECT status, COUNT(*) AS total FROM laporan GROUP BY status')->fetchAll();
        $aduan   = $pdo->query('SELECT status, COUNT(*) AS total FROM aduan GROUP BY status')->fetchAll();
        $perSppg = $pdo->query('
            SELECT s.id, s.nama,
                   (SELECT COUNT(*) FROM laporan l WHERE l.sppg_id = s.id) AS total_laporan,
                   (SELECT COUNT(*) FROM aduan a WHERE a.sppg_id = s.id) AS total_aduan
            FROM sppg s ORDER BY s.nama
        ')->fetchAll();

        Response::success([
            'laporan_by_status' => $laporan,
            'aduan_by_status'   => $aduan,
            'per_sppg'          => $perSppg,
        ]);
    }

    if ($endpoint === 'sppg' && $method === 'GET') {
        $user = Auth::requireRole(['sppg']);

        $laporan = $pdo->prepare('SELECT status, COUNT(*) AS total FROM laporan WHERE sppg_id = ? GROUP BY status');
        $laporan->execute([$user['sppg_id']]);

        $aduan = $pdo->prepare('SELECT status, COUNT(*) AS total FROM aduan WHERE sppg_id = ? GROUP BY status');
        $aduan->execute([$user['sppg_id']]);

        Response::success([
            'laporan_by_status' => $laporan->fetchAll(),
            'aduan_by_status'   => $aduan->fetchAll(),
        ]);
    }

    if ($endpoint === 'publik' && $method === 'GET') {
        // Publik, tanpa login, tanpa data pribadi
        $totalLaporan = $pdo->query('SELECT COUNT(*) FROM laporan')->fetchColumn();
        $totalAduan   = $pdo->query('SELECT COUNT(*) FROM aduan')->fetchColumn();
        $selesai      = $pdo->query('SELECT COUNT(*) FROM aduan WHERE status = "selesai"')->fetchColumn();
        $totalSppg    = $pdo->query('SELECT COUNT(*) FROM sppg')->fetchColumn();

        Response::success([
            'total_sppg'    => (int)$totalSppg,
            'total_laporan' => (int)$totalLaporan,
            'total_aduan'   => (int)$totalAduan,
            'aduan_selesai' => (int)$selesai,
        ]);
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
