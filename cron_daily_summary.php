<?php
/**
 * cron_daily_summary.php
 *
 * Envoie au restaurant un email recapitulatif de toutes les commandes
 * payees du jour. A executer chaque soir a 23h59.
 *
 * --- 2 modes d'execution ---
 *
 * 1) En ligne de commande (cron classique chez O2switch / VPS) :
 *      php /chemin/vers/cron_daily_summary.php
 *    Exemple de crontab :
 *      59 23 * * * php /home/utilisateur/public_html/cron_daily_summary.php
 *
 * 2) Via HTTPS avec token (Infinity Free, ou tout hebergeur sans cron CLI) :
 *      https://www.tonsite.com/cron_daily_summary.php?token=XXX
 *    Le token est defini dans config.php (CRON_DAILY_TOKEN).
 *    Utilise un service externe comme cron-job.org pour declencher l'URL
 *    a 23h59 chaque jour.
 *
 * Optionnel : ajouter &date=YYYY-MM-DD pour generer le recap d'une autre date.
 */

date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer_brevo.php';

// === Securisation pour acces HTTP ===
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!defined('CRON_DAILY_TOKEN') || !hash_equals(CRON_DAILY_TOKEN, $token)) {
        http_response_code(403);
        die('Token invalide');
    }
}

// === Date cible (par defaut : aujourd'hui) ===
$target_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
    $target_date = date('Y-m-d');
}

// === Connexion DB ===
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('DB error: ' . $e->getMessage());
}

// === Recuperation de l'email du resto ===
$resto_email = get_restaurant_email($pdo);
if ($resto_email === '') {
    echo "Pas d'email restaurant configure dans admin. Recap non envoye.\n";
    brevo_log("Cron quotidien : email restaurant vide, recap pour $target_date NON envoye");
    exit(0);
}

// === Recuperation des commandes payees du jour ===
$stmt = $pdo->prepare("
    SELECT id, client_nom, client_tel, client_email, heure_retrait,
           details_panier, statut, date_commande, piste_id
    FROM commandes
    WHERE DATE(date_commande) = ?
      AND statut IN ('en attente', 'terminé')
    ORDER BY date_commande ASC
");
$stmt->execute([$target_date]);
$commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($commandes)) {
    echo "Aucune commande pour le $target_date. Pas d'email envoye.\n";
    brevo_log("Cron quotidien : aucune commande pour $target_date");
    exit(0);
}

// === Aggregation des stats ===
$nb_commandes = count($commandes);
$total_jour   = 0.0;
$nb_terminees = 0;
$nb_attente   = 0;
$articles_agg = []; // nom => qty totale

foreach ($commandes as $cmd) {
    if ($cmd['statut'] === 'terminé') $nb_terminees++;
    else $nb_attente++;

    $panier_raw = json_decode($cmd['details_panier'], true);
    $items = (is_array($panier_raw) && isset($panier_raw['items']))
        ? $panier_raw['items']
        : (is_array($panier_raw) ? $panier_raw : []);

    foreach ($items as $item) {
        $qty  = (int)($item['qty']  ?? 1);
        $nom  = (string)($item['nom']  ?? '');
        $prix = (float)($item['prix'] ?? 0);
        $total_jour += $qty * $prix;

        if ($nom !== '') {
            if (!isset($articles_agg[$nom])) {
                $articles_agg[$nom] = ['qty' => 0, 'total' => 0.0];
            }
            $articles_agg[$nom]['qty']   += $qty;
            $articles_agg[$nom]['total'] += $qty * $prix;
        }
    }
}

// Tri des articles par quantite decroissante
uasort($articles_agg, fn($a, $b) => $b['qty'] <=> $a['qty']);

// === Construction du HTML ===
$date_fr = date('d/m/Y', strtotime($target_date));

$html  = '<div style="font-family:Arial,sans-serif; max-width:700px; margin:0 auto; color:#333;">';
$html .= '<div style="background:#005599; color:white; padding:25px; text-align:center; border-radius:10px 10px 0 0;">';
$html .= '<h1 style="margin:0; font-size:26px;">BlueBeeTN</h1>';
$html .= '<p style="margin:8px 0 0; font-size:18px;">Recapitulatif du ' . $date_fr . '</p>';
$html .= '</div>';
$html .= '<div style="background:white; padding:25px; border:1px solid #e2e8f0; border-top:0;">';

// Cartes stats
$html .= '<div style="display:flex; gap:10px; margin-bottom:25px; flex-wrap:wrap;">';
$html .= '<div style="flex:1; min-width:140px; background:#dbeafe; padding:15px; border-radius:10px; text-align:center;">';
$html .= '<div style="font-size:32px; font-weight:bold; color:#1e40af;">' . $nb_commandes . '</div>';
$html .= '<div style="font-size:13px; color:#1e3a8a;">Commandes</div></div>';
$html .= '<div style="flex:1; min-width:140px; background:#dcfce7; padding:15px; border-radius:10px; text-align:center;">';
$html .= '<div style="font-size:32px; font-weight:bold; color:#166534;">' . $nb_terminees . '</div>';
$html .= '<div style="font-size:13px; color:#14532d;">Terminees</div></div>';
$html .= '<div style="flex:1; min-width:140px; background:#fef9c3; padding:15px; border-radius:10px; text-align:center;">';
$html .= '<div style="font-size:32px; font-weight:bold; color:#92400e;">' . $nb_attente . '</div>';
$html .= '<div style="font-size:13px; color:#78350f;">En attente</div></div>';
$html .= '<div style="flex:1; min-width:140px; background:#fce7f3; padding:15px; border-radius:10px; text-align:center;">';
$html .= '<div style="font-size:32px; font-weight:bold; color:#9d174d;">' . number_format($total_jour, 2, ',', ' ') . ' &euro;</div>';
$html .= '<div style="font-size:13px; color:#831843;">CA du jour</div></div>';
$html .= '</div>';

// Top articles
$html .= '<h3 style="border-bottom:2px solid #005599; padding-bottom:8px;">Articles vendus</h3>';
$html .= '<table style="width:100%; border-collapse:collapse; margin-bottom:25px;">';
$html .= '<thead><tr style="background:#005599; color:white;">';
$html .= '<th style="padding:10px; text-align:left;">Article</th>';
$html .= '<th style="padding:10px; text-align:center;">Qte</th>';
$html .= '<th style="padding:10px; text-align:right;">CA</th>';
$html .= '</tr></thead><tbody>';
foreach ($articles_agg as $nom => $info) {
    $html .= '<tr style="border-bottom:1px solid #e2e8f0;">';
    $html .= '<td style="padding:10px;">' . htmlspecialchars($nom) . '</td>';
    $html .= '<td style="padding:10px; text-align:center; font-weight:bold;">' . $info['qty'] . '</td>';
    $html .= '<td style="padding:10px; text-align:right;">' . number_format($info['total'], 2, ',', ' ') . ' &euro;</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';

// Detail commandes
$html .= '<h3 style="border-bottom:2px solid #005599; padding-bottom:8px;">Detail des commandes</h3>';
$html .= '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
$html .= '<thead><tr style="background:#f1f5f9;">';
$html .= '<th style="padding:8px; text-align:left;">#</th>';
$html .= '<th style="padding:8px; text-align:left;">Heure</th>';
$html .= '<th style="padding:8px; text-align:left;">Client</th>';
$html .= '<th style="padding:8px; text-align:left;">Tel</th>';
$html .= '<th style="padding:8px; text-align:center;">Statut</th>';
$html .= '<th style="padding:8px; text-align:right;">Total</th>';
$html .= '</tr></thead><tbody>';
foreach ($commandes as $cmd) {
    $panier_raw = json_decode($cmd['details_panier'], true);
    $items = (is_array($panier_raw) && isset($panier_raw['items']))
        ? $panier_raw['items']
        : (is_array($panier_raw) ? $panier_raw : []);
    $t = 0;
    foreach ($items as $it) {
        $t += (int)($it['qty'] ?? 1) * (float)($it['prix'] ?? 0);
    }
    $statut_color = $cmd['statut'] === 'terminé' ? '#166534' : '#92400e';
    $statut_bg    = $cmd['statut'] === 'terminé' ? '#dcfce7' : '#fef9c3';
    $html .= '<tr style="border-bottom:1px solid #e2e8f0;">';
    $html .= '<td style="padding:8px;">' . (int)$cmd['id'] . '</td>';
    $html .= '<td style="padding:8px;">' . htmlspecialchars($cmd['heure_retrait']) . '</td>';
    $html .= '<td style="padding:8px;">' . htmlspecialchars($cmd['client_nom']) . '</td>';
    $html .= '<td style="padding:8px;">' . htmlspecialchars($cmd['client_tel']) . '</td>';
    $html .= '<td style="padding:8px; text-align:center;"><span style="background:' . $statut_bg . '; color:' . $statut_color . '; padding:3px 8px; border-radius:6px; font-weight:bold;">' . htmlspecialchars($cmd['statut']) . '</span></td>';
    $html .= '<td style="padding:8px; text-align:right; font-weight:bold;">' . number_format($t, 2, ',', ' ') . ' &euro;</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';

$html .= '<div style="text-align:right; font-size:22px; font-weight:bold; padding-top:15px; margin-top:15px; border-top:3px double #005599;">';
$html .= 'TOTAL DU JOUR : ' . number_format($total_jour, 2, ',', ' ') . ' &euro;';
$html .= '</div>';

$html .= '</div>';
$html .= '<div style="text-align:center; padding:15px; font-size:12px; color:#94a3b8;">';
$html .= 'BlueBeeTN - Rapport automatique du ' . $date_fr;
$html .= '</div></div>';

// === Envoi ===
$res = envoyer_email_brevo(
    $resto_email,
    'Cuisine BlueBeeTN',
    '[RECAP ' . $date_fr . '] ' . $nb_commandes . ' commandes - ' . number_format($total_jour, 2, ',', ' ') . ' EUR',
    $html
);

if ($res['success']) {
    echo "OK : recap du $target_date envoye a $resto_email ($nb_commandes commandes, " . number_format($total_jour, 2) . " EUR)\n";
} else {
    echo "ECHEC : " . ($res['error'] ?? 'unknown') . "\n";
}
