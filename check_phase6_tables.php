<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=nubibilling', 'root', 'Arnaw@2026');
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='nubibilling' AND (
    table_name LIKE '%employee%' OR
    table_name LIKE '%staff%' OR
    table_name LIKE '%attendance%' OR
    table_name LIKE '%salary%' OR
    table_name LIKE '%payroll%' OR
    table_name LIKE '%role%' OR
    table_name LIKE '%permission%' OR
    table_name LIKE '%biometric%'
)");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($tables, JSON_PRETTY_PRINT);
