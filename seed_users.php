<?php
/**
 * seed_users.php — generate akun awal untuk tiap role, tanpa email.
 *
 * Akun yang dibuat (password sama semua: 123):
 *   bgn                    (role: bgn)
 *   sppg1, sppg2, sppg3    (role: sppg, masing-masing terkait 1 SPPG)
 *   masy1, masy2, masy3    (role: masyarakat)
 *
 * Cara pakai (sekali saja saat setup awal):
 *   php seed_users.php
 *
 * Memakai Database::get() yang sama dengan aplikasi (otomatis menjalankan
 * auto-migration bila tabel belum ada), jadi skema selalu konsisten dengan
 * core/Database.php.
 */

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';

$pdo = Database::get();

$defaultPassword = '123';
$hash = Auth::hashPassword($defaultPassword);

// 1) Seed 3 SPPG dulu supaya akun sppg1/2/3 langsung bisa dikaitkan.
$sppgList = [
    ['nama' => 'SPPG 1', 'wilayah' => null],
    ['nama' => 'SPPG 2', 'wilayah' => null],
    ['nama' => 'SPPG 3', 'wilayah' => null],
];
$stmtSppg = $pdo->prepare('INSERT INTO sppg (nama, wilayah) VALUES (:nama, :wilayah)');
$sppgIds = [];
foreach ($sppgList as $i => $s) {
    $stmtSppg->execute([':nama' => $s['nama'], ':wilayah' => $s['wilayah']]);
    $sppgIds[$i + 1] = (int) $pdo->lastInsertId();
    echo "OK: SPPG dibuat -> {$s['nama']} (id: {$sppgIds[$i + 1]})\n";
}

$accounts = [
    ['username' => 'bgn',   'nama' => 'Admin BGN',    'role' => 'bgn',        'sppg_id' => null],
    ['username' => 'sppg1', 'nama' => 'SPPG 1',       'role' => 'sppg',       'sppg_id' => $sppgIds[1]],
    ['username' => 'sppg2', 'nama' => 'SPPG 2',       'role' => 'sppg',       'sppg_id' => $sppgIds[2]],
    ['username' => 'sppg3', 'nama' => 'SPPG 3',       'role' => 'sppg',       'sppg_id' => $sppgIds[3]],
    ['username' => 'masy1', 'nama' => 'Masyarakat 1', 'role' => 'masyarakat', 'sppg_id' => null],
    ['username' => 'masy2', 'nama' => 'Masyarakat 2', 'role' => 'masyarakat', 'sppg_id' => null],
    ['username' => 'masy3', 'nama' => 'Masyarakat 3', 'role' => 'masyarakat', 'sppg_id' => null],
];

$stmt = $pdo->prepare("
    INSERT INTO users (nama, username, password, role, sppg_id)
    VALUES (:nama, :username, :password, :role, :sppg_id)
    ON DUPLICATE KEY UPDATE
        nama     = VALUES(nama),
        password = VALUES(password),
        role     = VALUES(role),
        sppg_id  = VALUES(sppg_id)
");

foreach ($accounts as $acc) {
    $stmt->execute([
        ':nama'     => $acc['nama'],
        ':username' => $acc['username'],
        ':password' => $hash,
        ':role'     => $acc['role'],
        ':sppg_id'  => $acc['sppg_id'],
    ]);
    echo "OK: {$acc['username']} (role: {$acc['role']}) — password: {$defaultPassword}\n";
}

echo "\nSelesai. Total " . count($accounts) . " akun dan " . count($sppgList) . " SPPG dibuat/diperbarui.\n";
