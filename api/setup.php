<?php
/**
 * setup.php — trigger seeding akun awal lewat HTTP (bukan CLI).
 *
 * Dipakai kalau Back End di-deploy sebagai private service yang hanya
 * bisa diakses dari Front End (tidak ada akses SSH/CLI langsung yang
 * praktis buat operator). Endpoint ini:
 *
 *   POST /api/setup/seed
 *
 * - Tetap wajib X-API-Key (dicek di public/index.php sebelum sampai sini).
 * - HANYA jalan kalau tabel users masih kosong. Begitu ada 1 baris user,
 *   endpoint ini otomatis menolak — supaya tidak bisa dipanggil ulang untuk
 *   mereset password akun default di production (celah keamanan kalau
 *   dibiarkan terbuka terus).
 * - Query ke sini otomatis memicu Database::get() -> migrate(), jadi tabel
 *   turut dibuat kalau belum ada, sama seperti endpoint lain.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';

function handle_setup(string $action, string $method): void
{
    $pdo = Database::get();

    if ($action === 'status' && $method === 'GET') {
        $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
        Response::success(['seeded' => $count > 0, 'user_count' => $count]);
    }

    if ($action === 'seed' && $method === 'POST') {
        $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];

        if ($count > 0) {
            Response::error(
                'Seeding ditolak: tabel users sudah berisi data (' . $count . ' akun). '
                . 'Endpoint ini hanya bisa dipakai sekali saat database masih kosong.',
                'ALREADY_SEEDED',
                409
            );
        }

        $defaultPassword = '123';
        $hash = Auth::hashPassword($defaultPassword);

        // 1) Seed 3 SPPG dulu, supaya akun sppg1/2/3 bisa langsung dikaitkan
        //    ke sppg_id yang benar (bukan NULL, tidak perlu UPDATE manual lagi).
        $sppgList = [
            ['nama' => 'SPPG 1', 'wilayah' => null],
            ['nama' => 'SPPG 2', 'wilayah' => null],
            ['nama' => 'SPPG 3', 'wilayah' => null],
        ];
        $stmtSppg = $pdo->prepare('INSERT INTO sppg (nama, wilayah) VALUES (:nama, :wilayah)');
        $sppgIds = [];
        foreach ($sppgList as $i => $s) {
            $stmtSppg->execute([':nama' => $s['nama'], ':wilayah' => $s['wilayah']]);
            $sppgIds[$i + 1] = (int) $pdo->lastInsertId(); // index 1..3 sesuai sppg1/2/3
        }

        // 2) Seed akun user, akun sppgN langsung dikaitkan ke sppg_id barusan.
        $accounts = [
            ['username' => 'bgn',    'nama' => 'Admin BGN',    'role' => 'bgn',        'sppg_id' => null],
            ['username' => 'sppg1',  'nama' => 'SPPG 1',       'role' => 'sppg',       'sppg_id' => $sppgIds[1]],
            ['username' => 'sppg2',  'nama' => 'SPPG 2',       'role' => 'sppg',       'sppg_id' => $sppgIds[2]],
            ['username' => 'sppg3',  'nama' => 'SPPG 3',       'role' => 'sppg',       'sppg_id' => $sppgIds[3]],
            ['username' => 'masy1',  'nama' => 'Masyarakat 1', 'role' => 'masyarakat', 'sppg_id' => null],
            ['username' => 'masy2',  'nama' => 'Masyarakat 2', 'role' => 'masyarakat', 'sppg_id' => null],
            ['username' => 'masy3',  'nama' => 'Masyarakat 3', 'role' => 'masyarakat', 'sppg_id' => null],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO users (nama, username, password, role, sppg_id)
             VALUES (:nama, :username, :password, :role, :sppg_id)'
        );

        $created = [];
        foreach ($accounts as $acc) {
            $stmt->execute([
                ':nama'     => $acc['nama'],
                ':username' => $acc['username'],
                ':password' => $hash,
                ':role'     => $acc['role'],
                ':sppg_id'  => $acc['sppg_id'],
            ]);
            $created[] = $acc['username'] . ' (' . $acc['role'] . ')';
        }

        Response::success([
            'sppg_created'      => array_column($sppgList, 'nama'),
            'accounts_created'  => $created,
            'default_password'  => $defaultPassword,
            'note' => "Ganti password akun-akun ini segera setelah login pertama. "
                    . "Akun sppg1/2/3 sudah otomatis terkait ke SPPG 1/2/3.",
        ], 'Seeding berhasil, 3 SPPG dan ' . count($created) . ' akun dibuat', 201);
    }

    Response::error('Endpoint tidak ditemukan', 'NOT_FOUND', 404);
}
