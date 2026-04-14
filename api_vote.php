<?php
require_once 'config.php';
header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erreur de connexion']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'results') {
    $dateLundi = date('Y-m-d', strtotime('monday this week'));
    $dateJeudi = date('Y-m-d', strtotime('thursday this week'));
    $today = date('Y-m-d');
    
    // On ne compte que les votes d'aujourd'hui pour le menu du jour
    $stmt = $pdo->prepare("SELECT plat_index, COUNT(*) as count FROM votes_menu WHERE vote_date = ? GROUP BY plat_index");
    $stmt->execute([$today]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $counts = [1 => 0, 2 => 0, 3 => 0];
    $total = 0;
    foreach ($results as $r) {
        $counts[$r['plat_index']] = (int)$r['count'];
        $total += (int)$r['count'];
    }
    
    $percentages = [];
    foreach ($counts as $index => $count) {
        $percentages[$index] = $total > 0 ? round(($count / $total) * 100) : 0;
    }
    
    echo json_encode(['total' => $total, 'percentages' => $percentages]);
    exit;
}

if ($action === 'vote') {
    // Vérification du jour (Mon-Thu only)
    $num_jour = (int)date('N');
    if ($num_jour < 1 || $num_jour > 4) {
        echo json_encode(['error' => 'Le système de vote est fermé pour le week-end.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $nom = $data['firstName'] ?? '';
    $tel = $data['phone'] ?? '';
    $choix = (int)($data['choice'] ?? 0);
    $today = date('Y-m-d');

    if (!$nom || !$tel || !$choix) {
        echo json_encode(['error' => 'Champs manquants']);
        exit;
    }

    // Vérifier si déjà voté aujourd'hui
    $stmt = $pdo->prepare("SELECT id FROM votes_menu WHERE phone_number = ? AND vote_date = ?");
    $stmt->execute([$tel, $today]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Vous avez déjà voté pour aujourd\'hui !']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO votes_menu (phone_number, first_name, plat_index, vote_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tel, $nom, $choix, $today]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erreur lors de l\'enregistrement du vote']);
    }
    exit;
}

echo json_encode(['error' => 'Action invalide']);
