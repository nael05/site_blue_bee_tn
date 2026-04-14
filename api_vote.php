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
    // On calcule la plage du lundi au jeudi de la semaine en cours
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $jeudi = date('Y-m-d', strtotime('thursday this week'));
    
    // On compte les votes cumulés par plat_index pour ce cycle
    $stmt = $pdo->prepare("SELECT plat_index, COUNT(*) as count FROM votes_menu WHERE (vote_date BETWEEN ? AND ?) AND plat_index IS NOT NULL GROUP BY plat_index");
    $stmt->execute([$lundi, $jeudi]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $counts = [];
    $total = 0;
    foreach ($results as $r) {
        $counts[$r['plat_index']] = (int)$r['count'];
        $total += (int)$r['count'];
    }
    
    $percentages = [];
    foreach ($counts as $id => $count) {
        $percentages[$id] = $total > 0 ? round(($count / $total) * 100) : 0;
    }
    
    echo json_encode(['total' => $total, 'percentages' => $percentages]);
    exit;
}

if ($action === 'vote') {
    // Vérification du jour (Mon-Thu only)
    $num_jour = (int)date('N');
    if ($num_jour < 1 || $num_jour > 4) {
        echo json_encode(['error' => 'Le système de vote est fermé. Revenez lundi !']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $nom = $data['firstName'] ?? '';
    $tel = $data['phone'] ?? '';
    $choix_id = (int)($data['choice'] ?? 0);
    $today = date('Y-m-d');
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $jeudi = date('Y-m-d', strtotime('thursday this week'));

    if (!$nom || !$tel || !$choix_id) {
        echo json_encode(['error' => 'Champs manquants']);
        exit;
    }

    // Vérifier si déjà voté dans ce CYCLE hebdomadaire
    $stmt = $pdo->prepare("SELECT id FROM votes_menu WHERE phone_number = ? AND (vote_date BETWEEN ? AND ?)");
    $stmt->execute([$tel, $lundi, $jeudi]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Vous avez déjà voté pour cette semaine !']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO votes_menu (phone_number, first_name, plat_index, vote_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tel, $nom, $choix_id, $today]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erreur lors de l\'enregistrement']);
    }
    exit;
}

echo json_encode(['error' => 'Action invalide']);
