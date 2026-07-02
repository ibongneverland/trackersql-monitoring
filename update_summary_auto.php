<?php
/**
 * update_summary_auto.php
 * Auto-update summary table saat dashboard dibuka
 */

function autoUpdateSummary($pdo) {
    $todayExists = $pdo->query("
        SELECT 1 FROM trackersql_summary 
        WHERE tanggal = CURDATE() 
        LIMIT 1
    ")->fetch();
    
    $lastUpdate = $pdo->query("
        SELECT MAX(sync_finished) as last 
        FROM trackersql_sync_log 
        WHERE status = 'completed'
    ")->fetchColumn();
    
    $needUpdate = false;
    
    if (!$todayExists) {
        $needUpdate = true;
        $reason = "Data hari ini belum ada";
    } elseif ($lastUpdate && strtotime($lastUpdate) < strtotime('-10 minutes')) {
        $needUpdate = true;
        $reason = "Terakhir update: " . date('H:i:s', strtotime($lastUpdate));
    }
    
    if (!$needUpdate) {
        return array('status' => 'ok', 'message' => 'Data masih fresh');
    }
    
    $running = $pdo->query("
        SELECT id FROM trackersql_sync_log 
        WHERE status = 'running' 
        AND sync_started > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ")->fetch();
    
    if ($running) {
        return array('status' => 'running', 'message' => 'Update sedang berjalan oleh proses lain');
    }
    
    $pdo->prepare("INSERT INTO trackersql_sync_log (sync_started, status) VALUES (NOW(), 'running')")->execute();
    $logId = $pdo->lastInsertId();
    
    try {
        $pdo->exec("CALL sp_update_tracker_summary(CURDATE())");
        $pdo->exec("CALL sp_update_tracker_summary(DATE_SUB(CURDATE(), INTERVAL 1 DAY))");
        $pdo->exec("CALL sp_backfill_summary(DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY))");
        
        $pdo->prepare("UPDATE trackersql_sync_log SET status = 'completed', sync_finished = NOW() WHERE id = ?")->execute([$logId]);
        
        return array(
            'status' => 'updated',
            'message' => 'Summary diperbarui otomatis',
            'reason' => $reason
        );
        
    } catch (Exception $e) {
        $pdo->prepare("UPDATE trackersql_sync_log SET status = 'failed', sync_finished = NOW() WHERE id = ?")->execute([$logId]);
        return array(
            'status' => 'error',
            'message' => 'Gagal update: ' . $e->getMessage()
        );
    }
}
?>