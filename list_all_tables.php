<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=nubibilling', 'root', 'Arnaw@2026');
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='nubibilling'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($tables, JSON_PRETTY_PRINT);
