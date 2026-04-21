<?php
require_once 'config.php';
$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
$pdo->exec("ALTER TABLE commandes ADD COLUMN piste_id INT DEFAULT 1");
echo "piste_id ajouté";
