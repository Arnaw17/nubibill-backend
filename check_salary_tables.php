<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=nubibilling', 'root', 'Arnaw@2026');
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='nubibilling' AND (table_name LIKE '%salary%' OR table_name LIKE '%payroll%' OR table_name LIKE '%khata%')");
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
