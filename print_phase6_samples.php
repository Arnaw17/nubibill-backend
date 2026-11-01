<?php

$pdo = new PDO('mysql:host=127.0.0.1;dbname=nubibilling', 'root', 'Arnaw@2026');
echo "=== Sample Employee ===\n";
$emp = $pdo->query('SELECT payload FROM employees LIMIT 1')->fetchColumn();
echo json_encode(json_decode($emp, true), JSON_PRETTY_PRINT)."\n\n";

echo "=== Sample Attendance ===\n";
$att = $pdo->query('SELECT payload FROM staff_attendance LIMIT 1')->fetchColumn();
echo json_encode(json_decode($att, true), JSON_PRETTY_PRINT)."\n\n";

echo "=== Sample Role ===\n";
$role = $pdo->query('SELECT payload FROM roles LIMIT 1')->fetchColumn();
echo json_encode(json_decode($role, true), JSON_PRETTY_PRINT)."\n\n";
