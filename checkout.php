<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax'); // Crucial pour le retour de Stripe

session_start();

require_once 'config.php';

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

// 3. Capacité du créneau
$heure_choisie = htmlspecialchars($donnees['heure'] ?? '');
if ($heure_choisie && $heure_choisie !== 'Au plus vite') {
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM commandes WHERE DATE(date_commande) = CURDATE() AND heure_retrait = ?");
    $stmtC->execute([$heure_choisie]);
    $deja_commandes = $stmtC->fetchColumn();
    
    if ($deja_commandes >= $settings['max_per_slot']) {
        http_response_code(403);
        die(json_encode(['error' => "Le créneau de $heure_choisie est désormais complet. Merci d'en choisir un autre."]));
    }
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

$_SESSION['commande_en_attente'] = [
    'client' => htmlspecialchars($donnees['client']),
    'tel' => htmlspecialchars($donnees['tel']),
    'heure' => htmlspecialchars($donnees['heure']),
    'note' => htmlspecialchars($donnees['note'] ?? ''), // LA NOTE EST ICI
    'panier' => $panier_verifie
];

$stripe_data = [
    'payment_method_types' => ['card'],
    'line_items' => $line_items,
    'mode' => 'payment',
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