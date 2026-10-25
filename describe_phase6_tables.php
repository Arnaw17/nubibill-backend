<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=nubibilling', 'root', 'Arnaw@2026');
$tables = ['employees', 'roles', 'permissions', 'staff_attendance'];

foreach ($tables as $t) {
    echo "=== Table: $t ===\n";
    $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Key']}\n";
    }
    $count = $pdo->query("SELECT count(*) FROM `$t`")->fetchColumn();
    echo "Count: $count rows\n";
    if ($count > 0) {
        $sample = $pdo->query("SELECT * FROM `$t` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo "Sample ID: " . $sample['id'] . "\n";
        echo "Sample payload: " . substr($sample['payload'], 0, 300) . "...\n";
    }
    echo "\n";
}
