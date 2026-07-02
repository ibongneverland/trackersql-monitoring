<?php
/**
 * debug_search.php
 * Debug pencarian di TABEL ARCHIVE dengan batasan tanggal
 */

require_once 'config.php';

// Inisialisasi variabel untuk menghindari undefined
$keyword = isset($_GET['k']) ? $_GET['k'] : '2026/01/04/000';
$dateFrom = isset($_GET['from']) ? $_GET['from'] : '2026-01-01';
$dateTo = isset($_GET['to']) ? $_GET['to'] : '2026-01-31';
$archiveCount = 0;
$results = array();

try {
    $pdo = getDBConnection();
    
    // Hitung archive (dari information_schema, cepat)
    $archiveCount = (int)$pdo->query("
        SELECT table_rows FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' AND table_name = 'trackersql_archive'
    ")->fetchColumn();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// HTML Output
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Debug Search Archive</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', monospace; background: #0f172a; color: #e2e8f0; padding: 30px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #34d399; margin-bottom: 20px; }
        h2 { color: #60a5fa; margin: 20px 0 10px; font-size: 18px; }
        .info { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 15px; margin: 15px 0; }
        .warning { background: #451a03; border: 1px solid #92400e; color: #fbbf24; padding: 12px; border-radius: 8px; margin: 15px 0; }
        .error { background: #450a0a; border: 1px solid #f87171; color: #f87171; padding: 12px; border-radius: 8px; margin: 15px 0; }
        .success { background: #064e3b; border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin: 15px 0; }
        .form { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; color: #94a3b8; font-size: 12px; margin-bottom: 5px; text-transform: uppercase; }
        .form-group input { 
            background: #0f172a; border: 1px solid #334155; color: #e2e8f0;
            padding: 10px 12px; border-radius: 6px; width: 100%; font-size: 14px;
        }
        .btn { 
            display: inline-block; padding: 10px 24px; background: #3b82f6; color: white; 
            text-decoration: none; border-radius: 8px; border: none; cursor: pointer;
            font-size: 14px; font-weight: 600; 
        }
        .btn:hover { background: #2563eb; }
        pre { 
            background: #0f172a; border: 1px solid #334155; padding: 15px; 
            border-radius: 6px; overflow-x: auto; font-size: 12px; line-height: 1.5;
        }
        .result-box { 
            background: #1e293b; border: 1px solid #334155; padding: 15px; 
            margin: 10px 0; border-radius: 8px; 
        }
        .result-box .meta { color: #94a3b8; font-size: 12px; margin-bottom: 8px; }
        .sql-preview { 
            background: #0f172a; padding: 10px; border-radius: 4px; 
            font-family: monospace; font-size: 11px; color: #cbd5e1;
            white-space: pre-wrap; word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Search Archive</h1>
        
        <?php if (isset($error)): ?>
        <div class="error">
            ❌ Database Error: <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <div class="info">
            📊 Total archive: ~<?php echo number_format($archiveCount); ?> record
        </div>
        
        <!-- Form -->
        <div class="form">
            <form method="GET">
                <div class="form-group">
                    <label>Kata Kunci (Nomor Rawat)</label>
                    <input type="text" name="k" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Contoh: 2026/01/04/000">
                </div>
                <div class="form-group">
                    <label>Dari Tanggal</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="form-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <button type="submit" class="btn">🔍 Cari</button>
                <a href="?" class="btn" style="background: #334155; margin-left: 10px;">Reset</a>
            </form>
        </div>
        
        <?php
        // ============================================
        // PROSES SEARCH
        // ============================================
        if (!empty($keyword) && isset($pdo)) {
            echo "<h2>Hasil Pencarian</h2>";
            
            set_time_limit(120);
            
            // 1. LIKE dengan batasan tanggal
            echo "<div class='info'>Mencari: '" . htmlspecialchars($keyword) . "' dari " . htmlspecialchars($dateFrom) . " s/d " . htmlspecialchars($dateTo) . "</div>";
            
            $start = microtime(true);
            
            try {
                $stmt = $pdo->prepare("
                    SELECT tanggal, sqle, usere 
                    FROM trackersql_archive 
                    WHERE tanggal BETWEEN ? AND ?
                    AND sqle LIKE ?
                    LIMIT 10
                ");
                
                $stmt->execute([
                    $dateFrom . ' 00:00:00',
                    $dateTo . ' 23:59:59',
                    '%' . $keyword . '%'
                ]);
                
                $results = $stmt->fetchAll();
                $elapsed = round(microtime(true) - $start, 3);
                
                if (empty($results)) {
                    echo "<div class='warning'>";
                    echo "❌ Tidak ditemukan dengan LIKE<br>";
                    echo "Mencoba variasi tanpa slash...";
                    echo "</div>";
                    
                    // Coba tanpa slash
                    $keywordNoSlash = str_replace('/', '', $keyword);
                    $stmt2 = $pdo->prepare("
                        SELECT tanggal, sqle, usere 
                        FROM trackersql_archive 
                        WHERE tanggal BETWEEN ? AND ?
                        AND sqle LIKE ?
                        LIMIT 10
                    ");
                    $stmt2->execute([
                        $dateFrom . ' 00:00:00',
                        $dateTo . ' 23:59:59',
                        '%' . $keywordNoSlash . '%'
                    ]);
                    $results = $stmt2->fetchAll();
                    
                    if (!empty($results)) {
                        echo "<div class='success'>✅ Ditemukan dengan variasi tanpa slash! ({$elapsed}s)</div>";
                    } else {
                        echo "<div class='error'>❌ Tidak ditemukan dengan semua variasi</div>";
                    }
                } else {
                    echo "<div class='success'>✅ Ditemukan " . count($results) . " record ({$elapsed}s)</div>";
                }
                
                // Tampilkan hasil
                foreach ($results as $r) {
                    echo "<div class='result-box'>";
                    echo "<div class='meta'>";
                    echo "📅 Tanggal: " . $r['tanggal'] . " | ";
                    echo "👤 User: " . htmlspecialchars($r['usere']);
                    echo "</div>";
                    echo "<div class='sql-preview'>" . htmlspecialchars($r['sqle']) . "</div>";
                    echo "</div>";
                }
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            
            // Sample data untuk referensi
            echo "<h2>Sample Data Archive (5 terakhir)</h2>";
            try {
                $samples = $pdo->query("
                    SELECT tanggal, LEFT(sqle, 300) as preview 
                    FROM trackersql_archive 
                    ORDER BY tanggal DESC 
                    LIMIT 5
                ")->fetchAll();
                
                foreach ($samples as $s) {
                    echo "<div class='result-box'>";
                    echo "<div class='meta'>📅 " . $s['tanggal'] . "</div>";
                    echo "<div class='sql-preview'>" . htmlspecialchars($s['preview']) . "...</div>";
                    echo "</div>";
                }
            } catch (Exception $e) {
                echo "<div class='error'>Gagal ambil sample: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
        ?>
        
        <div style="margin-top: 30px;">
            <a href="index.php" class="btn" style="background: #334155;">← Kembali ke Dashboard</a>
        </div>
    </div>
</body>
</html>