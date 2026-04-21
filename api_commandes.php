<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'functions_ordering.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => "Erreur de connexion"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_full_availability' || $action === 'check_availability') {
    $panier = $input['panier'] ?? [];
    
    // 1. Récupérer les réglages
    $stmtSet = $pdo->query("SELECT s_key, s_value FROM commandes_settings");
    $settings = [];
    foreach ($stmtSet->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $settings[$s['s_key']] = $s['s_value'];
    }
    $reduction = (int)($settings['reduction_temps_doublon'] ?? 0);

    // 2. Récupérer les détails des articles pour le temps
    $panier_complet = [];
    foreach ($panier as $item) {
        $stmt = $pdo->prepare("SELECT id, temps_prep_min, nom FROM carte_restaurant WHERE id = ?");
        $stmt->execute([$item['id']]);
        $plat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($plat) {
            $plat['qty'] = $item['qty'];
            $panier_complet[] = $plat;
        }
    }

    // 3. Calculer temps total
    $temps_total = calculerTempsPanier($panier_complet, $reduction);

    // 4. Vérifier stocks (Optionnel pour le scan global, mais on le fait quand même)
    $stock_check = verifierStocks($panier, $pdo);
    if (!$stock_check['success']) {
        echo json_encode(['success' => false, 'message' => $stock_check['message']]);
        exit;
    }

    if ($action === 'check_availability') {
        // Ancienne logique conservée pour compatibilité checkout
        $type_commande = $input['type_commande'] ?? 'asap';
        $heure_souhaitee = $input['heure'] ?? '';
        $dispo = trouverDisponibilite($temps_total, $type_commande, $heure_souhaitee, $pdo);
        if ($dispo) {
            echo json_encode(['success' => true, 'display_time' => $dispo['display_time'], 'heure_debut' => $dispo['heure_debut'], 'heure_fin' => $dispo['heure_fin']]);
        } else {
            echo json_encode(['success' => false, 'message' => "Complet"]);
        }
        exit;
    }

    // NOUVELLE ACTION: get_full_availability
    // Calcul ASAP
    $asap = trouverDisponibilite($temps_total, 'asap', '', $pdo);
    
    // Calcul de tous les créneaux
    $slots_availability = [];
    $now = new DateTime();
    $periodes = [];
    if (!empty($settings['morning_start'])) $periodes[] = ['s' => $settings['morning_start'], 'e' => $settings['morning_end']];
    if (!empty($settings['evening_start'])) $periodes[] = ['s' => $settings['evening_start'], 'e' => $settings['evening_end']];
    $slotDur = (int)($settings['slot_duration'] ?? 30);

    foreach ($periodes as $p) {
        $iter = new DateTime(date('Y-m-d ') . $p['s']);
        $fin = new DateTime(date('Y-m-d ') . $p['e']);
        while ($iter <= $fin) {
            if ($iter > $now) {
                $timeStr = $iter->format('H:i');
                $is_ok = trouverDisponibilite($temps_total, 'scheduled', $timeStr, $pdo);
                $slots_availability[] = [
                    'time' => $timeStr,
                    'display' => $iter->format('H\hi'),
                    'available' => (bool)$is_ok
                ];
            }
            $iter->modify("+$slotDur minutes");
        }
    }

    echo json_encode([
        'success' => true,
        'asap' => $asap,
        'slots' => $slots_availability
    ]);
}
?>
