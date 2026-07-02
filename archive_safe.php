<?php
/**
 * archive_safe.php
 * Archive data lama ke trackersql_archive
 * Metode: INSERT IGNORE ... LIMIT + DELETE ... LIMIT (tanpa JOIN)
 */

require_once 'config.php';

set_time_limit(3600);
ini_set('memory_limit', '256M');

if (ob_get_level() == 0) ob_start();
function safeFlush() {
    if (ob_get_level() > 0) { ob_flush(); flush(); }
}

$KEEP_MONTHS = 1;
$BATCH_SIZE = 10000;
$DELAY_MS = 1000000;

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Safe Archive - Tracker SQL</title>
    <style>
        body { font-family: 'Segoe UI', monospace; background: #0f172a; color: #e2e8f0; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #34d399; }
        .info { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 15px; margin: 15px 0; font-size: 13px; }
        .log { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 15px; margin: 15px 0; max-height: 400px; overflow-y: auto; }
        .log-line { padding: 3px 0; font-size: 12px; border-bottom: 1px solid #1e293b; }
        .success { color: #34d399; }
        .info2 { color: #60a5fa; }
        .warning { color: #fbbf24; }
        .btn { display: inline-block; padding: 10px 24px; background: #f87171; color: white; text-decoration: none; border-radius: 8px; margin: 5px; font-weight: 600; }
        .btn-secondary { background: #334155; }
        .progress-bar { width: 100%; height: 20px; background: #334155; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #34d399, #60a5fa); transition: width 0.3s; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🛡️ Safe Archive</h1>
        <div class='info'>
            ✅ Metode: INSERT IGNORE ... LIMIT + DELETE ... LIMIT<br>
            ✅ Tidak pakai JOIN, tidak pakai subquery di DELETE<br>
            ✅ Lock minimal, aplikasi tetap bisa insert
        </div>
    <div class='log'>";

function logMsg($msg, $type = 'info2') {
    echo "<div class='log-line {$type}'>[" . date('H:i:s') . "] {$msg}</div>";
    safeFlush();
}

try {
    $pdo = getDBConnection();
    
    // Cek & buat tabel archive
    $tableExists = $pdo->query("
        SELECT 1 FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' 
        AND table_name = 'trackersql_archive'
    ")->fetch();
    
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE trackersql_archive (
                tanggal DATETIME NOT NULL,
                sqle TEXT,
                usere VARCHAR(20),
                INDEX idx_tanggal (tanggal),
                INDEX idx_usere (usere)
            ) ENGINE=InnoDB
        ");
        logMsg("✅ Tabel trackersql_archive dibuat", "success");
    }
    
    $cutoffDate = date('Y-m-d', strtotime("-{$KEEP_MONTHS} months"));
    
    // Hitung total yang akan di-archive
    $totalToArchive = (int)$pdo->query("
        SELECT COUNT(*) FROM trackersql WHERE tanggal < '{$cutoffDate}'
    ")->fetchColumn();
    
    $totalMain = (int)$pdo->query("
        SELECT table_rows FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' AND table_name = 'trackersql'
    ")->fetchColumn();
    
    logMsg("📊 Cutoff date: {$cutoffDate}", "info2");
    logMsg("📊 trackersql: ~" . number_format($totalMain) . " record (estimasi)", "info2");
    logMsg("📊 Yang akan di-archive: " . number_format($totalToArchive), "warning");
    
    if ($totalToArchive == 0) {
        logMsg("✅ Tidak ada data yang perlu di-archive", "success");
        echo "</div><a href='index.php' class='btn btn-secondary'>Kembali ke Dashboard</a></div></body></html>";
        exit;
    }
    
    // Estimasi waktu
    $estHours = round(($totalToArchive / $BATCH_SIZE * 1.5) / 3600, 1);
    logMsg("⏱️ Estimasi waktu: ~{$estHours} jam", "info2");
    
    // KONFIRMASI
    if (!isset($_GET['confirm'])) {
        echo "</div>";
        echo "<div class='info'>";
        echo "⚠️ <strong>" . number_format($totalToArchive) . "</strong> record akan dipindah ke archive<br>";
        echo "⏱️ Estimasi: ~" . $estHours . " jam<br>";
        echo "🔒 Aplikasi tetap bisa insert selama proses";
        echo "</div>";
        echo "<a href='?confirm=1' class='btn'>✅ Konfirmasi & Mulai Archive</a>";
        echo "<a href='index.php' class='btn btn-secondary'>❌ Batal</a>";
        echo "</div></body></html>";
        exit;
    }
    
    // PROSES ARCHIVE
    logMsg("🚀 MULAI ARCHIVE...", "success");
    
    $totalMoved = 0;
    $batchNum = 0;
    
    echo "<div class='progress-bar'><div class='progress-fill' id='progress' style='width:0%'></div></div>";
    safeFlush();
    
    while ($totalMoved < $totalToArchive) {
        $batchNum++;
        $batchStart = microtime(true);
        
        // ============================================
        // STEP 1: Copy ke archive (LIMIT di SELECT)
        // ============================================
        $copied = $pdo->exec("
            INSERT IGNORE INTO trackersql_archive (tanggal, sqle, usere)
            SELECT tanggal, sqle, usere 
            FROM trackersql
            WHERE tanggal < '{$cutoffDate}'
            LIMIT {$BATCH_SIZE}
        ");
        
        if ($copied == 0) {
            logMsg("Tidak ada data lagi yang bisa di-copy", "info2");
            break;
        }
        
        // ============================================
        // STEP 2: Delete dari tabel utama (LIMIT langsung)
        // ============================================
        $deleted = $pdo->exec("
            DELETE FROM trackersql
            WHERE tanggal < '{$cutoffDate}'
            LIMIT {$BATCH_SIZE}
        ");
        
        $totalMoved += $copied;
        $elapsed = round(microtime(true) - $batchStart, 2);
        $progress = min(100, round(($totalMoved / $totalToArchive) * 100));
        
        logMsg("Batch #{$batchNum}: Copied {$copied}, Deleted {$deleted} ({$elapsed}s) | Total: " . number_format($totalMoved) . " ({$progress}%)", "success");
        
        echo "<script>document.getElementById('progress').style.width='{$progress}%';</script>";
        safeFlush();
        
        // JEDA agar aplikasi bisa insert
        if ($totalMoved < $totalToArchive) {
            usleep($DELAY_MS);
        }
    }
    
    // Hasil akhir
    $finalMain = (int)$pdo->query("
        SELECT table_rows FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' AND table_name = 'trackersql'
    ")->fetchColumn();
    
    $finalArchive = (int)$pdo->query("SELECT COUNT(*) FROM trackersql_archive")->fetchColumn();
    
    logMsg("🎉 ARCHIVE SELESAI!", "success");
    logMsg("🗄️ trackersql: ~" . number_format($finalMain) . " record", "info2");
    logMsg("📦 archive: " . number_format($finalArchive) . " record", "info2");
    
    echo "</div>
    <div class='info'>
        <div class='progress-bar'><div class='progress-fill' style='width:100%'></div></div>
        ✅ Selesai!<br>
        🗄️ trackersql: ~" . number_format($finalMain) . " record<br>
        📦 archive: " . number_format($finalArchive) . " record
    </div>";
    
} catch (Exception $e) {
    logMsg("❌ ERROR: " . $e->getMessage(), "warning");
}

echo "<a href='archive_safe.php' class='btn btn-secondary'>🔄 Archive Lagi</a>";
echo "<a href='index.php' class='btn btn-secondary'>📊 Dashboard</a>";
echo "</div></body></html>";

if (ob_get_level() > 0) ob_end_flush();
?>