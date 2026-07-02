<?php
/**
 * index.php
 * Dashboard monitoring Tracker SQL - FINAL FIXED + PAGINATION
 * Fitur: Summary + Search Aktif + Search Archive (with Pagination) + Auto Refresh
 * PHP 7.1+ Compatible
 */

require_once 'config.php';
require_once 'update_summary_auto.php';

set_time_limit(120);
ini_set('memory_limit', '256M');

$pdo = getDBConnection();
$startTime = microtime(true);

// ============================================
// INISIALISASI SEMUA VARIABEL
// ============================================
$updateResult = array('status' => 'ok', 'message' => 'No update');
$summaryMinDate = null;
$summaryMaxDate = null;
$archiveMinDate = null;
$archiveMaxDate = null;
$defaultDateFrom = date('Y-m-d');
$defaultDateTo = date('Y-m-d');
$totalAll = 0;
$mainCount = 0;
$archiveCount = 0;
$stats = array(
    'total_queries' => 0,
    'selects' => 0,
    'inserts' => 0,
    'updates' => 0,
    'deletes' => 0,
    'unique_users' => 0
);
$topUsersList = array();
$trendData = array();
$searchResults = array();
$searchCount = 0;
$searchTime = 0;
$searchArchiveResults = array();
$searchArchiveCount = 0;
$searchArchiveTime = 0;
$searchArchiveDebug = '';
$archivePage = 1;
$archivePerPage = 100;
$archiveHasMore = false;
$archiveOffset = 0;
$detailRows = array();
$detailCount = 0;

// ============================================
// AUTO UPDATE SUMMARY
// ============================================
try {
    $updateResult = autoUpdateSummary($pdo);
} catch (Exception $e) {
    $updateResult = array('status' => 'error', 'message' => $e->getMessage());
}

// ============================================
// DETECT TANGGAL DARI SUMMARY
// ============================================
try {
    $summaryMinDate = $pdo->query("SELECT MIN(tanggal) FROM " . SUMMARY_TABLE)->fetchColumn();
    $summaryMaxDate = $pdo->query("SELECT MAX(tanggal) FROM " . SUMMARY_TABLE)->fetchColumn();

    if (!empty($summaryMinDate)) {
        $defaultDateFrom = $summaryMinDate;
        $defaultDateTo = $summaryMaxDate;
    }
} catch (Exception $e) {
    // Silent fail
}

// ============================================
// DETECT TANGGAL DARI ARCHIVE
// ============================================
try {
    $archiveMinDate = $pdo->query("SELECT MIN(tanggal) FROM " . ARCHIVE_TABLE)->fetchColumn();
    $archiveMaxDate = $pdo->query("SELECT MAX(tanggal) FROM " . ARCHIVE_TABLE)->fetchColumn();
} catch (Exception $e) {
    $archiveMinDate = null;
    $archiveMaxDate = null;
}

// ============================================
// PARAMETER
// ============================================
$filterUser = isset($_GET['usere']) ? trim($_GET['usere']) : '';
$filterSql  = isset($_GET['sqle']) ? trim($_GET['sqle']) : '';
$dateFrom   = isset($_GET['date_from']) ? $_GET['date_from'] : $defaultDateFrom;
$dateTo     = isset($_GET['date_to']) ? $_GET['date_to'] : $defaultDateTo;
$page       = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage    = min((int)(isset($_GET['per_page']) ? $_GET['per_page'] : 50), 200);
$viewMode   = isset($_GET['view']) ? $_GET['view'] : 'summary';
$autoRefresh = isset($_GET['auto_refresh']) ? (int)$_GET['auto_refresh'] : 0;

// Parameter Search Tabel Aktif
$searchSql = isset($_GET['search_sql']) ? trim($_GET['search_sql']) : '';
$searchDateFrom = isset($_GET['search_date_from']) ? $_GET['search_date_from'] : date('Y-m-d');
$searchDateTo = isset($_GET['search_date_to']) ? $_GET['search_date_to'] : date('Y-m-d');

// Parameter Search Tabel Archive (FIXED - auto-detect range + pagination)
$searchArchiveSql = isset($_GET['search_archive_sql']) ? trim($_GET['search_archive_sql']) : '';
$searchArchiveDateFrom = isset($_GET['search_archive_date_from']) 
    ? $_GET['search_archive_date_from'] 
    : ($archiveMaxDate ? date('Y-m-d', strtotime($archiveMaxDate . ' -1 month')) : date('Y-m-d', strtotime('-5 years')));
$searchArchiveDateTo = isset($_GET['search_archive_date_to']) 
    ? $_GET['search_archive_date_to'] 
    : ($archiveMaxDate ? date('Y-m-d', strtotime($archiveMaxDate)) : date('Y-m-d'));
$archivePage = max(1, (int)(isset($_GET['archive_page']) ? $_GET['archive_page'] : 1));
$archivePerPage = 100;
$archiveOffset = ($archivePage - 1) * $archivePerPage;

$offset = ($page - 1) * $perPage;

// ============================================
// STATISTIK
// ============================================
try {
    $mainCount = (int)$pdo->query("
        SELECT table_rows FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' AND table_name = '" . TRACKER_TABLE . "'
    ")->fetchColumn();

    $archiveCount = (int)$pdo->query("
        SELECT table_rows FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "' AND table_name = '" . ARCHIVE_TABLE . "'
    ")->fetchColumn();

    $totalAll = $mainCount + $archiveCount;

} catch (Exception $e) {
    try {
        $mainCount = (int)$pdo->query("SELECT COUNT(*) FROM " . TRACKER_TABLE)->fetchColumn();
        $archiveCount = (int)$pdo->query("SELECT COUNT(*) FROM " . ARCHIVE_TABLE)->fetchColumn();
        $totalAll = $mainCount + $archiveCount;
    } catch (Exception $e2) {
        $totalAll = 55845789;
    }
}

// Summary stats
try {
    $summaryStats = $pdo->prepare("
        SELECT 
            SUM(total_queries) as total_queries,
            SUM(select_count) as selects,
            SUM(insert_count) as inserts,
            SUM(update_count) as updates,
            SUM(delete_count) as deletes,
            COUNT(DISTINCT usere) as unique_users
        FROM " . SUMMARY_TABLE . "
        WHERE tanggal BETWEEN ? AND ?
    ");
    $summaryStats->execute([$dateFrom, $dateTo]);
    $stats = $summaryStats->fetch();

    if (!$stats) {
        $stats = array('total_queries' => 0, 'selects' => 0, 'inserts' => 0, 'updates' => 0, 'deletes' => 0, 'unique_users' => 0);
    }
} catch (Exception $e) {
    // Silent fail
}

// Top users
try {
    $topUsers = $pdo->prepare("
        SELECT usere, SUM(total_queries) as total
        FROM " . SUMMARY_TABLE . "
        WHERE tanggal BETWEEN ? AND ?
        GROUP BY usere
        ORDER BY total DESC
        LIMIT 10
    ");
    $topUsers->execute([$dateFrom, $dateTo]);
    $topUsersList = $topUsers->fetchAll();
} catch (Exception $e) {
    $topUsersList = array();
}

// Trend harian
try {
    $trend = $pdo->prepare("
        SELECT tanggal, SUM(total_queries) as total
        FROM " . SUMMARY_TABLE . "
        WHERE tanggal BETWEEN ? AND ?
        GROUP BY tanggal
        ORDER BY tanggal
    ");
    $trend->execute([$dateFrom, $dateTo]);
    $trendData = $trend->fetchAll();
} catch (Exception $e) {
    $trendData = array();
}

// ============================================
// SEARCH TABEL AKTIF
// ============================================
if (!empty($searchSql)) {
    $searchStart = microtime(true);

    try {
        $sql = "SELECT tanggal, sqle, usere 
                FROM " . TRACKER_TABLE . "
                WHERE tanggal BETWEEN :dateFrom AND :dateTo
                AND sqle LIKE :search
                ORDER BY tanggal DESC 
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':dateFrom', $searchDateFrom . ' 00:00:00');
        $stmt->bindValue(':dateTo', $searchDateTo . ' 23:59:59');
        $stmt->bindValue(':search', '%' . $searchSql . '%');
        $stmt->execute();

        $searchResults = $stmt->fetchAll();
        $searchCount = count($searchResults);
        $searchTime = round(microtime(true) - $searchStart, 3);

    } catch (Exception $e) {
        $searchCount = 0;
        $searchTime = 0;
    }
}

// ============================================
// SEARCH TABEL ARCHIVE (FIXED + PAGINATION)
// ============================================
if (!empty($searchArchiveSql)) {
    $searchStart = microtime(true);

    try {
        $keyword = trim($searchArchiveSql);
        $variations = array($keyword);

        if (strpos($keyword, '/') !== false) {
            $variations[] = str_replace('/', '', $keyword);
            $variations[] = str_replace('/', '-', $keyword);
        }
        if (strpos($keyword, ' ') !== false) {
            $variations[] = str_replace(' ', '', $keyword);
        }

        $whereConditions = array();
        $bindParams = array();

        foreach ($variations as $i => $var) {
            $whereConditions[] = "sqle LIKE :search" . $i;
            $bindParams[':search' . $i] = '%' . $var . '%';
        }

        $whereSql = implode(' OR ', $whereConditions);

        // Query data page ini
        $sql = "SELECT tanggal, sqle, usere 
                FROM " . ARCHIVE_TABLE . "
                WHERE tanggal BETWEEN :dateFrom AND :dateTo
                AND (" . $whereSql . ")
                ORDER BY tanggal DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);

        $bindParams[':dateFrom'] = $searchArchiveDateFrom . ' 00:00:00';
        $bindParams[':dateTo'] = $searchArchiveDateTo . ' 23:59:59';
        $bindParams[':limit'] = $archivePerPage;
        $bindParams[':offset'] = $archiveOffset;

        $stmt->execute($bindParams);

        $searchArchiveResults = $stmt->fetchAll();
        $searchArchiveCount = count($searchArchiveResults);
        $searchArchiveTime = round(microtime(true) - $searchStart, 3);

        // Cek apakah ada page berikutnya (tanpa COUNT total)
        $archiveHasMore = false;
        if ($searchArchiveCount > 0) {
            $checkSql = "SELECT 1 
                        FROM " . ARCHIVE_TABLE . "
                        WHERE tanggal BETWEEN :dateFrom AND :dateTo
                        AND (" . $whereSql . ")
                        ORDER BY tanggal DESC 
                        LIMIT 1 OFFSET :offset";

            $checkStmt = $pdo->prepare($checkSql);
            $checkBindParams = array(
                ':dateFrom' => $searchArchiveDateFrom . ' 00:00:00',
                ':dateTo' => $searchArchiveDateTo . ' 23:59:59',
                ':offset' => $archiveOffset + $archivePerPage
            );
            foreach ($variations as $i => $var) {
                $checkBindParams[':search' . $i] = '%' . $var . '%';
            }

            $checkStmt->execute($checkBindParams);
            $archiveHasMore = $checkStmt->fetch() !== false;
        }

        $searchArchiveDebug = "Variasi: " . implode(', ', array_slice($variations, 0, 3)) . 
                              " | Page: " . $archivePage . " | Offset: " . $archiveOffset;

    } catch (Exception $e) {
        $searchArchiveCount = 0;
        $searchArchiveTime = 0;
        $archiveHasMore = false;
        $searchArchiveDebug = "Error: " . $e->getMessage();
    }
}

// ============================================
// DATA DETAIL (1 JAM TERAKHIR)
// ============================================
if ($viewMode === 'detail') {
    try {
        $sql = "SELECT tanggal, sqle, usere 
                FROM " . TRACKER_TABLE . "
                WHERE tanggal >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $params = array();

        if (!empty($filterUser)) {
            $sql .= " AND usere = :usere";
            $params[':usere'] = $filterUser;
        }
        if (!empty($filterSql)) {
            $sql .= " AND sqle LIKE :sqle";
            $params[':sqle'] = '%' . $filterSql . '%';
        }

        $sql .= " ORDER BY tanggal DESC LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->execute($params);
        $detailRows = $stmt->fetchAll();
        $detailCount = count($detailRows);

    } catch (Exception $e) {
        $detailRows = array();
        $detailCount = 0;
    }
}

// ============================================
// FUNGSI HELPER
// ============================================
function getSqlType($sql) {
    $sql = strtoupper(trim($sql));
    if (strpos($sql, 'SELECT') === 0) return array('SELECT', '#4CAF50');
    if (strpos($sql, 'INSERT') === 0) return array('INSERT', '#2196F3');
    if (strpos($sql, 'UPDATE') === 0) return array('UPDATE', '#FF9800');
    if (strpos($sql, 'DELETE') === 0) return array('DELETE', '#f44336');
    return array('OTHER', '#9C27B0');
}

function formatChartDate($d) {
    return date('d M', strtotime($d['tanggal']));
}

function getChartTotal($d) {
    return $d['total'];
}

function highlightText($text, $search) {
    if (empty($search)) return $text;

    $variations = array($search);
    if (strpos($search, '/') !== false) {
        $variations[] = str_replace('/', '', $search);
        $variations[] = str_replace('/', '-', $search);
    }

    foreach ($variations as $keyword) {
        $keyword = htmlspecialchars($keyword);
        $text = str_ireplace(
            $keyword,
            '<mark style="background:#fbbf24; color:#0f172a; padding:2px 4px; border-radius:3px; font-weight:700;">' . $keyword . '</mark>',
            $text
        );
    }
    return $text;
}

// Helper: Build URL dengan parameter archive_page
function buildArchivePageUrl($pageNum, $currentParams) {
    $params = $currentParams;
    $params['archive_page'] = $pageNum;
    return '?' . http_build_query($params);
}

$loadTime = round(microtime(true) - $startTime, 3);

$queryParams = $_GET;
$queryParams['auto_refresh'] = $autoRefresh;
$currentUrl = '?' . http_build_query($queryParams);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tracker SQL Monitor - Final + Pagination</title>

    <?php if ($autoRefresh > 0 && empty($searchSql) && empty($searchArchiveSql)): ?>
    <meta http-equiv="refresh" content="<?php echo $autoRefresh; ?>">
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }

        .auto-status {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 8px 20px;
            font-size: 12px;
            text-align: center;
            z-index: 1000;
            transition: all 0.3s;
        }
        .auto-status.updated { background: #064e3b; color: #34d399; }
        .auto-status.ok { background: #1e3a5f; color: #60a5fa; }
        .auto-status.error { background: #450a0a; color: #f87171; }
        .auto-status.running { background: #451a03; color: #fbbf24; }

        h1 { font-size: 24px; margin: 40px 0 8px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-green { background: #064e3b; color: #34d399; }
        .badge-blue { background: #1e3a5f; color: #60a5fa; }
        .badge-orange { background: #451a03; color: #fbbf24; }
        .badge-red { background: #450a0a; color: #f87171; }
        .badge-purple { background: #3b0764; color: #c084fc; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { 
            background: linear-gradient(135deg, #1e293b, #0f172a); 
            border: 1px solid #334155;
            border-radius: 12px; 
            padding: 20px;
            transition: all 0.3s;
        }
        .stat-card:hover { border-color: #475569; transform: translateY(-2px); }
        .stat-card .label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-card .value { font-size: 28px; font-weight: 700; color: #f8fafc; }
        .stat-card .sub { font-size: 11px; color: #64748b; margin-top: 4px; }
        .stat-card.total .value { color: #34d399; }
        .stat-card.select .value { color: #60a5fa; }
        .stat-card.insert .value { color: #a78bfa; }
        .stat-card.update .value { color: #fbbf24; }
        .stat-card.delete .value { color: #f87171; }
        .stat-card.archive .value { color: #c084fc; }
        .stat-card.active .value { color: #38bdf8; }

        .main-grid { display: grid; grid-template-columns: 1fr 350px; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 1100px) { .main-grid { grid-template-columns: 1fr; } }

        .panel { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
        .panel-search { border-color: #3b82f6; }
        .panel-archive { border-color: #c084fc; }
        .panel-header { padding: 16px 20px; border-bottom: 1px solid #334155; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .panel-search .panel-header { background: #1e3a5f; color: #60a5fa; border-bottom-color: #3b82f6; }
        .panel-archive .panel-header { background: #3b0764; color: #c084fc; border-bottom-color: #c084fc; }
        .panel-body { padding: 20px; }

        .chart-container { height: 300px; position: relative; }

        .user-list { display: flex; flex-direction: column; gap: 10px; }
        .user-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #0f172a; border-radius: 8px; }
        .user-rank { color: #475569; font-weight: 700; width: 30px; }
        .user-name { font-weight: 600; color: #94a3b8; flex: 1; }
        .user-count { font-family: monospace; font-size: 14px; color: #34d399; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600; }
        .filter-group input, .filter-group select { 
            background: #0f172a; border: 1px solid #334155; color: #e2e8f0;
            padding: 8px 12px; border-radius: 8px; font-size: 13px; min-width: 160px;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #3b82f6; }
        .btn { padding: 8px 20px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #334155; color: #e2e8f0; }
        .btn-secondary:hover { background: #475569; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: #0f172a; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: #f87171; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn.active { box-shadow: 0 0 0 2px #3b82f6; }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .detail-section { margin-top: 0; }
        .detail-alert { background: #451a03; border: 1px solid #92400e; color: #fbbf24; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .search-info { background: #064e3b; border: 1px solid #10b981; color: #34d399; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin: 15px 0; }
        .archive-info { background: #3b0764; border: 1px solid #c084fc; color: #c084fc; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; }
        .search-error { background: #450a0a; border: 1px solid #f87171; color: #f87171; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin: 15px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #0f172a; color: #94a3b8; padding: 12px 16px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 10px 16px; border-bottom: 1px solid #334155; }
        tr:hover td { background: #252f47; }
        .sql-text { font-family: 'SF Mono', monospace; color: #cbd5e1; max-width: 600px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sql-text:hover { white-space: normal; word-break: break-all; }
        .user-tag { background: #1e3a5f; color: #60a5fa; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .type-tag { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }

        .footer { text-align: center; padding: 20px; color: #475569; font-size: 12px; }
        .load-time { color: #34d399; font-family: monospace; }

        .manual-update { 
            background: #1e293b; 
            border: 1px dashed #475569; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .manual-update .info { font-size: 13px; color: #94a3b8; }

        .refresh-controls { display: flex; gap: 8px; align-items: center; }
        .refresh-label { font-size: 12px; color: #94a3b8; }

        .empty-alert { background: #451a03; border: 1px solid #92400e; color: #fbbf24; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .empty-alert a { color: #60a5fa; text-decoration: underline; }

        mark { background: #fbbf24; color: #0f172a; padding: 2px 4px; border-radius: 3px; font-weight: 700; }

        .search-warning { 
            background: #451a03; 
            border: 1px solid #92400e; 
            color: #fbbf24; 
            padding: 12px 16px; 
            border-radius: 8px; 
            font-size: 13px; 
            margin-bottom: 15px; 
        }
        .search-warning strong { color: #f87171; }

        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #334155;
        }
        .pagination-info {
            font-size: 13px;
            color: #94a3b8;
            margin-right: 15px;
        }
        .pagination .btn {
            min-width: 40px;
            text-align: center;
            padding: 6px 12px;
        }
        .pagination .btn.active {
            background: #c084fc;
            color: #0f172a;
            box-shadow: none;
        }
        .pagination .btn-nav {
            background: #334155;
        }
        .pagination .btn-nav:hover {
            background: #475569;
        }
        .pagination .btn-nav:disabled {
            background: #1e293b;
            color: #475569;
            cursor: not-allowed;
        }
        .page-jump {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 15px;
        }
        .page-jump input {
            width: 60px;
            text-align: center;
            background: #0f172a;
            border: 1px solid #334155;
            color: #e2e8f0;
            padding: 6px;
            border-radius: 6px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="auto-status <?php echo $updateResult['status']; ?>">
        🔄 Auto-Update: <?php echo $updateResult['message']; ?> 
        <?php echo isset($updateResult['reason']) ? '(' . $updateResult['reason'] . ')' : ''; ?>
    </div>

    <div class="container">
        <h1>
            📊 Tracker SQL Monitor
            <span class="badge badge-green">Final</span>
            <span class="badge badge-blue"><?php echo number_format($totalAll / 1000000, 1); ?>JT Total</span>
            <?php if (!empty($searchSql)): ?>
            <span class="badge badge-red">Search Aktif</span>
            <?php elseif (!empty($searchArchiveSql)): ?>
            <span class="badge badge-purple">Search Archive</span>
            <?php endif; ?>
        </h1>
        <p class="subtitle">Summary + Search Aktif + Search Archive (with Pagination) — Auto-refresh & Auto-update</p>

        <?php if (empty($summaryMinDate)): ?>
        <div class="empty-alert">
            ⚠️ <strong>Data summary masih kosong!</strong><br>
            Silakan jalankan <a href="update_summary_web.php">Backfill Manual</a> terlebih dahulu.
        </div>
        <?php endif; ?>

        <!-- Controls -->
        <div class="manual-update">
            <div class="info">
                📅 Summary: <strong><?php echo $summaryMinDate ?: '-'; ?></strong> s/d <strong><?php echo $summaryMaxDate ?: '-'; ?></strong> | 
                📅 Archive: <strong><?php echo $archiveMinDate ? date('d M Y', strtotime($archiveMinDate)) : '-'; ?></strong> s/d <strong><?php echo $archiveMaxDate ? date('d M Y', strtotime($archiveMaxDate)) : '-'; ?></strong> |
                ⏱️ Load: <span class="load-time"><?php echo $loadTime; ?>s</span>
                <?php if (!empty($searchSql)): ?>
                | 🔍 Search Aktif: <span class="load-time"><?php echo $searchTime; ?>s</span>
                <?php elseif (!empty($searchArchiveSql)): ?>
                | 🗄️ Search Archive: <span class="load-time"><?php echo $searchArchiveTime; ?>s</span>
                <?php endif; ?>
            </div>
            <div class="refresh-controls">
                <span class="refresh-label">Auto Refresh:</span>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('auto_refresh' => 30))); ?>" 
                   class="btn btn-secondary <?php echo $autoRefresh == 30 ? 'active' : ''; ?>">30s</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('auto_refresh' => 60))); ?>" 
                   class="btn btn-secondary <?php echo $autoRefresh == 60 ? 'active' : ''; ?>">1m</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('auto_refresh' => 300))); ?>" 
                   class="btn btn-secondary <?php echo $autoRefresh == 300 ? 'active' : ''; ?>">5m</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, array('auto_refresh' => 0))); ?>" 
                   class="btn btn-secondary <?php echo $autoRefresh == 0 ? 'active' : ''; ?>">Off</a>
                <a href="update_summary_web.php" class="btn btn-success">🔄 Backfill</a>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- INFO ARCHIVE -->
        <!-- ============================================ -->
        <div class="panel panel-archive">
            <div class="panel-header">
                🗄️ Status Data
            </div>
            <div class="panel-body">
                <div class="archive-info">
                    📊 <strong>trackersql</strong> (aktif): <?php echo number_format($mainCount); ?> record | 
                    🗄️ <strong>trackersql_archive</strong>: <?php echo number_format($archiveCount); ?> record<br>
                    📅 <strong>Archive range:</strong> 
                    <?php echo $archiveMinDate ? date('d M Y', strtotime($archiveMinDate)) : '-'; ?> 
                    s/d 
                    <?php echo $archiveMaxDate ? date('d M Y', strtotime($archiveMaxDate)) : '-'; ?><br>
                    ⏳ Auto-archive: data ><?php echo ARCHIVE_KEEP_MONTHS; ?> bulan otomatis dipindah
                </div>
                <div style="display: flex; gap: 10px;">
                    <a href="archive_safe.php" class="btn btn-danger">🗄️ Archive Manual</a>
                    <span style="font-size: 12px; color: #64748b; align-self: center;">
                        Klik untuk memindah data lama sekarang
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SEARCH TABEL AKTIF -->
        <!-- ============================================ -->
        <div class="panel panel-search">
            <div class="panel-header">
                🔍 Cari SQL Query di Tabel Aktif (<?php echo number_format($mainCount); ?> record)
            </div>
            <div class="panel-body">
                <div class="search-warning">
                    ⚠️ <strong>PERINGATAN:</strong> Search membaca langsung dari tabel aktif.
                    Wajib batasi rentang tanggal maksimal <strong>1-3 hari</strong> agar tidak timeout!
                </div>

                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label>Kata Kunci SQL *</label>
                        <input type="text" name="search_sql" 
                               placeholder="Contoh: DELETE, UPDATE, nama_tabel..." 
                               value="<?php echo htmlspecialchars($searchSql); ?>" 
                               style="border-color: #3b82f6; min-width: 250px;" required>
                    </div>
                    <div class="filter-group">
                        <label>Dari Tanggal *</label>
                        <input type="date" name="search_date_from" 
                               value="<?php echo $searchDateFrom; ?>" required>
                    </div>
                    <div class="filter-group">
                        <label>Sampai Tanggal *</label>
                        <input type="date" name="search_date_to" 
                               value="<?php echo $searchDateTo; ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background: #3b82f6;">
                        🔍 Cari
                    </button>
                    <a href="?" class="btn btn-secondary">Reset All</a>
                </form>

                <?php if (!empty($searchSql)): ?>
                    <?php if ($searchCount > 0): ?>
                    <div class="search-info">
                        ✅ Ditemukan <strong><?php echo number_format($searchCount); ?></strong> record 
                        (<?php echo $searchTime; ?>s)
                    </div>
                    <?php else: ?>
                    <div class="search-error">
                        ❌ Tidak ditemukan dalam rentang <?php echo htmlspecialchars($searchDateFrom); ?> s/d <?php echo htmlspecialchars($searchDateTo); ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- HASIL SEARCH AKTIF -->
        <!-- ============================================ -->
        <?php if (!empty($searchResults)): ?>
        <div class="panel detail-section">
            <div class="panel-header">
                🔍 Hasil Pencarian Tabel Aktif: "<?php echo htmlspecialchars($searchSql); ?>"
                <span style="font-size:12px;color:#64748b;"><?php echo $searchCount; ?> record (max 100)</span>
            </div>
            <div class="panel-body">
                <table>
                    <thead>
                        <tr>
                            <th width="160">Tanggal</th>
                            <th width="100">User</th>
                            <th width="70">Tipe</th>
                            <th>SQL Query</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $row): 
                            list($type, $color) = getSqlType($row['sqle']);
                            $highlightedSql = highlightText(htmlspecialchars($row['sqle']), $searchSql);
                        ?>
                        <tr>
                            <td style="font-family:monospace; color:#94a3b8;"><?php echo $row['tanggal']; ?></td>
                            <td><span class="user-tag"><?php echo htmlspecialchars($row['usere']); ?></span></td>
                            <td><span class="type-tag" style="background:<?php echo $color; ?>20; color:<?php echo $color; ?>;"><?php echo $type; ?></span></td>
                            <td class="sql-text"><?php echo $highlightedSql; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif (!empty($searchSql)): ?>
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-body" style="text-align:center; padding:40px; color:#64748b;">
                ❌ Tidak ditemukan query yang mengandung <strong>"<?php echo htmlspecialchars($searchSql); ?>"</strong>
                dalam rentang <?php echo htmlspecialchars($searchDateFrom); ?> s/d <?php echo htmlspecialchars($searchDateTo); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- SEARCH TABEL ARCHIVE -->
        <!-- ============================================ -->
        <div class="panel panel-archive">
            <div class="panel-header">
                🗄️ Cari SQL Query di Tabel Archive (<?php echo number_format($archiveCount); ?> record)
            </div>
            <div class="panel-body">
                <div class="archive-info">
                    ℹ️ <strong>Archive</strong> berisi data lama (><?php echo ARCHIVE_KEEP_MONTHS; ?> bulan).
                    <strong style="color:#fbbf24;">Wajib batasi rentang tanggal</strong> agar tidak timeout!<br>
                    📅 <strong>Range data archive:</strong> 
                    <?php echo $archiveMinDate ? date('d M Y', strtotime($archiveMinDate)) : '-'; ?> 
                    s/d 
                    <?php echo $archiveMaxDate ? date('d M Y', strtotime($archiveMaxDate)) : '-'; ?><br>
                    Contoh nomor rawat: <code>2026/01/04/000</code> atau <code>20260104000</code>
                </div>

                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label>Kata Kunci SQL *</label>
                        <input type="text" name="search_archive_sql" 
                               placeholder="Contoh: 2026/01/04/000, 20260104000..." 
                               value="<?php echo htmlspecialchars($searchArchiveSql); ?>" 
                               style="border-color: #c084fc; min-width: 250px;" required>
                    </div>
                    <div class="filter-group">
                        <label>Dari Tanggal *</label>
                        <input type="date" name="search_archive_date_from" 
                               value="<?php echo $searchArchiveDateFrom; ?>" required>
                    </div>
                    <div class="filter-group">
                        <label>Sampai Tanggal *</label>
                        <input type="date" name="search_archive_date_to" 
                               value="<?php echo $searchArchiveDateTo; ?>" required>
                    </div>
                    <button type="submit" class="btn btn-warning">
                        🗄️ Cari Archive
                    </button>
                    <a href="?" class="btn btn-secondary">Reset All</a>
                </form>

                <?php if (!empty($searchArchiveSql)): ?>
                    <?php if ($searchArchiveCount > 0): ?>
                    <div class="search-info" style="background: #3b0764; border-color: #c084fc; color: #c084fc;">
                        ✅ Halaman <strong><?php echo number_format($archivePage); ?></strong> | 
                        Menampilkan <strong><?php echo number_format($archiveOffset + 1); ?> - <?php echo number_format($archiveOffset + $searchArchiveCount); ?></strong> record 
                        (<?php echo $searchArchiveTime; ?>s)
                        <?php if ($archiveHasMore): ?>
                        | Masih ada data berikutnya →
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="search-error">
                        ❌ Tidak ditemukan. Debug: <?php echo htmlspecialchars($searchArchiveDebug); ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- HASIL SEARCH ARCHIVE + PAGINATION -->
        <!-- ============================================ -->
        <?php if (!empty($searchArchiveResults)): ?>
        <div class="panel detail-section">
            <div class="panel-header" style="background: #3b0764; color: #c084fc; border-bottom-color: #c084fc;">
                🗄️ Hasil Pencarian Archive: "<?php echo htmlspecialchars($searchArchiveSql); ?>"
                <span style="font-size:12px;color:#a78bfa;">Halaman <?php echo $archivePage; ?> | <?php echo $searchArchiveCount; ?> record</span>
            </div>
            <div class="panel-body">
                <table>
                    <thead>
                        <tr style="background: #2e1065;">
                            <th width="160">Tanggal</th>
                            <th width="100">User</th>
                            <th width="70">Tipe</th>
                            <th>SQL Query</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchArchiveResults as $row): 
                            list($type, $color) = getSqlType($row['sqle']);
                            $highlightedSql = highlightText(htmlspecialchars($row['sqle']), $searchArchiveSql);
                        ?>
                        <tr>
                            <td style="font-family:monospace; color:#94a3b8;"><?php echo $row['tanggal']; ?></td>
                            <td><span class="user-tag"><?php echo htmlspecialchars($row['usere']); ?></span></td>
                            <td><span class="type-tag" style="background:<?php echo $color; ?>20; color:<?php echo $color; ?>;"><?php echo $type; ?></span></td>
                            <td class="sql-text"><?php echo $highlightedSql; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- PAGINATION CONTROLS -->
                <div class="pagination">
                    <span class="pagination-info">
                        📄 Halaman <strong><?php echo number_format($archivePage); ?></strong> | 
                        Record <strong><?php echo number_format($archiveOffset + 1); ?> - <?php echo number_format($archiveOffset + $searchArchiveCount); ?></strong>
                        <?php if ($archiveHasMore): ?>
                        <span style="color:#fbbf24;">(masih ada data)</span>
                        <?php else: ?>
                        <span style="color:#34d399;">(data terakhir)</span>
                        <?php endif; ?>
                    </span>

                    <?php 
                    $archiveQueryParams = $_GET;
                    unset($archiveQueryParams['archive_page']);
                    ?>

                    <!-- Tombol Sebelumnya -->
                    <?php if ($archivePage > 1): ?>
                    <a href="<?php echo buildArchivePageUrl($archivePage - 1, $archiveQueryParams); ?>" 
                       class="btn btn-nav">← Sebelumnya</a>
                    <?php else: ?>
                    <button class="btn btn-nav" disabled>← Sebelumnya</button>
                    <?php endif; ?>

                    <!-- Nomor Halaman (tampilkan 5 page sekitar current) -->
                    <?php 
                    $startPage = max(1, $archivePage - 2);
                    $endPage = $archivePage + 2;
                    for ($p = $startPage; $p <= $endPage; $p++): 
                        if ($p == $archivePage):
                    ?>
                        <span class="btn active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo buildArchivePageUrl($p, $archiveQueryParams); ?>" 
                           class="btn btn-secondary"><?php echo $p; ?></a>
                    <?php 
                        endif;
                    endfor; 
                    ?>

                    <!-- Tombol Berikutnya -->
                    <?php if ($archiveHasMore): ?>
                    <a href="<?php echo buildArchivePageUrl($archivePage + 1, $archiveQueryParams); ?>" 
                       class="btn btn-nav">Berikutnya →</a>
                    <?php else: ?>
                    <button class="btn btn-nav" disabled>Berikutnya →</button>
                    <?php endif; ?>

                    <!-- Jump to page -->
                    <form method="GET" class="page-jump" style="margin-left: 15px;">
                        <?php foreach ($_GET as $key => $val): ?>
                            <?php if ($key !== 'archive_page'): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <span style="font-size: 12px; color: #94a3b8;">Ke halaman:</span>
                        <input type="number" name="archive_page" min="1" value="" placeholder="<?php echo $archivePage + 1; ?>" style="width: 60px;">
                        <button type="submit" class="btn btn-secondary" style="padding: 6px 12px;">Go</button>
                    </form>
                </div>
            </div>
        </div>
        <?php elseif (!empty($searchArchiveSql)): ?>
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-body" style="text-align:center; padding:40px; color:#64748b;">
                ❌ Tidak ditemukan query yang mengandung <strong>"<?php echo htmlspecialchars($searchArchiveSql); ?>"</strong>
                dalam rentang <?php echo htmlspecialchars($searchArchiveDateFrom); ?> s/d <?php echo htmlspecialchars($searchArchiveDateTo); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- SUMMARY DASHBOARD (Tampil jika tidak search) -->
        <!-- ============================================ -->
        <?php if (empty($searchSql) && empty($searchArchiveSql)): ?>

        <!-- Filter Summary -->
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-body">
                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label>Dari Tanggal</label>
                        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Sampai Tanggal</label>
                        <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
                    </div>
                    <div class="filter-group">
                        <label>User</label>
                        <input type="text" name="usere" placeholder="Filter user..." value="<?php echo htmlspecialchars($filterUser); ?>">
                    </div>
                    <div class="filter-group">
                        <label>View</label>
                        <select name="view">
                            <option value="summary" <?php echo $viewMode == 'summary' ? 'selected' : ''; ?>>Summary Only</option>
                            <option value="detail" <?php echo $viewMode == 'detail' ? 'selected' : ''; ?>>+ Detail (1 jam)</option>
                        </select>
                    </div>
                    <input type="hidden" name="auto_refresh" value="<?php echo $autoRefresh; ?>">
                    <button type="submit" class="btn btn-primary">🔍 Tampilkan</button>
                    <a href="?auto_refresh=<?php echo $autoRefresh; ?>" class="btn btn-secondary">Reset</a>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="label">Total Keseluruhan</div>
                <div class="value"><?php echo number_format($totalAll); ?></div>
                <div class="sub">aktif + archive</div>
            </div>
            <div class="stat-card active">
                <div class="label">Tabel Aktif</div>
                <div class="value"><?php echo number_format($mainCount); ?></div>
                <div class="sub"><?php echo ARCHIVE_KEEP_MONTHS; ?> bulan terakhir</div>
            </div>
            <div class="stat-card archive">
                <div class="label">Archive</div>
                <div class="value"><?php echo number_format($archiveCount); ?></div>
                <div class="sub">data lama</div>
            </div>
            <div class="stat-card select">
                <div class="label">SELECT</div>
                <div class="value"><?php echo number_format($stats['selects'] ?? 0); ?></div>
            </div>
            <div class="stat-card insert">
                <div class="label">INSERT</div>
                <div class="value"><?php echo number_format($stats['inserts'] ?? 0); ?></div>
            </div>
            <div class="stat-card update">
                <div class="label">UPDATE</div>
                <div class="value"><?php echo number_format($stats['updates'] ?? 0); ?></div>
            </div>
            <div class="stat-card delete">
                <div class="label">DELETE</div>
                <div class="value"><?php echo number_format($stats['deletes'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="label">User Unik</div>
                <div class="value"><?php echo number_format($stats['unique_users'] ?? 0); ?></div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Chart -->
            <div class="panel">
                <div class="panel-header">
                    <span>📈 Trend Query Harian</span>
                    <span style="font-size:12px;color:#64748b;"><?php echo count($trendData); ?> hari</span>
                </div>
                <div class="panel-body">
                    <?php if (empty($trendData)): ?>
                    <div style="text-align:center; padding:60px; color:#64748b;">
                        📊 Tidak ada data untuk ditampilkan<br>
                        <span style="font-size:12px;">Silakan jalankan backfill terlebih dahulu</span>
                    </div>
                    <?php else: ?>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Users -->
            <div class="panel">
                <div class="panel-header">👥 Top Users</div>
                <div class="panel-body">
                    <?php if (empty($topUsersList)): ?>
                    <div style="text-align:center; padding:40px; color:#64748b;">Belum ada data</div>
                    <?php else: ?>
                    <div class="user-list">
                        <?php foreach ($topUsersList as $i => $user): ?>
                        <div class="user-item">
                            <span class="user-rank">#<?php echo $i+1; ?></span>
                            <span class="user-name"><?php echo htmlspecialchars($user['usere']); ?></span>
                            <span class="user-count"><?php echo number_format($user['total']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Detail -->
        <?php if ($viewMode === 'detail'): ?>
        <div class="panel detail-section">
            <div class="panel-header">🔍 Detail Query Langsung (1 Jam Terakhir)</div>
            <div class="panel-body">
                <div class="detail-alert">
                    ⚠️ Mode detail membaca langsung dari <strong>trackersql</strong> (<?php echo number_format($mainCount); ?> record) 
                    tetapi dibatasi hanya <strong>1 jam terakhir</strong> untuk performa.
                </div>

                <table>
                    <thead>
                        <tr>
                            <th width="160">Tanggal</th>
                            <th width="100">User</th>
                            <th width="70">Tipe</th>
                            <th>SQL Query</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detailRows)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:40px; color:#64748b;">Tidak ada data dalam 1 jam terakhir</td></tr>
                        <?php else: ?>
                            <?php foreach ($detailRows as $row): 
                                list($type, $color) = getSqlType($row['sqle']);
                            ?>
                            <tr>
                                <td style="font-family:monospace; color:#94a3b8;"><?php echo $row['tanggal']; ?></td>
                                <td><span class="user-tag"><?php echo htmlspecialchars($row['usere']); ?></span></td>
                                <td><span class="type-tag" style="background:<?php echo $color; ?>20; color:<?php echo $color; ?>;"><?php echo $type; ?></span></td>
                                <td class="sql-text"><?php echo htmlspecialchars(substr($row['sqle'], 0, 120)) . (strlen($row['sqle']) > 120 ? '...' : ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end if empty search ?>

        <div class="footer">
            ⏱️ Load: <span class="load-time"><?php echo $loadTime; ?>s</span> | 
            Auto-update: <span class="load-time"><?php echo $updateResult['status']; ?></span> | 
            Auto-refresh: <span class="load-time"><?php echo $autoRefresh > 0 ? $autoRefresh . 's' : 'OFF'; ?></span> |
            Summary: <?php 
                try {
                    echo number_format($pdo->query("SELECT COUNT(*) FROM " . SUMMARY_TABLE)->fetchColumn());
                } catch (Exception $e) {
                    echo '0';
                }
            ?> rows |
            <a href="update_summary_web.php" style="color:#3b82f6;">Backfill</a> |
            <a href="archive_safe.php" style="color:#c084fc;">Archive</a>
        </div>
    </div>

    <script>
        <?php if (empty($searchSql) && empty($searchArchiveSql) && !empty($trendData)): ?>
        var ctx = document.getElementById('trendChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php 
                    $chartLabels = array();
                    foreach ($trendData as $d) {
                        $chartLabels[] = formatChartDate($d);
                    }
                    echo json_encode($chartLabels);
                ?>,
                datasets: [{
                    label: 'Total Queries',
                    data: <?php 
                        $chartData = array();
                        foreach ($trendData as $d) {
                            $chartData[] = getChartTotal($d);
                        }
                        echo json_encode($chartData);
                    ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: '#334155' }, ticks: { color: '#94a3b8' } },
                    y: { grid: { color: '#334155' }, ticks: { color: '#94a3b8' } }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
