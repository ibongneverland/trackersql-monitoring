<?php
require_once 'config.php';
$pdo = getDBConnection();

// Test 1: Cek struktur tabel archive
$cols = $pdo->query("DESCRIBE " . ARCHIVE_TABLE)->fetchAll(PDO::FETCH_ASSOC);
echo "Struktur tabel " . ARCHIVE_TABLE . ":\n";
foreach ($cols as $c) {
    echo "  - {$c['Field']}: {$c['Type']}\n";
}

// Test 2: Cek sample data
$sample = $pdo->query("SELECT * FROM " . ARCHIVE_TABLE . " LIMIT 3")->fetchAll();
echo "\nSample data:\n";
print_r($sample);

// Test 3: Cek total data
$count = $pdo->query("SELECT COUNT(*) FROM " . ARCHIVE_TABLE)->fetchColumn();
echo "\nTotal: $count records\n";