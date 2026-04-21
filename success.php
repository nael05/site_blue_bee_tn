<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax'); // Crucial pour le retour de Stripe

session_start();
require_once 'config.php';

if (!isset($_GET['session_id']) || !isset($_SESSION['commande_en_attente'])) {
    header("Location: index.php");
    exit;
}

$stripe_secret = STRIPE_SECRET_KEY;
$session_id = $_GET['session_id'];

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
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Le service est temporairement indisponible.");
}

$c = $_SESSION['commande_en_attente'];
$order_id = $c['order_id'] ?? $session_stripe['client_reference_id'];

if (!$order_id) {
    die("Erreur de réconciliation de commande.");
}

// On passe le statut de 'attente_paiement' à 'en attente'
$stmt = $pdo->prepare("UPDATE commandes SET statut = 'en attente' WHERE id = ?");
$stmt->execute([$order_id]);

// Déduction des stocks
foreach ($c['panier'] as $item) {
    $stmt = $pdo->prepare("UPDATE carte_restaurant SET stock_actuel = stock_actuel - ? WHERE id = ? AND type_stock = 'reel'");
    $stmt->execute([$item['qty'], $item['id']]);
}

// On récupère les détails complets de la commande pour l'affichage
$stmt = $pdo->prepare("SELECT * FROM commandes WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Commande introuvable.");
}

$details = json_decode($order['details_panier'], true);
$items = $details['items'] ?? [];
$note_client = $details['note'] ?? '';

unset($_SESSION['commande_en_attente']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre Reçu - BlueBeeTN</title>
    <link href="https://fonts.googleapis.com/css2?family=Aref+Ruqaa:wght@400;700&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --sidi-blue: #005599;
            --sidi-dark: #003a6c;
            --medina-gold: #d4af37;
            --harissa-red: #d32f2f;
            --chaux-white: #fdfbf7;
        }

        body { 
            font-family: 'Tajawal', sans-serif; 
            background-color: var(--chaux-white); 
            margin: 0; 
            padding: 20px; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            color: var(--sidi-dark);
        }

        .receipt-container {
            background: white;
            width: 100%;
            max-width: 500px;
            padding: 0;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
            margin-bottom: 30px;
        }

        .receipt-header {
            background: var(--sidi-blue);
            color: white;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 5px solid var(--medina-gold);
        }

        .khamsa-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: white;
        }

        .receipt-header h1 {
            font-family: 'Aref Ruqaa', serif;
            margin: 0;
            font-size: 2rem;
        }

        .receipt-body {
            padding: 30px 25px;
        }

        .status-badge {
            background: #dcfce7;
            color: #166534;
            padding: 8px 15px;
            border-radius: 50px;
            font-weight: 800;
            display: inline-block;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .order-meta {
            margin-bottom: 30px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .meta-label {
            color: #64748b;
            font-weight: 500;
        }

        .meta-value {
            font-weight: 800;
            color: var(--sidi-dark);
        }

        .instruction-box {
            background: #fffbeb;
            border: 2px solid #fde68a;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }

        .instruction-box i {
            color: #b45309;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .instruction-box p {
            margin: 0;
            color: #92400e;
            font-weight: 700;
            line-height: 1.5;
        }

        .item-list {
            border-top: 2px dashed #e2e8f0;
            padding-top: 20px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .item-info {
            display: flex;
            flex-direction: column;
        }

        .item-name {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .item-price {
            color: #64748b;
            font-size: 0.9rem;
        }

        .item-total {
            font-weight: 800;
            font-size: 1.1rem;
            color: var(--sidi-blue);
        }

        .receipt-total {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px solid var(--sidi-blue);
            display: flex;
            justify-content: space-between;
            font-size: 1.5rem;
            font-weight: 800;
        }

        .footer-action {
            text-align: center;
            padding-top: 20px;
        }

        .contact-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--sidi-blue);
            color: white;
            padding: 15px 30px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0, 85, 153, 0.2);
            transition: 0.3s;
        }

        .contact-btn:hover {
            background: var(--sidi-dark);
            transform: translateY(-2px);
        }

        .btn-home {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            .receipt-header h1 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

    <div class="receipt-container">
        <div class="receipt-header">
            <div class="khamsa-icon"><i class="fa-solid fa-hamsa"></i></div>
            <h1>Mabrouk !</h1>
            <p style="margin:0; opacity: 0.9;">Votre commande est validée</p>
        </div>

        <div class="receipt-body">
            <div style="text-align: center;">
                <div class="status-badge"><i class="fa-solid fa-check"></i> Paiement Terminé</div>
            </div>

            <div class="order-meta">
                <div class="meta-row">
                    <span class="meta-label">Commande :</span>
                    <span class="meta-value">#<?= $order['id'] ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Nom du client :</span>
                    <span class="meta-value"><?= htmlspecialchars($order['client_nom']) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Heure de retrait :</span>
                    <span class="meta-value" style="color: var(--harissa-red); font-size: 1.3rem;"><?= date('H:i', strtotime($order['heure_retrait'])) ?></span>
                </div>
            </div>

            <div class="instruction-box">
                <i class="fa-solid fa-person-running"></i>
                <p>Soyez présent impérativement à <?= date('H:i', strtotime($order['heure_retrait'])) ?>.</p>
                <p style="font-size: 0.9rem; margin-top: 5px;">Présentez-vous au comptoir avec le nom : <br><strong style="font-size: 1.1rem; color: var(--sidi-dark);"><?= htmlspecialchars($order['client_nom']) ?></strong></p>
            </div>

            <div class="item-list">
                <?php 
                $total = 0;
                foreach ($items as $item): 
                    $t = ($item['prix'] ?? 0) * $item['qty'];
                    $total += $t;
                ?>
                <div class="item-row">
                    <div class="item-info">
                        <span class="item-name"><?= htmlspecialchars($item['nom']) ?> (x<?= $item['qty'] ?>)</span>
                        <span class="item-price"><?= number_format($item['prix'], 2) ?> € / unité</span>
                    </div>
                    <span class="item-total"><?= number_format($t, 2) ?> €</span>
                </div>
                <?php endforeach; ?>

                <div class="receipt-total">
                    <span>Total Payé</span>
                    <span><?= number_format($total, 2) ?> €</span>
                </div>
            </div>
        </div>
    </div>

    <div class="footer-action">
        <p style="font-weight: 700; margin-bottom: 10px;">Besoin d'aide ou d'un changement ?</p>
        <a href="tel:0601394628" class="contact-btn">
            <i class="fa-solid fa-phone"></i> Appeler le 06 01 39 46 28
        </a>
        <br>
        <a href="index.php" class="btn-home">Retour à l'accueil</a>
    </div>

</body>
</html>