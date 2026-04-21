<?php
require 'config.php';
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $stmt = $pdo->query("SELECT * FROM commandes_settings");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    $stmt2 = $pdo->query("SELECT nom, temps_prep_min FROM carte_restaurant");
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
