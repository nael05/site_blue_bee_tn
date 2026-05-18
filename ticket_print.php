<?php
/**
 * ticket_print.php
 *
 * Page de rendu d'un ticket cuisine, optimisee pour impression sur
 * imprimante thermique 80mm via le driver Windows + Chrome --kiosk-printing.
 *
 * Parametres :
 *   id   = ID de la commande (obligatoire)
 *   auto = 1 pour declencher window.print() automatiquement au chargement
 *          et marquer la commande comme imprimee
 *        = 0 (defaut) pour reimpression manuelle, sans marquage
 *
 * Securite : necessite que la session cuisine soit ouverte (meme verif
 * que cuisine.php pour acceder a la liste des commandes).
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

header('X-Frame-Options: SAMEORIGIN'); // Autorise l'iframe depuis cuisine.php
header('X-Content-Type-Options: nosniff');

session_start();
require_once 'config.php';

if (!isset($_SESSION['cuisine_logged_in'])) {
    http_response_code(403);
    die('Acces refuse - veuillez vous connecter au service cuisine.');
}

$id     = (int)($_GET['id'] ?? 0);
$auto   = isset($_GET['auto']) && $_GET['auto'] === '1';
$copies = max(1, min(3, (int)($_GET['copies'] ?? 1))); // 1 ou 2 (max 3 par securite)

if (!$id) {
    http_response_code(400);
    die('ID de commande manquant');
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die('Erreur DB');
}

$stmt = $pdo->prepare("SELECT * FROM commandes WHERE id = ?");
$stmt->execute([$id]);
$cmd = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cmd) {
    http_response_code(404);
    die('Commande introuvable');
}

$panier_raw = json_decode($cmd['details_panier'], true);
$items = (is_array($panier_raw) && isset($panier_raw['items'])) ? $panier_raw['items'] : (is_array($panier_raw) ? $panier_raw : []);
$note  = (is_array($panier_raw) && isset($panier_raw['note']))  ? $panier_raw['note']  : '';

$total = 0.0;
foreach ($items as $item) {
    $total += (int)($item['qty'] ?? 1) * (float)($item['prix'] ?? 0);
}

$debut   = $cmd['heure_debut_prep'] ? date('H:i', strtotime($cmd['heure_debut_prep'])) : '--:--';
$fin     = $cmd['heure_fin_estimee'] ? date('H:i', strtotime($cmd['heure_fin_estimee'])) : '--:--';
$retrait = !empty($cmd['heure_retrait']) ? $cmd['heure_retrait'] : null;

$csrf = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Ticket cuisine #<?= $id ?></title>
<style>
    /* Format papier 80mm (largeur utile ~72mm sur ODP 333) */
    @page {
        size: 80mm auto;
        margin: 0;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #e5e7eb;
    }

    .ticket {
        width: 72mm;
        margin: 0 auto;
        padding: 4mm 3mm;
        font-family: 'Courier New', Consolas, monospace;
        font-size: 11pt;
        line-height: 1.35;
        color: #000;
        background: white;
    }

    .center  { text-align: center; }
    .right   { text-align: right; }
    .bold    { font-weight: bold; }
    .big     { font-size: 14pt; font-weight: bold; }
    .huge    { font-size: 22pt; font-weight: bold; letter-spacing: 1px; }
    .small   { font-size: 9pt; }

    .sep         { border-top: 1px dashed #000; margin: 2mm 0; }
    .sep-double  { border-top: 2px solid #000;  margin: 2mm 0; }

    .row {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: 3mm;
    }
    .row > span:last-child { white-space: nowrap; }

    .item { margin: 1.5mm 0; }
    .item-name { font-weight: bold; word-break: break-word; }
    .item-detail { font-size: 9pt; color: #222; padding-left: 5mm; }

    .total {
        font-size: 14pt; font-weight: bold;
        margin-top: 3mm; padding-top: 2mm;
        border-top: 2px solid #000;
    }

    .note-box {
        margin-top: 3mm;
        padding: 2mm;
        border: 2px dashed #000;
        font-size: 10pt;
    }

    .footer { margin-top: 6mm; font-size: 8pt; text-align: center; color: #444; }

    /* Boutons (affiches uniquement a l'ecran, jamais imprimes) */
    .controls {
        max-width: 72mm;
        margin: 5mm auto;
        text-align: center;
    }
    .controls button {
        padding: 10px 20px;
        font-size: 14px;
        margin: 5px;
        background: #005599;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
    }
    .controls button:hover { background: #003a6c; }
    .controls .btn-close { background: #6b7280; }
    .controls .btn-close:hover { background: #374151; }

    /* Force un saut de page entre 2 exemplaires pour que le massicot coupe */
    .ticket + .ticket { page-break-before: always; }

    .copy-label {
        text-align: center;
        background: #000; color: white;
        padding: 3mm 0;
        margin: 0 -3mm 3mm;
        font-weight: bold; font-size: 12pt;
        letter-spacing: 3px;
    }

    /* === REGLES POUR L'IMPRESSION === */
    @media print {
        body { background: white; }
        .ticket { width: 72mm; margin: 0; padding: 2mm; box-shadow: none; }
        .controls { display: none !important; }
    }
</style>
</head>
<body>

<?php
// Libelles des exemplaires (1 par defaut, 2 si double impression)
$labels = $copies === 2 ? ['CUISINE', 'SAC CLIENT'] : [null];
for ($k = 0; $k < $copies; $k++): ?>
<div class="ticket">

    <?php if ($labels[$k] !== null): ?>
    <div class="copy-label"><?= $labels[$k] ?></div>
    <?php endif; ?>

    <div class="center big">BlueBeeTN</div>
    <div class="center small">Service Cuisine</div>
    <div class="sep-double"></div>

    <div class="center">
        <div class="small">COMMANDE</div>
        <div class="huge">N&deg; <?= $id ?></div>
    </div>
    <div class="sep"></div>

    <div class="bold">CUISINIER <?= htmlspecialchars($cmd['piste_id']) ?></div>
    <div class="row">
        <span>Lancer &agrave; :</span>
        <span class="big"><?= $debut ?></span>
    </div>
    <div class="row">
        <span>Pr&ecirc;t pour :</span>
        <span class="bold"><?= $fin ?></span>
    </div>
    <?php if ($retrait): ?>
    <div class="row">
        <span>Retrait :</span>
        <span><?= htmlspecialchars($retrait) ?></span>
    </div>
    <?php endif; ?>
    <div class="sep"></div>

    <div class="bold">Client : <?= htmlspecialchars($cmd['client_nom']) ?></div>
    <div>T&eacute;l : <?= htmlspecialchars($cmd['client_tel']) ?></div>
    <div class="sep"></div>

    <div class="bold center" style="margin-bottom: 1mm;">--- ARTICLES ---</div>

    <?php foreach ($items as $item):
        $qty  = (int)($item['qty'] ?? 1);
        $nom  = (string)($item['nom'] ?? '');
        $prix = (float)($item['prix'] ?? 0);
        $sub  = $qty * $prix;
    ?>
    <div class="item">
        <div class="row">
            <span class="item-name"><?= $qty ?>&times; <?= htmlspecialchars($nom) ?></span>
            <span class="bold"><?= number_format($sub, 2, ',', ' ') ?>&nbsp;&euro;</span>
        </div>
        <?php if ($qty > 1): ?>
        <div class="item-detail">(<?= number_format($prix, 2, ',', ' ') ?> &euro;/unit&eacute;)</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="row total">
        <span>TOTAL</span>
        <span><?= number_format($total, 2, ',', ' ') ?>&nbsp;&euro;</span>
    </div>

    <?php if (!empty(trim((string)$note))): ?>
    <div class="note-box">
        <div class="bold">NOTE CLIENT :</div>
        <div><?= nl2br(htmlspecialchars($note)) ?></div>
    </div>
    <?php endif; ?>

    <div class="footer">
        Imprim&eacute; le <?= date('d/m/Y H:i:s') ?>
    </div>

</div>
<?php endfor; ?>

<div class="controls">
    <button onclick="window.print()">Imprimer</button>
    <button class="btn-close" onclick="window.close()">Fermer</button>
</div>

<script>
const COMMANDE_ID = <?= (int)$id ?>;
const AUTO_MODE   = <?= $auto ? 'true' : 'false' ?>;
const CSRF_TOKEN  = <?= json_encode($csrf) ?>;

if (AUTO_MODE) {
    // Petit delai pour s'assurer que le rendu est complet avant d'imprimer
    window.addEventListener('load', () => {
        setTimeout(() => {
            window.print();
        }, 300);
    });

    // Marquer la commande comme imprimee + signaler au parent
    window.addEventListener('afterprint', () => {
        const body = new URLSearchParams();
        body.set('action', 'marquer_imprime');
        body.set('id', COMMANDE_ID);
        body.set('csrf_token', CSRF_TOKEN);

        fetch('cuisine.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(() => { /* on retentera au prochain refresh */ })
          .finally(() => {
            // Notifier le parent (cuisine.php) qui pourra retirer l'iframe
            if (window.parent && window.parent !== window) {
                try {
                    window.parent.postMessage(
                        { type: 'ticket_printed', id: COMMANDE_ID },
                        window.location.origin
                    );
                } catch (e) {}
            }
        });
    });
}
</script>
</body>
</html>
