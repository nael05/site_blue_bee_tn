<?php
// --- BLINDAGE SÉCURITÉ SESSIONS & HEADERS ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax'); // Crucial pour le retour de Stripe

session_start();
date_default_timezone_set('Europe/Paris');
require_once 'config.php';
require_once 'mailer_brevo.php';

if (!isset($_GET['session_id']) || !isset($_SESSION['commande_en_attente'])) {
    header("Location: index.php");
    exit;
}

$stripe_secret = STRIPE_SECRET_KEY;
$session_id = $_GET['session_id'];

$ch = curl_init("https://api.stripe.com/v1/checkout/sessions/" . $session_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ':');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_CAINFO, 'C:/wamp64/bin/php/php8.3.28/cacert.pem');
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
curl_close($ch);

$session_stripe = json_decode($response, true);

if (!isset($session_stripe['payment_status']) || $session_stripe['payment_status'] !== 'paid') {
    header("Location: index.php");
    exit;
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

// On passe le statut de 'attente_paiement' à 'en attente' (la condition évite la double exécution)
// $stmt->rowCount() nous dit si c'est BIEN cette execution qui a fait la transition,
// ce qui evite les doubles envois d'emails si le client recharge la page.
$stmt = $pdo->prepare("UPDATE commandes SET statut = 'en attente' WHERE id = ? AND statut = 'attente_paiement'");
$stmt->execute([$order_id]);
$transition_effectuee = $stmt->rowCount() > 0;

// Déduction des stocks (seulement a la premiere transition)
if ($transition_effectuee) {
    foreach ($c['panier'] as $item) {
        $stmt = $pdo->prepare("UPDATE carte_restaurant SET stock_actuel = stock_actuel - ? WHERE id = ? AND type_stock = 'reel'");
        $stmt->execute([$item['qty'], $item['id']]);
    }
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

// ============================================================
// ENVOI DES EMAILS - UNIQUEMENT SI LE PAIEMENT EST CONFIRME
// (on est deja apres le check payment_status === 'paid' ligne 30)
// ET UNIQUEMENT a la 1ere transition pour eviter les doublons
// ============================================================
if ($transition_effectuee) {
    // Recap items au format HTML
    $items_fmt = format_items_html($items);

    // Email expediteur Brevo verifie : nanaililnail99@gmail.com
    $heure_retrait = !empty($order['heure_retrait']) ? $order['heure_retrait'] : '';

    // ------- 1) EMAIL AU CLIENT (recu / confirmation) -------
    if (!empty($order['client_email']) && filter_var($order['client_email'], FILTER_VALIDATE_EMAIL)) {
        $html_client  = '<div style="font-family:Arial,sans-serif; max-width:600px; margin:0 auto; color:#333;">';
        $html_client .= '<div style="background:#005599; color:white; padding:20px; text-align:center; border-radius:10px 10px 0 0;">';
        $html_client .= '<h1 style="margin:0; font-size:24px;">BlueBeeTN</h1>';
        $html_client .= '<p style="margin:5px 0 0; opacity:0.9;">Confirmation de votre commande</p>';
        $html_client .= '</div>';
        $html_client .= '<div style="background:white; padding:25px; border:1px solid #e2e8f0; border-top:0;">';
        $html_client .= '<p>Bonjour <strong>' . htmlspecialchars($order['client_nom']) . '</strong>,</p>';
        $html_client .= '<p>Merci pour votre commande ! Votre paiement a bien ete recu.</p>';
        $html_client .= '<div style="background:#fffbeb; border-left:5px solid #fbbf24; padding:15px; margin:15px 0; border-radius:5px;">';
        $html_client .= '<strong>Commande N&deg; ' . (int)$order['id'] . '</strong><br>';
        $html_client .= 'Heure de retrait : <strong style="color:#d32f2f; font-size:18px;">' . htmlspecialchars($heure_retrait) . '</strong>';
        $html_client .= '</div>';
        $html_client .= $items_fmt['html'];
        $html_client .= '<div style="text-align:right; font-size:20px; font-weight:bold; padding-top:10px; border-top:2px solid #005599;">';
        $html_client .= 'Total paye : ' . number_format($items_fmt['total'], 2, ',', ' ') . ' &euro;';
        $html_client .= '</div>';
        if (!empty($note_client)) {
            $html_client .= '<div style="background:#f1f5f9; padding:12px; margin-top:15px; border-radius:5px;">';
            $html_client .= '<strong>Votre note :</strong><br>' . nl2br(htmlspecialchars($note_client));
            $html_client .= '</div>';
        }
        $html_client .= '<p style="margin-top:20px;">Presentez-vous au comptoir a l\'heure indiquee.</p>';
        $html_client .= '<p style="font-size:13px; color:#64748b;">Une question ? Contactez le restaurant au 09 56 53 55 31.</p>';
        $html_client .= '</div>';
        $html_client .= '<div style="text-align:center; padding:15px; font-size:12px; color:#94a3b8;">';
        $html_client .= 'BlueBeeTN - Cuisine tunisienne authentique';
        $html_client .= '</div></div>';

        envoyer_email_brevo(
            $order['client_email'],
            $order['client_nom'],
            'Votre commande BlueBeeTN N° ' . $order['id'] . ' est confirmee',
            $html_client
        );
    }

    // ------- 2) EMAIL AU RESTAURANT (nouvelle commande) -------
    $resto_email = get_restaurant_email($pdo);
    if ($resto_email !== '') {
        $html_resto  = '<div style="font-family:Arial,sans-serif; max-width:600px; margin:0 auto; color:#333;">';
        $html_resto .= '<div style="background:#d32f2f; color:white; padding:20px; text-align:center; border-radius:10px 10px 0 0;">';
        $html_resto .= '<h1 style="margin:0; font-size:24px;">NOUVELLE COMMANDE</h1>';
        $html_resto .= '<p style="margin:5px 0 0;">Commande N&deg; ' . (int)$order['id'] . '</p>';
        $html_resto .= '</div>';
        $html_resto .= '<div style="background:white; padding:25px; border:1px solid #e2e8f0; border-top:0;">';
        $html_resto .= '<table style="width:100%; margin-bottom:15px;">';
        $html_resto .= '<tr><td style="padding:5px 0;"><strong>Heure de retrait :</strong></td><td style="text-align:right; color:#d32f2f; font-weight:bold; font-size:18px;">' . htmlspecialchars($heure_retrait) . '</td></tr>';
        $html_resto .= '<tr><td style="padding:5px 0;"><strong>Client :</strong></td><td style="text-align:right;">' . htmlspecialchars($order['client_nom']) . '</td></tr>';
        $html_resto .= '<tr><td style="padding:5px 0;"><strong>Telephone :</strong></td><td style="text-align:right;">' . htmlspecialchars($order['client_tel']) . '</td></tr>';
        $html_resto .= '<tr><td style="padding:5px 0;"><strong>Email :</strong></td><td style="text-align:right;">' . htmlspecialchars($order['client_email'] ?? '') . '</td></tr>';
        $html_resto .= '<tr><td style="padding:5px 0;"><strong>Cuisinier :</strong></td><td style="text-align:right;">N&deg; ' . (int)$order['piste_id'] . '</td></tr>';
        $html_resto .= '</table>';
        $html_resto .= $items_fmt['html'];
        $html_resto .= '<div style="text-align:right; font-size:20px; font-weight:bold; padding-top:10px; border-top:2px solid #d32f2f;">';
        $html_resto .= 'Total : ' . number_format($items_fmt['total'], 2, ',', ' ') . ' &euro;';
        $html_resto .= '</div>';
        if (!empty($note_client)) {
            $html_resto .= '<div style="background:#fffbeb; border:2px dashed #d97706; padding:12px; margin-top:15px; border-radius:5px;">';
            $html_resto .= '<strong style="color:#92400e;">NOTE CLIENT :</strong><br>' . nl2br(htmlspecialchars($note_client));
            $html_resto .= '</div>';
        }
        $html_resto .= '</div></div>';

        envoyer_email_brevo(
            $resto_email,
            'Cuisine BlueBeeTN',
            '[NOUVELLE CMD #' . $order['id'] . '] Retrait ' . $heure_retrait . ' - ' . number_format($items_fmt['total'], 2, ',', ' ') . ' EUR',
            $html_resto
        );
    }
}

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
        <a href="tel:0956535531" class="contact-btn">
            <i class="fa-solid fa-phone"></i> Appeler le 09 56 53 55 31
        </a>
        <br>
        <a href="index.php" class="btn-home">Retour à l'accueil</a>
    </div>

</body>
</html>