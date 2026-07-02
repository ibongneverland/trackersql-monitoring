<?php
/**
 * update_summary_web.php
 * Jalankan via browser: http://localhost/etracker/update_summary_web.php
 * Atau via AJAX untuk background update
 */

require_once 'config.php';

// ============================================
// FIX: Mulai output buffer sebelum apapun
// ============================================
if (ob_get_level() == 0) {
    ob_start();
}

// Naikkan limit untuk browser
set_time_limit(300);
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

$startTime = microtime(true);
$batchSize = 7;
$delaySeconds = 1;

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Update Summary - Tracker SQL</title>
    <style>
        body { font-family: 'Segoe UI', monospace; background: #0f172a; color: #e2e8f0; padding: 30px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #34d399; }
        .log { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .log-line { padding: 4px 0; border-bottom: 1px solid #334155; font-size: 13px; }
        .log-line:last-child { border-bottom: none; }
        .success { color: #34d399; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        .progress-bar { width: 100%; height: 20px; background: #334155; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #34d399); transition: width 0.3s; }
        .btn { display: inline-block; padding: 10px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; margin-top: 20px; }
        .btn:hover { background: #2563eb; }
        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
        .stat-box { background: #1e293b; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-box .number { font-size: 24px; font-weight: 700; color: #34d399; }
        .stat-box .label { font-size: 12px; color: #94a3b8; margin-top: 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔄 Update Summary Table</h1>
    <div class='log'>";

// FIX: Flush dengan cek buffer
function safeFlush() {
    if (ob_get_level() > 0) {
        ob_flush();
        flush();
    }
}

function logMsg($msg, $type = 'info') {
    $color = $type == 'success' ? 'success' : ($type == 'error' ? 'error' : 'info');
    echo "<div class='log-line {$color}'>[" . date('H:i:s') . "] {$msg}</div>";
    safeFlush();
}

try {
    $pdo = getDBConnection();
    
    // Cek apakah ada sync yang sedang berjalan
    $running = $pdo->query("
        SELECT id, sync_started 
        FROM trackersql_sync_log 
        WHERE status = 'running' 
        AND sync_started > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ")->fetch();
    
    if ($running) {
        logMsg("⚠️ Sync sedang berjalan sejak {$running['sync_started']} (ID: {$running['id']})", 'error');
        echo "</div><a href='update_summary_web.php' class='btn'>🔄 Coba Lagi</a>";
        echo "</div></body></html>";
        exit;
    }
    
    // Insert log
    $pdo->prepare("INSERT INTO trackersql_sync_log (sync_started, status) VALUES (NOW(), 'running')")->execute();
    $logId = $pdo->lastInsertId();
    logMsg("Sync dimulai (ID: {$logId})", 'info');
    
    // Cek tanggal terakhir
    $lastDate = $pdo->query("SELECT MAX(tanggal) FROM trackersql_summary")->fetchColumn();
    $startDate = $lastDate ? date('Y-m-d', strtotime($lastDate . ' +1 day')) : date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
    
    logMsg("Rentang: {$startDate} s/d {$endDate}", 'info');
    
    $totalDays = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;
    $processedDays = 0;
    $current = $startDate;
    
    echo "<div class='progress-bar'><div class='progress-fill' id='progress' style='width:0%'></div></div>";
    safeFlush();
    
    while (strtotime($current) <= strtotime($endDate)) {
        $batchEnd = date('Y-m-d', min(strtotime($current . " +{$batchSize} days"), strtotime($endDate)));
        
        logMsg("📦 Processing: {$current} s/d {$batchEnd} ... ", 'info');
        $batchStart = microtime(true);
        
        try {
            $pdo->exec("CALL sp_backfill_summary('{$current}', '{$batchEnd}')");
            $elapsed = round(microtime(true) - $batchStart, 2);
            logMsg("✅ Selesai ({$elapsed}s)", 'success');
            
        } catch (Exception $e) {
            logMsg("❌ ERROR: " . $e->getMessage(), 'error');
            break;
        }
        
        $processedDays += $batchSize;
        $progress = min(100, round(($processedDays / $totalDays) * 100));
        
        echo "<script>document.getElementById('progress').style.width='{$progress}%';</script>";
        safeFlush();
        
        $current = date('Y-m-d', strtotime($batchEnd . ' +1 day'));
        
        if (strtotime($current) <= strtotime($endDate)) {
            logMsg("⏸️ Jeda {$delaySeconds} detik...", 'info');
            sleep($delaySeconds);
        }
    }
    
    // Update log
    $pdo->prepare("
        UPDATE trackersql_sync_log 
        SET status = 'completed', sync_finished = NOW() 
        WHERE id = ?
    ")->execute([$logId]);
    
    $totalElapsed = round(microtime(true) - $startTime, 2);
    logMsg("🎉 SELESAI! Total waktu: {$totalElapsed} detik", 'success');
    
    $summaryCount = $pdo->query("SELECT COUNT(*) FROM trackersql_summary")->fetchColumn();
    $summaryDays = $pdo->query("SELECT COUNT(DISTINCT tanggal) FROM trackersql_summary")->fetchColumn();
    
    echo "</div>
    <div class='stats'>
        <div class='stat-box'>
            <div class='number'>" . number_format($summaryCount) . "</div>
            <div class='label'>Total Rows Summary</div>
        </div>
        <div class='stat-box'>
            <div class='number'>" . number_format($summaryDays) . "</div>
            <div class='label'>Hari Tercover</div>
        </div>
    </div>
    <a href='index.php' class='btn'>📊 Buka Dashboard</a>
    <a href='update_summary_web.php' class='btn' style='background:#334155; margin-left:10px;'>🔄 Update Lagi</a>";
    
} catch (Exception $e) {
    if (isset($logId)) {
        $pdo->prepare("
            UPDATE trackersql_sync_log 
            SET status = 'failed', sync_finished = NOW() 
            WHERE id = ?
        ")->execute([$logId]);
    }
    logMsg("❌ FATAL ERROR: " . $e->getMessage(), 'error');
    echo "</div>";
}

echo "</div></body></html>";

// FIX: Bersihkan buffer di akhir
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>