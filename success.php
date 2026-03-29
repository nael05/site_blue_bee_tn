<?php
session_start();
require_once 'config.php'; // On charge la configuration sécurisée

if (!isset($_GET['session_id']) || !isset($_SESSION['commande_en_attente'])) {
    header("Location: index.php");
    exit;
}

$stripe_secret = STRIPE_SECRET_KEY; // On utilise la constante de config.php
$session_id = $_GET['session_id'];

// Vérification du paiement auprès de Stripe
$ch = curl_init("https://api.stripe.com/v1/checkout/sessions/" . $session_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ':');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$session_stripe = json_decode($response, true);

if (!isset($session_stripe['payment_status']) || $session_stripe['payment_status'] !== 'paid') {
    die("Le service est temporairement indisponible.");
}

try {
    // Utilisation des constantes DB de config.php
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}

$c = $_SESSION['commande_en_attente'];
$panier_json = json_encode($c['panier']);

$stmt = $pdo->prepare("INSERT INTO commandes (client_nom, client_tel, heure_retrait, details_panier) VALUES (?, ?, ?, ?)");
$stmt->execute([$c['client'], $c['tel'], $c['heure'], $panier_json]);

unset($_SESSION['commande_en_attente']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Commande Validée - BlueBeeTN</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #fdfbf7; text-align: center; padding-top: 100px; color: #003a6c; }
        .box { background: white; max-width: 500px; margin: 0 auto; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top: 8px solid #c1272d; }
        h1 { color: #c1272d; }
        a { display: inline-block; margin-top: 20px; padding: 12px 25px; background: #0066b2; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <div style="font-size: 5rem;">🐫</div>
        <h1>Mabrouk !</h1>
        <h2>Paiement réussi</h2>
        <p>Ta commande a bien été transmise à nos cuisines. Elle sera prête très bientôt !</p>
        <a href="index.php">Retour au menu</a>
    </div>
</body>
</html>