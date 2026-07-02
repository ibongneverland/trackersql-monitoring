<?php
/**
 * config.php
 * Konfigurasi database dan konstanta aplikasi
 */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'namadatabase');
define('DB_CHARSET', 'latin1');

// Tabel
define('TRACKER_TABLE', 'trackersql');
define('SUMMARY_TABLE', 'trackersql_summary');
define('ARCHIVE_TABLE', 'trackersql_archive');
define('SYNC_LOG_TABLE', 'trackersql_sync_log');

// Setting Archive
define('ARCHIVE_KEEP_MONTHS', 1);  // Simpan berapa bulan di tabel utama

function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 60,
        ]);
        $pdo->exec("SET SESSION wait_timeout = 120");
        return $pdo;
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        die("Koneksi database gagal.");
    }
}
?>