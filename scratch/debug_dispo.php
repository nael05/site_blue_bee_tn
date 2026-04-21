<?php
require_once 'config.php';
require_once 'functions_ordering.php';

date_default_timezone_set('Europe/Paris');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Heure actuelle (PHP) : " . date('Y-m-d H:i:s') . "\n";
    
    // Simulation d'un panier de 20 min
    $temps = 20;
    
    echo "--- Test ASAP ---\n";
    $dispo_asap = trouverDisponibilite($temps, 'asap', '', $pdo);
    print_r($dispo_asap);
    
    echo "--- Test Planifié (dans 1h) ---\n";
    $heure_cible = date('H:i', strtotime('+1 hour'));
    $dispo_plan = trouverDisponibilite($temps, 'scheduled', $heure_cible, $pdo);
    echo "Cible : $heure_cible\n";
    print_r($dispo_plan);

} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}
