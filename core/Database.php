<?php

class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $cfg = require __DIR__ . '/../config.php';
            $db  = $cfg['db'];

            self::ensureDatabaseExists($db);

            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";

            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    /**
     * Auto-create database: PDO tidak bisa CREATE DATABASE lewat DSN yang
     * sudah menyertakan dbname (connect akan gagal duluan kalau db belum
     * ada). Jadi di sini kita connect dulu TANPA dbname, jalankan
     * CREATE DATABASE IF NOT EXISTS, baru serahkan ke get() untuk connect
     * ulang dengan dbname seperti biasa.
     */
    private static function ensureDatabaseExists(array $db): void
    {
        $dsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
        $tmp = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $name = str_replace('`', '``', $db['name']); // jaga-jaga dari nama db aneh
        $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tmp = null;
    }

    /** Auto-migration: CREATE TABLE IF NOT EXISTS untuk seluruh skema. */
    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sppg (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(150) NOT NULL,
                alamat VARCHAR(255) DEFAULT NULL,
                wilayah VARCHAR(150) DEFAULT NULL,
                penanggung_jawab VARCHAR(150) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(150) NOT NULL,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('bgn','sppg','masyarakat') NOT NULL DEFAULT 'masyarakat',
                sppg_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_role (role),
                CONSTRAINT fk_users_sppg FOREIGN KEY (sppg_id) REFERENCES sppg(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS laporan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sppg_id INT NOT NULL,
                jenis_laporan VARCHAR(100) NOT NULL,
                isi TEXT NOT NULL,
                file_url VARCHAR(500) DEFAULT NULL,
                status ENUM('menunggu','ditinjau','selesai') NOT NULL DEFAULT 'menunggu',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sppg (sppg_id),
                INDEX idx_status (status),
                CONSTRAINT fk_laporan_sppg FOREIGN KEY (sppg_id) REFERENCES sppg(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS aduan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                sppg_id INT NOT NULL,
                kategori VARCHAR(100) NOT NULL,
                isi TEXT NOT NULL,
                file_url VARCHAR(500) DEFAULT NULL,
                status ENUM('baru','diproses','selesai') NOT NULL DEFAULT 'baru',
                tanggapan TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_sppg (sppg_id),
                INDEX idx_status (status),
                INDEX idx_user (user_id),
                CONSTRAINT fk_aduan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_aduan_sppg FOREIGN KEY (sppg_id) REFERENCES sppg(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
