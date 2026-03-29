<?php
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
    'panier' => $panier_verifie
];

$stripe_data = [
    'payment_method_types' => ['card'],
    'line_items' => $line_items,
    'mode' => 'payment',
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