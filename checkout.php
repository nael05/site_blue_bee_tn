<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax'); // Crucial pour le retour de Stripe

session_start();

require_once 'config.php';
require_once 'functions_ordering.php';

$stripe_secret = STRIPE_SECRET_KEY;

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => "Le service est temporairement indisponible."]));
}


$donnees = json_decode(file_get_contents('php://input'), true);

// --- VÉRIFICATION CAPACITÉ & HORAIRES ---
$settings_raw = $pdo->query("SELECT * FROM commandes_settings")->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['s_key']] = $s['s_value'];
}
$settings['max_per_slot'] = (int)($settings['max_per_slot'] ?? 10);
$settings['is_active'] = (bool)($settings['is_active'] ?? true);
$settings['closed_days'] = json_decode($settings['closed_days'] ?? '[]', true);

// 1. Statut global
if (!$settings['is_active']) {
    http_response_code(403);
    die(json_encode(['error' => "Désolé, les commandes sont actuellement désactivées."]));
}

// 2. Jour de fermeture
$nom_jour_fr = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][date('w')];
if (in_array($nom_jour_fr, $settings['closed_days'])) {
    http_response_code(403);
    die(json_encode(['error' => "Le restaurant est fermé aujourd'hui."]));
}

// 3. Calculer temps et vérifier capacité
$panier_pour_calcul = [];
foreach ($donnees['panier'] as $item) {
    $stmt = $pdo->prepare("SELECT temps_prep_min FROM carte_restaurant WHERE id = ?");
    $stmt->execute([$item['id']]);
    $t = $stmt->fetchColumn();
    $panier_pour_calcul[] = ['id' => $item['id'], 'qty' => $item['qty'], 'temps_prep_min' => $t];
}

$temps_total = calculerTempsPanier($panier_pour_calcul, (int)($settings['reduction_temps_doublon'] ?? 0));
$stock_check = verifierStocks($donnees['panier'], $pdo);

if (!$stock_check['success']) {
    http_response_code(403);
    die(json_encode(['error' => $stock_check['message']]));
}

$heure_choisie = htmlspecialchars($donnees['heure'] ?? '');
$type_cmd = ($heure_choisie === 'asap') ? 'asap' : 'scheduled';
$dispo = trouverDisponibilite($temps_total, $type_cmd, $heure_choisie, $pdo);

if (!$dispo) {
    http_response_code(403);
    die(json_encode(['error' => "Désolés, la cuisine est complète pour cet horaire."]));
}
// ----------------------------------------

if (!$donnees || empty($donnees['panier'])) {
    http_response_code(400);
    die(json_encode(['error' => "Panier vide"]));
}

$line_items = [];
$panier_verifie = [];

foreach ($donnees['panier'] as $item) {
    $qty = (int)$item['qty'];
    if ($qty <= 0 || $qty > 50) continue;

    $stmt = $pdo->prepare("SELECT nom, prix FROM carte_restaurant WHERE id = ?");
    $stmt->execute([$item['id']]);
    $plat = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($plat) {
        $line_items[] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => ['name' => $plat['nom']],
                'unit_amount' => round($plat['prix'] * 100),
            ],
            'quantity' => $qty,
        ];
        $panier_verifie[] = ['id' => $item['id'], 'nom' => $plat['nom'], 'prix' => $plat['prix'], 'qty' => $qty];
    }
}

// --- CRÉATION PRÉ-COMMANDE (RÉSERVATION) ---
$details_panier_data = [
    'items' => $panier_verifie,
    'note' => htmlspecialchars($donnees['note'] ?? '')
];
$panier_json = json_encode($details_panier_data);

$stmt = $pdo->prepare("INSERT INTO commandes (client_nom, client_tel, heure_retrait, details_panier, temps_total_prep, heure_debut_prep, heure_fin_estimee, piste_id, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'attente_paiement')");
$stmt->execute([
    htmlspecialchars($donnees['client']),
    htmlspecialchars($donnees['tel']),
    $dispo['display_time'],
    $panier_json,
    $temps_total,
    $dispo['heure_debut'],
    $dispo['heure_fin'],
    $dispo['piste_id']
]);
$order_id = $pdo->lastInsertId();

$_SESSION['commande_en_attente'] = [
    'order_id' => $order_id,
    'panier' => $panier_verifie // Pour la déduction de stock dans success
];

$stripe_data = [
    'payment_method_types' => ['card'],
    'line_items' => $line_items,
    'mode' => 'payment',
    'client_reference_id' => $order_id,
    // ⚠️ Remplace 'localhost/resto' par ton adresse InfinityFree ici !
    'success_url' => 'http://localhost/resto/success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => 'http://localhost/resto/index.php',
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($stripe_data));
curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ':');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
curl_close($ch);

$session = json_decode($response, true);
if (isset($session['id'])) {
    echo json_encode(['id' => $session['id']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => "Erreur Stripe : " . ($session['error']['message'] ?? 'Inconnue')]);
}
?>