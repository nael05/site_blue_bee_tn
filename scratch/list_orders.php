<?php
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $orders = $pdo->query("SELECT id, client_nom, heure_retrait, heure_debut_prep, heure_fin_estimee, piste_id, statut FROM commandes WHERE DATE(date_commande) = CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($orders, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}
